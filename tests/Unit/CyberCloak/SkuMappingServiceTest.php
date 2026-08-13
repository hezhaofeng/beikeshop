<?php

namespace Tests\Unit\CyberCloak;

use Plugin\CyberCloak\Services\SkuMappingService;
use Tests\TestCase;

class SkuMappingServiceTest extends TestCase
{
    /**
     * 同一商品下的 SKU 指向同一个展示商品时，商品映射可以直接确认。
     */
    public function test_product_mapping_row_confirms_a_single_public_product(): void
    {
        $service = new SkuMappingService;
        $method  = (new \ReflectionClass($service))->getMethod('productMappingRow');

        $mapping = $method->invoke($service, 'v-test', 10, [
            ['status' => 'confirmed', 'public_id' => 20, 'candidate' => []],
            ['status' => 'confirmed', 'public_id' => 20, 'candidate' => []],
        ], now());

        $this->assertSame('confirmed', $mapping['status']);
        $this->assertSame(20, $mapping['public_product_id']);
        $this->assertNull($mapping['candidate_data']);
    }

    /**
     * 商品存在待确认 SKU 时，商品层也必须保持待确认，不能提前发布。
     */
    public function test_product_mapping_row_keeps_pending_sku_as_pending_product(): void
    {
        $service = new SkuMappingService;
        $method  = (new \ReflectionClass($service))->getMethod('productMappingRow');

        $mapping = $method->invoke($service, 'v-test', 10, [
            ['status' => 'confirmed', 'public_id' => 20, 'candidate' => []],
            ['status' => 'pending', 'public_id' => null, 'candidate' => ['public_sku_ids' => [101]]],
        ], now());

        $this->assertSame('pending', $mapping['status']);
        $this->assertSame(20, $mapping['public_product_id']);
    }

    /**
     * 候选模式只从当前商品同名的展示商品中返回 SKU，避免跨批次扫描全库。
     */
    public function test_name_candidates_are_limited_to_the_current_product_batch(): void
    {
        $service = new SkuMappingService;
        $method  = (new \ReflectionClass($service))->getMethod('nameCandidatesForProduct');

        $result = $method->invoke($service, 10, [
            'real'   => [10 => 'sample product'],
            'public' => ['sample product' => [20]],
        ], [
            20 => [(object) ['id' => 101], (object) ['id' => 102]],
            30 => [(object) ['id' => 201]],
        ]);

        $this->assertSame([
            'public_sku_ids' => [101, 102],
            'reason'         => 'name_exact_candidate',
        ], $result);
    }

    /**
     * 同名展示商品过多时只保留有限候选，管理员仍可通过 SKU 搜索补充确认。
     */
    public function test_name_candidates_cap_the_stored_sku_list(): void
    {
        $service = new SkuMappingService;
        $method  = (new \ReflectionClass($service))->getMethod('nameCandidatesForProduct');
        $skus    = array_map(static fn (int $id): object => (object) ['id' => $id], range(1, 120));

        $result = $method->invoke($service, 10, [
            'real'   => [10 => 'sample product'],
            'public' => ['sample product' => [20]],
        ], [20 => $skus]);

        $this->assertCount(100, $result['public_sku_ids']);
        $this->assertTrue($result['truncated']);
    }

    /**
     * 型号重复时只保留有限精确候选，避免冲突 SKU 把整库记录放入内存。
     */
    public function test_exact_candidate_index_is_capped_per_match_key(): void
    {
        $service = new SkuMappingService;
        $method  = (new \ReflectionClass($service))->getMethod('indexPublicSkus');
        $rows    = array_map(static fn (int $id): object => (object) [
            'id'         => $id,
            'product_id' => $id,
            'sku'        => '',
            'model'      => 'SHARED-MODEL',
            'variants'   => null,
        ], range(1, 120));

        $index = $method->invoke($service, $rows);

        $this->assertCount(100, $index['model:shared-model']);
    }
}
