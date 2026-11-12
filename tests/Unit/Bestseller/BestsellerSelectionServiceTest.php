<?php

namespace Tests\Unit\Bestseller;

use Beike\Libraries\Registry;
use Mockery;
use Plugin\Bestseller\Services\AllTimeSalesService;
use Plugin\Bestseller\Services\BestsellerSelectionService;
use Plugin\CyberCloak\Services\CatalogContentMappingService;

class BestsellerSelectionServiceTest extends BestsellerTestCase
{
    /**
     * 勾选集合应过滤非法值和重复值，并保持用户最早勾选的顺序。
     */
    public function test_normalize_ids_keeps_first_selected_order(): void
    {
        $service = new BestsellerSelectionService;

        $this->assertSame([12, 8, 5], $service->normalizeIds([
            12,
            '8',
            ['id' => 12],
            ['id' => 5],
            0,
            -1,
            ['name' => 'no id'],
        ]));
    }

    /**
     * 未配置或非法模式都应保持原有销量排序，避免首页热卖区被意外覆盖。
     */
    public function test_invalid_mode_falls_back_to_automatic_mode(): void
    {
        config()->set('bk.plugin.bestseller_selector.mode', 'invalid');
        $service = new BestsellerSelectionService;

        $this->assertSame('auto', $service->mode());
        $this->assertSame(['products' => ['id' => 99]], $service->applyHomepageSelection([
            'products' => ['id' => 99],
        ]));
    }

    /**
     * 手动模式读取的历史配置也应清理重复和无效 ID，兼容设置表中的 JSON 数组。
     */
    public function test_selected_ids_are_normalized_from_plugin_settings(): void
    {
        config()->set('bk.plugin.bestseller_selector.mode', 'manual');
        config()->set('bk.plugin.bestseller_selector.product_ids', [33, '12', 33, 0]);
        $service = new BestsellerSelectionService;

        $this->assertSame('manual', $service->mode());
        $this->assertSame([33, 12], $service->selectedIds());
    }

    /**
     * 旧插件停用后，合并插件仍按原 module_id 和 Tab 配置覆盖，各实例互不影响。
     */
    public function test_existing_module_and_tab_settings_remain_independent_after_merge(): void
    {
        config()->set('bk.plugin.bestseller_selector', [
            'status'  => false,
            'modules' => [
                'hot-one' => ['mode' => 'manual', 'product_ids' => [33, 12]],
                'hot-two' => ['mode' => 'auto', 'product_ids' => [99]],
                'normal'  => ['mode' => 'manual', 'product_ids' => [81]],
                'tabs'    => ['tabs' => [
                    ['mode' => 'manual', 'product_ids' => [55, 21]],
                    ['mode' => 'auto', 'product_ids' => [66]],
                ]],
            ],
        ]);
        // 模拟展示库暂无映射，核对传入顺序和覆盖目标，避免访问真实商品库。
        $mapping = Mockery::mock(CatalogContentMappingService::class);
        $mapping->shouldReceive('mapProductIds')->once()->with([33, 12])->andReturn([]);
        $mapping->shouldReceive('mapProductIds')->once()->with([81])->andReturn([]);
        $mapping->shouldReceive('mapProductIds')->once()->with([55, 21])->andReturn([]);
        $this->app->instance(CatalogContentMappingService::class, $mapping);
        $service = new BestsellerSelectionService;

        $hot = ['module_code' => 'bestseller', 'module_id' => 'hot-one', 'title' => '热卖', 'products' => [1]];
        $this->assertSame(array_replace($hot, ['products' => []]), $service->applyHomepageSelection($hot));
        $auto = ['module_code' => 'bestseller', 'module_id' => 'hot-two', 'products' => [2]];
        $this->assertSame($auto, $service->applyHomepageSelection($auto));
        $missing = ['module_code' => 'bestseller', 'module_id' => 'new-module', 'products' => [3]];
        $this->assertSame($missing, $service->applyHomepageSelection($missing));
        $normal = ['module_code' => 'product', 'module_id' => 'normal', 'products' => [4]];
        $this->assertSame(array_replace($normal, ['products' => []]), $service->applyHomepageSelection($normal));
        $tabs = ['module_code' => 'tab_product', 'module_id' => 'tabs', 'tabs' => [
            ['title' => '手动', 'products' => [5]],
            ['title' => '自动', 'products' => [6]],
        ]];
        $expected                        = $tabs;
        $expected['tabs'][0]['products'] = [];
        $this->assertSame($expected, $service->applyHomepageSelection($tabs));
    }

    /**
     * 最早的单列表设置依然能驱动热卖区，不影响普通商品模块。
     */
    public function test_legacy_single_list_settings_still_apply_only_to_bestseller(): void
    {
        config()->set('bk.plugin.bestseller_selector', ['mode' => 'manual', 'product_ids' => [33, 12]]);
        $mapping = Mockery::mock(CatalogContentMappingService::class);
        $mapping->shouldReceive('mapProductIds')->once()->with([33, 12])->andReturn([]);
        $this->app->instance(CatalogContentMappingService::class, $mapping);
        $service = new BestsellerSelectionService;

        $hot = ['module_code' => 'bestseller', 'module_id' => 'old-hot', 'products' => [1]];
        $this->assertSame(array_replace($hot, ['products' => []]), $service->applyHomepageSelection($hot));
        $normal = ['module_code' => 'product', 'module_id' => 'normal', 'products' => [2]];
        $this->assertSame($normal, $service->applyHomepageSelection($normal));
    }

    public function test_editor_state_restores_saved_product_order_and_tab_configuration(): void
    {
        Registry::set('locale', 'zh_cn', true);
        config()->set('bk.system.base.design_setting.modules', [
            ['code' => 'bestseller', 'module_id' => 'hot', 'content' => ['title' => ['zh_cn' => '首页热卖']]],
            ['code' => 'tab_product', 'module_id' => 'tabs', 'content' => [
                'title' => ['zh_cn' => '分类精选'],
                'tabs'  => [['title' => ['zh_cn' => '第一组']]],
            ]],
        ]);
        config()->set('bk.plugin.bestseller_selector.modules', [
            'hot'  => ['mode' => 'manual', 'product_ids' => [33, 12]],
            'tabs' => ['tabs' => [['mode' => 'manual', 'product_ids' => [55, 21]]]],
        ]);
        $mapping = Mockery::mock(CatalogContentMappingService::class);
        $mapping->shouldReceive('mapProductIds')->with([33, 12])->once()->andReturn([]);
        $mapping->shouldReceive('mapProductIds')->with([])->once()->andReturn([]);
        $mapping->shouldReceive('mapProductIds')->with([55, 21])->once()->andReturn([]);
        $this->app->instance(CatalogContentMappingService::class, $mapping);

        try {
            $state = (new BestsellerSelectionService)->state();
            $this->assertSame(['hot', 'tabs'], array_column($state['homepage_modules'], 'id'));
            $this->assertSame('首页热卖', $state['homepage_modules'][0]['title']);
            $this->assertSame('manual', $state['modules']['hot']['mode']);
            $this->assertSame([33, 12], $state['modules']['hot']['product_ids']);
            $this->assertSame('manual', $state['modules']['tabs']['tabs'][0]['mode']);
            $this->assertSame([55, 21], $state['modules']['tabs']['tabs'][0]['product_ids']);
        } finally {
            Registry::getSingleton()->destroy();
        }
    }

    public function test_all_time_sales_applies_only_to_selected_supported_instances_with_their_display_limits(): void
    {
        config()->set('bk.plugin.bestseller_selector.modules', [
            'hot'    => ['mode' => 'all_time_sales', 'product_ids' => []],
            'other'  => ['mode' => 'auto', 'product_ids' => []],
            'normal' => ['mode' => 'all_time_sales', 'product_ids' => []],
            'tabs'   => ['mode' => 'all_time_sales', 'product_ids' => []],
        ]);
        $ranking = Mockery::mock(AllTimeSalesService::class);
        $ranking->shouldReceive('products')->once()->with(3)->andReturn([['id' => 8], ['id' => 5]]);
        $ranking->shouldReceive('products')->once()->with(5)->andReturn([['id' => 8], ['id' => 5], ['id' => 4]]);
        $this->app->instance(AllTimeSalesService::class, $ranking);
        $service = new BestsellerSelectionService;
        $hot     = ['module_code' => 'bestseller', 'module_id' => 'hot', 'limit' => 3, 'products' => [1]];
        $this->assertSame([['id' => 8], ['id' => 5]], $service->applyHomepageSelection($hot)['products']);
        $normal = ['module_code' => 'product', 'module_id' => 'normal', 'limit' => 5, 'title' => 'Bestsellers', 'desktop_columns' => 4, 'products' => [1]];
        $this->assertSame(array_replace($normal, ['products' => [['id' => 8], ['id' => 5], ['id' => 4]]]), $service->applyHomepageSelection($normal));
        foreach ([['bestseller', 'other'], ['bestseller', 'new'], ['product', 'other'], ['product', 'new'], ['tab_product', 'tabs']] as [$code, $id]) {
            $data = ['module_code' => $code, 'module_id' => $id, 'products' => [2]];
            $this->assertSame($data, $service->applyHomepageSelection($data));
        }
    }

    public function test_product_sales_mode_defaults_to_eight_and_preserves_manual_selection(): void
    {
        config()->set('bk.plugin.bestseller_selector.modules', [
            'normal' => ['mode' => 'all_time_sales', 'product_ids' => [33, 12]],
        ]);
        $ranking = Mockery::mock(AllTimeSalesService::class);
        $ranking->shouldReceive('products')->once()->with(8)->andReturn([['id' => 9]]);
        $this->app->instance(AllTimeSalesService::class, $ranking);
        $service = new BestsellerSelectionService;
        $data    = ['module_code' => 'product', 'module_id' => 'normal', 'products' => [1]];

        $this->assertSame([['id' => 9]], $service->applyHomepageSelection($data)['products']);
        $this->assertSame([33, 12], config('bk.plugin.bestseller_selector.modules.normal.product_ids'));
    }

    public function test_legacy_all_time_sales_mode_does_not_override_explicit_automatic_module(): void
    {
        config()->set('bk.plugin.bestseller_selector', [
            'mode'    => 'all_time_sales',
            'modules' => ['auto-hot' => ['mode' => 'auto'], 'auto-normal' => ['mode' => 'auto']],
        ]);
        $ranking = Mockery::mock(AllTimeSalesService::class);
        $ranking->shouldReceive('products')->once()->with(8)->andReturn([]);
        $this->app->instance(AllTimeSalesService::class, $ranking);
        $service = new BestsellerSelectionService;

        $this->assertSame('all_time_sales', $service->mode());
        $this->assertSame([], $service->applyHomepageSelection(['module_code' => 'bestseller'])['products']);
        $auto = ['module_code' => 'bestseller', 'module_id' => 'auto-hot', 'products' => [1]];
        $this->assertSame($auto, $service->applyHomepageSelection($auto));
        foreach (['auto-normal', 'unconfigured'] as $moduleId) {
            $normal = ['module_code' => 'product', 'module_id' => $moduleId, 'products' => [2]];
            $this->assertSame($normal, $service->applyHomepageSelection($normal));
        }
    }
}
