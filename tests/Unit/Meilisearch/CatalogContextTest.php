<?php

namespace Tests\Unit\Meilisearch;

use Beike\Models\Category;
use Beike\Models\CategoryDescription;
use Beike\Models\CategoryPath;
use Beike\Models\Product;
use Beike\Models\ProductCategory;
use Beike\Models\ProductDescription;
use Beike\Models\ProductSku;
use Plugin\CyberCloak\Services\StoreContext;
use Plugin\Meilisearch\Services\CatalogContext;
use Plugin\Meilisearch\Services\ProductDocument;
use Plugin\Meilisearch\Services\Settings;
use RuntimeException;
use Tests\TestCase;

/**
 * 覆盖 Meilisearch 与 CyberCloak 双商品库之间的隔离约定。
 *
 * 真实库和展示库的商品 ID 空间互不相干，一旦索引或检索选错商品库，前台会拿到完全
 * 不相干的商品，这里锁定相关不变量。
 */
class CatalogContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cyber_cloak.connections', [
            'real'   => 'mysql',
            'public' => 'catalog_public',
        ]);
        config()->set('database.connections.catalog_public', config('database.connections.mysql'));

        app()->instance(StoreContext::class, new StoreContext);
    }

    public function test_catalogs_include_public_when_connection_configured(): void
    {
        $this->assertSame([CatalogContext::REAL, CatalogContext::PUBLIC], CatalogContext::catalogs());
    }

    public function test_catalogs_fall_back_to_real_when_public_connection_missing(): void
    {
        config()->set('database.connections.catalog_public', null);

        $this->assertSame([CatalogContext::REAL], CatalogContext::catalogs());
    }

    public function test_current_catalog_follows_store_context(): void
    {
        $context = app(StoreContext::class);

        // 上下文未激活时（后台、队列、命令行）必须落在真实库，与 UsesCatalogConnection 一致。
        $this->assertSame(CatalogContext::REAL, CatalogContext::current());

        $context->activate(['mode' => StoreContext::PUBLIC]);
        $this->assertSame(CatalogContext::PUBLIC, CatalogContext::current());

        $context->activate(['mode' => StoreContext::REAL]);
        $this->assertSame(CatalogContext::REAL, CatalogContext::current());
    }

    public function test_index_names_are_isolated_per_catalog(): void
    {
        $real   = Settings::baseIndexName(CatalogContext::REAL, 'en');
        $public = Settings::baseIndexName(CatalogContext::PUBLIC, 'en');

        $this->assertNotSame($real, $public);
        $this->assertStringContainsString('_real_en', $real);
        $this->assertStringContainsString('_public_en', $public);
    }

    public function test_invalid_catalog_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        Settings::baseIndexName('staging', 'en');
    }

    public function test_run_as_public_activates_and_restores_context(): void
    {
        $context = app(StoreContext::class);

        $observed = CatalogContext::runAs(CatalogContext::PUBLIC, fn (): string => $context->connectionName());

        $this->assertSame('catalog_public', $observed);
        // 回调结束后必须复位，否则常驻进程会把展示库上下文带到下一个任务。
        $this->assertFalse($context->isActive());
    }

    public function test_run_as_rejects_nested_activation(): void
    {
        app(StoreContext::class)->activate(['mode' => StoreContext::PUBLIC]);

        $this->expectException(RuntimeException::class);

        CatalogContext::runAs(CatalogContext::PUBLIC, fn () => null);
    }

    public function test_public_catalog_is_constrained_by_published_skus(): void
    {
        $this->assertTrue(CatalogContext::constrained(CatalogContext::PUBLIC));
        $this->assertFalse(CatalogContext::constrained(CatalogContext::REAL));
    }

    public function test_sku_normalization_ignores_separators_and_case(): void
    {
        $documents = new ProductDocument();

        $this->assertSame('ABC123XL', $documents->normalize('abc-123_XL'));
        $this->assertSame('ABC123', $documents->normalize(' ABC/123 '));
        $this->assertSame('', $documents->normalize('---'));
    }

    public function test_document_indexes_category_names_for_current_locale(): void
    {
        $product  = $this->product();
        $category = new Category();
        $category->setRelation('descriptions', collect([
            (new CategoryDescription())->forceFill(['locale' => 'en', 'name' => 'Phone Accessories']),
            (new CategoryDescription())->forceFill(['locale' => 'zh_cn', 'name' => '手机配件']),
        ]));
        $product->setRelation('categories', collect([$category]));

        $document = (new ProductDocument())->make($product, CatalogContext::REAL, 'zh_cn');

        $this->assertSame(['手机配件'], $document['category_names']);
        $this->assertSame(['AB123'], $document['sku_exact']);
        $this->assertSame(0, $document['views']);
        $this->assertSame(0, $document['created_at']);
    }

    public function test_document_indexes_parent_category_names_from_category_paths(): void
    {
        $product = $this->product();
        $leaf   = new Category();
        $leaf->setRelation('descriptions', collect([
            (new CategoryDescription())->forceFill(['locale' => 'en', 'name' => 'Jerseys']),
        ]));

        $parent = new Category();
        $parent->setRelation('descriptions', collect([
            (new CategoryDescription())->forceFill(['locale' => 'en', 'name' => 'Arizona Cardinals']),
        ]));

        $path = new CategoryPath();
        $path->setRelation('pathCategory', $parent);
        $leaf->setRelation('paths', collect([$path]));
        $product->setRelation('categories', collect([$leaf]));

        $document = (new ProductDocument())->make($product, CatalogContext::REAL, 'en');

        $this->assertSame(['Jerseys', 'Arizona Cardinals'], $document['category_names']);
    }

    public function test_document_skips_category_names_when_relation_not_loaded(): void
    {
        // 未预加载 categories 时必须返回空数组，否则全量索引会退化成逐商品回查。
        $document = (new ProductDocument())->make($this->product(), CatalogContext::REAL, 'zh_cn');

        $this->assertSame([], $document['category_names']);
    }

    /**
     * 构造一个只带必要关联的商品，避免测试触碰数据库。
     */
    private function product(): Product
    {
        $product = (new Product())->forceFill([
            'id'         => 1,
            'active'     => 1,
            'brand_id'   => 0,
            'position'   => 0,
            'sales'      => 0,
            'deleted_at' => null,
        ]);
        $product->setRelation('descriptions', collect([
            (new ProductDescription())->forceFill(['locale' => 'zh_cn', 'name' => '测试商品']),
        ]));
        $product->setRelation('skus', collect([
            (new ProductSku())->forceFill(['sku' => 'AB-123', 'model' => '', 'price' => 10, 'is_default' => true]),
        ]));
        $product->setRelation('productCategories', collect([
            (new ProductCategory())->forceFill(['category_id' => 5]),
        ]));

        return $product;
    }
}
