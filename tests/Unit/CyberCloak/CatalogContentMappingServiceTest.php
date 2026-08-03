<?php

namespace Tests\Unit\CyberCloak;

use Mockery;
use Plugin\CyberCloak\Services\CatalogContentMappingService;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\StoreContext;
use Tests\TestCase;

class CatalogContentMappingServiceTest extends TestCase
{
    /**
     * 清理 Mockery，避免路由映射桩影响后续测试。
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 真实模式应保持首页装修保存的真实商品顺序和 ID。
     */
    public function test_real_mode_keeps_configured_product_ids(): void
    {
        $context = new StoreContext;
        $context->activate(['mode' => StoreContext::REAL]);
        app()->instance(StoreContext::class, $context);
        $routes = Mockery::mock(CatalogRouteService::class);
        $routes->shouldReceive('publicRecordIdsForRealRecords')->never();

        $result = (new CatalogContentMappingService($routes))->mapProductIds([101, 102, 101]);

        $this->assertSame([101, 102, 101], $result);
        $context->reset();
    }

    /**
     * Cloak 模式应批量转换真实商品 ID，并过滤没有已发布展示映射的商品。
     */
    public function test_public_mode_maps_and_filters_product_ids(): void
    {
        $context = new StoreContext;
        $context->activate(['mode' => StoreContext::PUBLIC]);
        app()->instance(StoreContext::class, $context);
        $routes = Mockery::mock(CatalogRouteService::class);
        $routes->shouldReceive('publicRecordIdsForRealRecords')
            ->once()
            ->with('product', [101, 102, 103])
            ->andReturn([101 => 7, 103 => 9]);

        $result = (new CatalogContentMappingService($routes))->mapProductIds([101, 102, 103]);

        $this->assertSame([7, 9], $result);
        $context->reset();
    }

    /**
     * 装修器提交带 id 字段的商品对象时，映射服务应统一提取商品 ID。
     */
    public function test_product_payload_objects_are_normalized(): void
    {
        $context = new StoreContext;
        $context->activate(['mode' => StoreContext::PUBLIC]);
        app()->instance(StoreContext::class, $context);
        $routes = Mockery::mock(CatalogRouteService::class);
        $routes->shouldReceive('publicRecordIdsForRealRecords')
            ->once()
            ->with('product', [101, 102])
            ->andReturn([101 => 7, 102 => 8]);

        $result = (new CatalogContentMappingService($routes))->mapProductIds([
            ['id' => 101],
            (object) ['id' => 102],
        ]);

        $this->assertSame([7, 8], $result);
        $context->reset();
    }
}
