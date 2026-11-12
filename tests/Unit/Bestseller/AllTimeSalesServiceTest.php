<?php

namespace Tests\Unit\Bestseller;

use Beike\Libraries\Registry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Mockery;
use PDO;
use Plugin\Bestseller\Services\AllTimeSalesService;
use Plugin\Bestseller\Services\BestsellerSelectionService;
use Plugin\CyberCloak\Services\CatalogContentMappingService;
use Plugin\CyberCloak\Services\StoreContext;

class AllTimeSalesServiceTest extends BestsellerTestCase
{
    private SQLiteConnection $db;

    private mixed $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousResolver = Model::getConnectionResolver();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('全部时间销量的 SQL 回归测试需要 pdo_sqlite。');
        }
        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'), '', '', ['name' => 'mysql']);
        $resolver = new ConnectionResolver(['mysql' => $this->db]);
        $resolver->setDefaultConnection('mysql');
        $this->app->instance('db', $resolver);
        Model::setConnectionResolver($resolver);

        foreach ([
            'products'       => 'id integer primary key, active integer, deleted_at text, sales integer default 0',
            'product_skus'   => 'id integer primary key, product_id integer, is_default integer',
            'orders'         => 'id integer primary key, status text, created_at text, deleted_at text',
            'order_products' => 'id integer primary key, order_id integer, product_id integer, product_sku text, quantity integer',
            'settings'       => 'id integer primary key, type text, space text, name text, value text, json integer, created_at text, updated_at text',
        ] as $table => $columns) {
            $this->db->statement('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Registry::getSingleton()->destroy();
        parent::tearDown();
    }

    public function test_ranking_sums_all_dates_and_skus_by_quantity_and_excludes_invalid_orders_and_products(): void
    {
        foreach (range(1, 8) as $id) {
            $this->product($id);
        }
        $this->db->table('product_skus')->insert(['product_id' => 1, 'is_default' => 1]);
        $this->db->table('products')->where('id', 2)->update(['sales' => 999999]);
        $this->db->table('products')->where('id', 5)->update(['active' => 0]);
        $this->db->table('products')->where('id', 6)->update(['deleted_at' => '2026-01-01']);
        $this->db->table('product_skus')->where('product_id', 7)->delete();
        // 一个旧订单的两个 SKU 合计 10 件，必须高于近期的多笔少量订单。
        $this->sale(1, 3, 'completed', '2020-01-01', 'SKU-A');
        $this->sale(1, 7, 'completed', '2020-01-01', 'SKU-B', 1);
        $this->sale(2, 2, 'paid', '2026-01-01');
        $this->sale(2, 3, 'shipped', '2026-01-01');
        $this->sale(3, 5, 'completed', '2022-01-01');
        $this->sale(4, 5, 'paid', '2026-01-01');
        foreach (['created', 'unpaid', 'cancelled', 'refunding'] as $status) {
            $this->sale(2, 1000, $status);
        }
        $deletedOrderId = $this->sale(2, 1000, 'paid');
        $this->db->table('orders')->where('id', $deletedOrderId)->update(['deleted_at' => '2026-01-02']);
        foreach ([5, 6, 7] as $id) {
            $this->sale($id, 1000, 'paid');
        }
        $this->sale(8, 0, 'paid');

        $service = new AllTimeSalesService;
        $this->assertSame([1, 4, 2, 3], $service->rankedProductIds(20));
        $this->assertSame([1, 4], $service->rankedProductIds(2));
        $this->assertSame([2, 3], $service->rankedProductIds(2, 2));
        $this->db->table('orders')->where('id', 1)->update(['status' => 'cancelled']);
        $this->assertSame([4, 2, 3], $service->rankedProductIds(20));
    }

    public function test_public_mode_uses_main_database_sales_and_fills_unmapped_top_positions(): void
    {
        foreach (range(1, 52) as $id) {
            $this->product($id);
            $this->sale($id, 100 - $id, 'paid');
        }
        $context = new StoreContext;
        $context->activate(['mode' => StoreContext::PUBLIC]);
        $this->app->instance(StoreContext::class, $context);
        // 此处没有展示库连接；若排名误随商品上下文切换，测试将直接报错。
        $mapping = Mockery::mock(CatalogContentMappingService::class);
        $mapping->shouldReceive('mapProductIds')->once()->with(range(1, 50))->andReturn([]);
        $mapping->shouldReceive('mapProductIds')->once()->with([51, 52])->andReturn([1051, 1052]);
        $this->app->instance(CatalogContentMappingService::class, $mapping);
        $service = Mockery::mock(AllTimeSalesService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('loadProducts')->with([])->once()->andReturn([]);
        $service->shouldReceive('loadProducts')->with([1051, 1052])->once()->andReturn([['id' => 1051], ['id' => 1052]]);

        $this->assertSame([['id' => 1051], ['id' => 1052]], $service->products(2));
    }

    public function test_ranking_deduplicates_display_products_and_respects_module_limit(): void
    {
        foreach (range(1, 4) as $id) {
            $this->product($id);
            $this->sale($id, 10 - $id, 'paid');
        }
        $mapping = Mockery::mock(CatalogContentMappingService::class);
        $mapping->shouldReceive('mapProductIds')->once()->with([1, 2, 3, 4])->andReturn([101, 101, 103, 104]);
        $this->app->instance(CatalogContentMappingService::class, $mapping);
        $service = Mockery::mock(AllTimeSalesService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('loadProducts')->with([101, 103, 104])->once()->andReturn([['id' => 101], ['id' => 103], ['id' => 104]]);

        $this->assertSame([['id' => 101], ['id' => 103]], $service->products(2));
    }

    public function test_no_valid_sales_returns_an_empty_ranking(): void
    {
        $this->product(1);
        $this->sale(1, 100, 'unpaid');

        $this->assertSame([], (new AllTimeSalesService)->products(8));
    }

    public function test_sales_mode_with_no_manual_products_persists_and_is_returned_immediately(): void
    {
        Registry::set('locale', 'zh_cn', true);
        config()->set('bk.system.base.design_setting.modules', [
            ['code' => 'bestseller', 'module_id' => 'hot', 'content' => ['title' => ['zh_cn' => '热卖']]],
            ['code' => 'product', 'module_id' => 'normal', 'content' => ['title' => ['zh_cn' => '商品']]],
            ['code' => 'tab_product', 'module_id' => 'tabs', 'content' => ['tabs' => [['title' => ['zh_cn' => '第一组']]]]],
        ]);
        $console = Mockery::mock(Kernel::class);
        $console->shouldReceive('call')->andReturn(0);
        $this->app->instance(Kernel::class, $console);
        $service = new BestsellerSelectionService;
        $state   = $service->saveModules([
            'hot'    => ['mode' => 'all_time_sales', 'product_ids' => []],
            'normal' => ['mode' => 'all_time_sales', 'product_ids' => []],
            'tabs'   => ['mode' => 'all_time_sales', 'tabs' => [['mode' => 'all_time_sales', 'product_ids' => []]]],
        ]);
        $row   = $this->db->table('settings')->where('space', 'bestseller_selector')->where('name', 'modules')->first();
        $saved = json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('all_time_sales', $saved['hot']['mode']);
        $this->assertSame('all_time_sales', $state['modules']['hot']['mode']);
        $this->assertSame([], $state['modules']['hot']['product_ids']);
        $this->assertSame('all_time_sales', $saved['normal']['mode']);
        $this->assertSame('all_time_sales', $state['modules']['normal']['mode']);
        $this->assertSame([], $state['modules']['normal']['product_ids']);
        $this->assertSame('auto', $saved['tabs']['mode']);
        $this->assertSame('auto', $saved['tabs']['tabs'][0]['mode']);
        $this->assertSame('auto', $state['modules']['tabs']['tabs'][0]['mode']);
    }

    private function product(int $id): void
    {
        $this->db->table('products')->insert(['id' => $id, 'active' => 1]);
        $this->db->table('product_skus')->insert(['product_id' => $id, 'is_default' => 1]);
    }

    private function sale(int $productId, int $quantity, string $status, string $createdAt = '2026-01-01', string $sku = 'SKU', int $orderId = null): int
    {
        $orderId ??= $this->db->table('orders')->insertGetId(['status' => $status, 'created_at' => $createdAt]);
        $this->db->table('order_products')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'quantity' => $quantity, 'product_sku' => $sku,
        ]);

        return $orderId;
    }
}
