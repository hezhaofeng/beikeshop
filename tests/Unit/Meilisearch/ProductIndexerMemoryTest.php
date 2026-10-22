<?php

namespace Tests\Unit\Meilisearch;

use Beike\Models\Product;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\TestCase;
use Plugin\CyberCloak\Services\StoreContext;
use Plugin\Meilisearch\Services\CatalogContext;
use Plugin\Meilisearch\Services\MeilisearchClient;
use Plugin\Meilisearch\Services\ProductDocument;
use Plugin\Meilisearch\Services\ProductIndexer;
use Plugin\Meilisearch\Services\Settings;
use Psr\Log\NullLogger;
use ReflectionMethod;
use TorMorten\Eventy\Events;

/**
 * 用内存数据库验证字段裁剪后的实际关联加载，避免测试依赖本地商城和生产数据库。
 */
class ProductIndexerMemoryTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    private mixed $previousResolver;

    private mixed $previousBatchSize;

    private array $connections = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('关联加载回归测试需要 pdo_sqlite。');
        }

        $this->previousContainer         = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver          = Model::getConnectionResolver();
        $this->previousBatchSize         = Env::getRepository()->get('MEILISEARCH_BATCH_SIZE');
        Env::getRepository()->clear('MEILISEARCH_BATCH_SIZE');

        $container = new Container();
        $container->instance('config', new Repository([
            'database'    => ['default' => 'mysql'],
            'cyber_cloak' => ['connections' => ['real' => 'mysql', 'public' => 'catalog_public']],
        ]));
        $container->instance('eventy', new Events());
        $container->instance('log', new NullLogger());
        $container->instance(StoreContext::class, new StoreContext());
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        foreach (['mysql', 'catalog_public'] as $name) {
            $this->connections[$name] = new SQLiteConnection(new PDO('sqlite::memory:'), '', '', ['name' => $name]);
            $this->seed($this->connections[$name]);
        }
        $resolver = new ConnectionResolver($this->connections);
        $resolver->setDefaultConnection('mysql');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if (isset($this->previousContainer)) {
            if ($this->previousBatchSize === null) {
                Env::getRepository()->clear('MEILISEARCH_BATCH_SIZE');
            } else {
                Env::getRepository()->set('MEILISEARCH_BATCH_SIZE', $this->previousBatchSize);
            }
            if ($this->previousResolver) {
                Model::setConnectionResolver($this->previousResolver);
            } else {
                Model::unsetConnectionResolver();
            }
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousFacadeApplication);
            Container::setInstance($this->previousContainer);
        }
        parent::tearDown();
    }

    public function test_trimmed_query_preserves_the_complete_search_document_without_loading_large_fields(): void
    {
        $full = Product::with([
            'descriptions', 'skus', 'productCategories', 'categories.descriptions',
            'categories.paths.pathCategory.descriptions',
        ])->withCount('views')->findOrFail(1);
        $light     = $this->query('real', 'en')->findOrFail(1);
        $documents = new ProductDocument();

        $connection = $this->connections['mysql'];
        $connection->enableQueryLog();
        $this->assertSame($documents->make($full, 'real', 'en'), $documents->make($light, 'real', 'en'));
        $this->assertSame([], $connection->getQueryLog(), '生成文档不应再触发关联查询。');
        $this->assertSame(['Shirts', 'Clothing'], $documents->make($light, 'real', 'en')['category_names']);
        $this->assertSame(2, $documents->make($light, 'real', 'en')['views']);
        $this->assertArrayNotHasKey('images', $light->getAttributes());
        $this->assertArrayNotHasKey('variables', $light->getAttributes());
        $this->assertCount(1, $light->descriptions);
        $this->assertArrayNotHasKey('content', $light->descriptions->first()->getAttributes());
        $this->assertArrayNotHasKey('images', $light->skus->first()->getAttributes());
        $this->assertArrayNotHasKey('variants', $light->skus->first()->getAttributes());
        $this->assertCount(1, $light->categories->first()->descriptions);
        $this->assertArrayNotHasKey('content', $light->categories->first()->descriptions->first()->getAttributes());
    }

    public function test_public_query_still_filters_unpublished_products_and_skus(): void
    {
        CatalogContext::runAs('public', function (): void {
            $products = $this->query('public', 'zh_cn')->get();
            $this->assertSame([1], $products->modelKeys());
            $product = $products->first();
            $this->assertSame('catalog_public', $product->getConnectionName());
            $this->assertSame([12], $product->skus->modelKeys());
            $document = (new ProductDocument())->make($product, 'public', 'zh_cn');
            $this->assertSame(['PUBLIC-12'], $document['sku']);
            $this->assertSame(['PUBLIC12', 'MODEL12'], $document['sku_exact']);
            $this->assertSame(29.9, $document['price']);
            $this->assertSame(['衬衫', '服装'], $document['category_names']);
            $this->assertSame(0, $document['views']);
            $this->assertTrue($document['visible']);
        });
        $this->assertFalse(app(StoreContext::class)->isActive());
    }

    public function test_single_product_sync_keeps_both_index_languages(): void
    {
        $client  = $this->createMock(MeilisearchClient::class);
        $written = [];
        $client->expects($this->exactly(2))->method('addDocuments')->willReturnCallback(
            function ($index, $documents) use (&$written): int {
                $written[$index] = $documents[0];

                return count($written);
            }
        );
        $client->expects($this->exactly(2))->method('waitForTask')->willReturn(['status' => 'succeeded']);
        (new ProductIndexer($client))->syncProduct(1, 'real');

        $this->assertSame('Shirt', $written['old_en']['name']);
        $this->assertSame('衬衫商品', $written['old_zh_cn']['name']);
        $this->assertSame(['Shirts', 'Clothing'], $written['old_en']['category_names']);
        $this->assertSame(['衬衫', '服装'], $written['old_zh_cn']['category_names']);
    }

    public function test_batch_size_has_a_small_default_and_caps_explicit_cli_values(): void
    {
        $this->assertSame(100, Settings::batchSize());
        $this->assertSame(1000, Settings::batchSize(50000));
        $this->assertSame(1, Settings::batchSize(0));
        config(['bk.plugin.meilisearch.batch_size' => 300]);
        $this->assertSame(300, Settings::batchSize());
        Env::getRepository()->set('MEILISEARCH_BATCH_SIZE', '50');
        $this->assertSame(50, Settings::batchSize());
    }

    private function query(string $catalog, ?string $locale)
    {
        return (new ReflectionMethod(ProductIndexer::class, 'productQuery'))->invoke(new ProductIndexer(), $catalog, $locale);
    }

    private function seed(SQLiteConnection $db): void
    {
        $schema = [
            'products'                       => 'id integer primary key, brand_id integer, active integer, position integer, sales integer, created_at text, updated_at text, deleted_at text, images text, variables text',
            'product_descriptions'           => 'id integer primary key, product_id integer, locale text, name text, content text',
            'product_skus'                   => 'id integer primary key, product_id integer, sku text, model text, price real, is_default integer, position integer, images text, variants text',
            'product_categories'             => 'id integer primary key, product_id integer, category_id integer, created_at text, updated_at text',
            'categories'                     => 'id integer primary key, image text',
            'category_descriptions'          => 'id integer primary key, category_id integer, locale text, name text, content text',
            'category_paths'                 => 'id integer primary key, category_id integer, path_id integer, level integer',
            'product_views'                  => 'id integer primary key, product_id integer',
            'languages'                      => 'id integer primary key, code text, status integer',
            'catalog_published_sku_mappings' => 'public_sku_id integer, active integer',
            'meilisearch_index_states'       => 'id integer primary key, catalog text, locale text, active_index text, last_updated_at text, last_product_id integer, created_at text, updated_at text',
        ];
        foreach ($schema as $table => $columns) {
            $db->statement("CREATE TABLE {$table} ({$columns})");
        }
        $largeText = str_repeat('详情正文及图片数据', 10000);
        foreach ([1, 2] as $id) {
            $db->table('products')->insert([
                'id'         => $id, 'brand_id' => 3, 'active' => 1, 'position' => 4, 'sales' => 9,
                'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
                'images'     => json_encode([$largeText]), 'variables' => json_encode([$largeText]),
            ]);
        }
        foreach (['en' => 'Shirt', 'zh_cn' => '衬衫商品', 'fr' => 'Chemise'] as $locale => $name) {
            $db->table('languages')->insert(['code' => $locale, 'status' => 1]);
            $db->table('product_descriptions')->insert(['product_id' => 1, 'locale' => $locale, 'name' => $name, 'content' => $largeText]);
            $db->table('category_descriptions')->insert([
                ['category_id' => 1, 'locale' => $locale, 'name' => $locale === 'zh_cn' ? '服装' : 'Clothing', 'content' => $largeText],
                ['category_id' => 2, 'locale' => $locale, 'name' => $locale === 'zh_cn' ? '衬衫' : 'Shirts', 'content' => $largeText],
            ]);
        }
        $db->table('categories')->insert([['id' => 1, 'image' => $largeText], ['id' => 2, 'image' => $largeText]]);
        $db->table('product_categories')->insert(['product_id' => 1, 'category_id' => 2]);
        $db->table('category_paths')->insert([
            ['category_id' => 2, 'path_id' => 2, 'level' => 1],
            ['category_id' => 2, 'path_id' => 1, 'level' => 0],
        ]);
        foreach ([11 => 1, 12 => 1, 21 => 2] as $id => $productId) {
            $db->table('product_skus')->insert([
                'id'       => $id, 'product_id' => $productId, 'sku' => $id === 12 ? 'PUBLIC-12' : "PRIVATE-{$id}",
                'model'    => "MODEL-{$id}", 'price' => $id                 === 12 ? 29.9 : 99, 'is_default' => $id === 11 ? 1 : 0,
                'position' => $id, 'images' => json_encode([$largeText]), 'variants' => json_encode([$largeText]),
            ]);
        }
        $db->table('catalog_published_sku_mappings')->insert(['public_sku_id' => 12, 'active' => 1]);
        $db->table('product_views')->insert([['product_id' => 1], ['product_id' => 1]]);
        foreach (['en', 'zh_cn'] as $locale) {
            $db->table('meilisearch_index_states')->insert(['catalog' => 'real', 'locale' => $locale, 'active_index' => "old_{$locale}"]);
        }
    }
}
