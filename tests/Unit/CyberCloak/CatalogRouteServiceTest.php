<?php

namespace Tests\Unit\CyberCloak;

use Plugin\CyberCloak\Services\CatalogRouteService;
use Tests\TestCase;

class CatalogRouteServiceTest extends TestCase
{
    /**
     * 分类重建统计应区分路由行数和实际被复用的展示分类数。
     */
    public function test_category_route_stat_counts_distinct_public_categories(): void
    {
        $service = new CatalogRouteService;
        $method  = (new \ReflectionClass($service))->getMethod('mappedPublicCategoryCount');

        $count = $method->invoke($service, [
            ['public_record_id' => 7],
            ['public_record_id' => 7],
            ['public_record_id' => 12],
            ['public_record_id' => null],
            [],
        ]);

        $this->assertSame(2, $count);
    }
}
