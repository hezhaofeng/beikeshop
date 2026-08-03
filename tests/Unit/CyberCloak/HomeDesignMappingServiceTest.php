<?php

namespace Tests\Unit\CyberCloak;

use Mockery;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\HomeDesignMappingService;
use Tests\TestCase;

class HomeDesignMappingServiceTest extends TestCase
{
    /**
     * 释放路由服务 Mock，避免首页装修扫描的查询桩影响其他测试。
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 首页装修中的幻灯片、图片 Banner 和后续模块应统一检查商品与分类路由。
     */
    public function test_scans_banner_links_and_maps_catalog_records(): void
    {
        config()->set('bk.system.base.design_setting', [
            'modules' => [
                [
                    'code'      => 'slideshow',
                    'module_id' => 'slide-1',
                    'content'   => [
                        'images' => [
                            ['link' => ['type' => 'product', 'value' => 101]],
                            ['link' => ['type' => 'category', 'value' => 201]],
                            ['link' => ['type' => 'custom', 'value' => 'https://example.test/sale']],
                        ],
                    ],
                ],
                [
                    'code'      => 'img_text_banner',
                    'module_id' => 'banner-1',
                    'content'   => ['link' => ['type' => 'product', 'value' => 102]],
                ],
                [
                    'code'      => 'future_banner',
                    'module_id' => 'future-1',
                    'content'   => ['items' => [['link' => ['type' => 'product', 'value' => 103]]]],
                ],
            ],
        ]);
        $routes = Mockery::mock(CatalogRouteService::class);
        $routes->shouldReceive('publicRecordIdsForRealRecords')
            ->once()
            ->with('product', [101, 102, 103])
            ->andReturn([101 => 701, 103 => 703]);
        $routes->shouldReceive('publicRecordIdsForRealRecords')
            ->once()
            ->with('category', [201])
            ->andReturn([201 => 801]);

        $result = (new HomeDesignMappingService($routes))->scan();

        $this->assertSame(3, $result['module_count']);
        $this->assertSame(5, $result['banner_count']);
        $this->assertSame(3, $result['mapped']);
        $this->assertSame(1, $result['unmapped']);
        $this->assertSame(1, $result['not_required']);
        $this->assertSame(3, $result['counts']['product']);
        $this->assertSame(1, $result['counts']['category']);
        $this->assertSame('modules[1].content.link', $result['unresolved'][0]['path']);
    }

    /**
     * 只有自定义或静态链接时不查询路由表，并将其标记为无需映射。
     */
    public function test_custom_links_do_not_require_catalog_routes(): void
    {
        config()->set('bk.system.base.design_setting', [
            'modules' => [
                [
                    'code'    => 'img_text_banner',
                    'content' => [
                        'link' => ['type' => 'static', 'value' => 'home.index'],
                    ],
                ],
            ],
        ]);
        $routes = Mockery::mock(CatalogRouteService::class);
        $routes->shouldReceive('publicRecordIdsForRealRecords')->never();

        $result = (new HomeDesignMappingService($routes))->scan();

        $this->assertSame(1, $result['banner_count']);
        $this->assertSame(0, $result['mapped']);
        $this->assertSame(0, $result['unmapped']);
        $this->assertSame(1, $result['not_required']);
    }
}
