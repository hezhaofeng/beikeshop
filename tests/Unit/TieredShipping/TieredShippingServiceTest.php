<?php

namespace Tests\Unit\TieredShipping;

use Plugin\TieredShipping\Services\TieredShippingService;
use Tests\TestCase;

class TieredShippingServiceTest extends TestCase
{
    /**
     * 满额免运费按订单商品小计的临界值计算。
     */
    public function test_amount_threshold_makes_shipping_free_at_the_boundary(): void
    {
        $service = new TieredShippingService;
        $setting = [
            'calculation_mode'      => TieredShippingService::MODE_AMOUNT_FREE,
            'standard_fee'          => '12.50',
            'amount_free_threshold' => '99.00',
        ];

        $this->assertSame(12.5, $service->calculate(98.99, 1, $setting));
        $this->assertSame(0.0, $service->calculate(99.00, 1, $setting));
    }

    /**
     * 金额阶梯应自动排序，并命中不高于订单金额的最高门槛。
     */
    public function test_tiered_amount_uses_the_highest_matching_threshold(): void
    {
        $service = new TieredShippingService;
        $setting = [
            'calculation_mode' => TieredShippingService::MODE_TIERED_AMOUNT,
            'standard_fee'     => 18,
            'tiered_rules'     => [
                ['amount' => 200, 'fee' => 0],
                ['amount' => 50, 'fee' => 12],
                ['amount' => 100, 'fee' => 8],
            ],
        ];

        $this->assertSame(18.0, $service->calculate(49.99, 1, $setting));
        $this->assertSame(12.0, $service->calculate(50, 1, $setting));
        $this->assertSame(8.0, $service->calculate(150, 1, $setting));
        $this->assertSame(0.0, $service->calculate(200, 1, $setting));
    }

    /**
     * 商品件数达到配置值时才免运费。
     */
    public function test_quantity_threshold_makes_shipping_free_at_the_boundary(): void
    {
        $service = new TieredShippingService;
        $setting = [
            'calculation_mode'        => TieredShippingService::MODE_QUANTITY_FREE,
            'standard_fee'            => 9.9,
            'quantity_free_threshold' => 3,
        ];

        $this->assertSame(9.9, $service->calculate(10, 2, $setting));
        $this->assertSame(0.0, $service->calculate(10, 3, $setting));
    }

    /**
     * 保存金额阶梯时必须至少有一条规则，且门槛不能重复。
     */
    public function test_tiered_configuration_requires_unique_rules(): void
    {
        $validator = TieredShippingService::validateConfiguration([
            'calculation_mode' => TieredShippingService::MODE_TIERED_AMOUNT,
            'standard_fee'     => 10,
            'tiered_rules'     => [
                ['amount' => 100, 'fee' => 8],
                ['amount' => 100.00, 'fee' => 0],
            ],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tiered_rules.1.amount', $validator->errors()->toArray());
    }

    /**
     * 后台配置模板应能由 Blade 编译器解析。
     */
    public function test_configuration_template_is_compilable(): void
    {
        foreach ([
            'plugins/TieredShipping/Views/admin/config.blade.php',
            'plugins/TieredShipping/Views/admin/config_form.blade.php',
        ] as $path) {
            $content = file_get_contents(base_path($path));

            $this->assertNotSame('', app('blade.compiler')->compileString($content));
        }
    }
}
