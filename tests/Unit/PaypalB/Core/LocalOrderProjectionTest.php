<?php

namespace Tests\Unit\PaypalB\Core;

use PHPUnit\Framework\TestCase;
use Plugin\PaypalB\Services\PaypalBMoney;
use Plugin\PaypalB\Services\PaypalBOrderPayload;
use Plugin\PaypalB\Services\PaypalBTransactionService;

/**
 * B 站本地订单以系统基准币种存储金额，提交 PayPal 前必须按订单汇率投影到订单币种。
 * 纯单元环境不启动 Laravel 容器，只验证投影结果、PayPal 请求体与网关金额校验的一致性。
 */
class LocalOrderProjectionTest extends TestCase
{
    public function test_eur_projection_converts_each_line_and_books_rounding_difference(): void
    {
        // 基准币种 USD，订单币种 EUR，汇率 0.979。
        $projection = PaypalBMoney::projectOrder(
            [
                ['source_product_id' => 11, 'name' => 'Lamp', 'sku' => 'L-1', 'quantity' => 3, 'price' => '10.0050'],
                ['source_product_id' => 12, 'name' => 'Bulb', 'sku' => 'B-1', 'quantity' => 1, 'price' => '5.5500'],
            ],
            [
                ['code' => 'sub_total', 'title' => 'Sub-total', 'value' => '35.5650'],
                ['code' => 'shipping', 'title' => 'Shipping', 'value' => '5.0000'],
                ['code' => 'promotion', 'title' => 'Discount', 'value' => '-1.5000'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '39.0650'],
            ],
            '39.0650',
            '0.97900000',
            'eur'
        );

        $this->assertSame('EUR', $projection['currency']);
        $this->assertSame('38.24', $projection['amount'], '总额 = round(39.065 × 0.979) = 38.24');
        $this->assertSame([
            ['source_product_id' => 11, 'name' => 'Lamp', 'sku' => 'L-1', 'quantity' => 3, 'unit_price' => '9.79', 'line_total' => '29.37'],
            ['source_product_id' => 12, 'name' => 'Bulb', 'sku' => 'B-1', 'quantity' => 1, 'unit_price' => '5.43', 'line_total' => '5.43'],
        ], $projection['items']);
        $this->assertSame([
            ['code' => 'sub_total', 'title' => 'Sub-total', 'value' => '34.80'],
            ['code' => 'shipping', 'title' => 'Shipping', 'value' => '4.90'],
            ['code' => 'promotion', 'title' => 'Discount', 'value' => '-1.47'],
            ['code' => 'currency_rounding', 'title' => 'Rounding', 'value' => '0.01'],
            ['code' => 'order_total', 'title' => 'Total', 'value' => '38.24'],
        ], $projection['totals']);

        $payload = $this->detailedPayload($projection);
        $unit    = $payload['purchase_units'][0];
        $this->assertSame(['currency_code' => 'EUR', 'value' => '38.24'], array_intersect_key($unit['amount'], ['currency_code' => 1, 'value' => 1]));
        $this->assertSame('34.80', $unit['amount']['breakdown']['item_total']['value']);
        $this->assertSame('4.90', $unit['amount']['breakdown']['shipping']['value']);
        // 正的舍入差额归入 handling，折扣保持为 discount。
        $this->assertSame('0.01', $unit['amount']['breakdown']['handling']['value']);
        $this->assertSame('1.47', $unit['amount']['breakdown']['discount']['value']);
        $this->assertSame('9.79', $unit['items'][0]['unit_amount']['value']);

        $this->assertGatewayAcceptsAmounts($projection);
    }

    public function test_jpy_projection_uses_zero_decimal_precision(): void
    {
        $projection = PaypalBMoney::projectOrder(
            [['source_product_id' => 21, 'name' => 'Tea', 'sku' => 'T-1', 'quantity' => 3, 'price' => '12.3400']],
            [
                ['code' => 'sub_total', 'title' => 'Sub-total', 'value' => '37.0200'],
                ['code' => 'shipping', 'title' => 'Shipping', 'value' => '4.9900'],
                ['code' => 'promotion', 'title' => 'Coupon', 'value' => '-0.5000'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '41.5100'],
            ],
            '41.5100',
            157.3,
            'JPY'
        );

        $this->assertSame('6530', $projection['amount'], '总额 = round(41.51 × 157.3) = 6530，日元没有小数位');
        $this->assertSame('1941', $projection['items'][0]['unit_price']);
        $this->assertSame('5823', $projection['items'][0]['line_total']);
        $this->assertSame([
            ['code' => 'sub_total', 'title' => 'Sub-total', 'value' => '5823'],
            ['code' => 'shipping', 'title' => 'Shipping', 'value' => '785'],
            ['code' => 'promotion', 'title' => 'Coupon', 'value' => '-79'],
            ['code' => 'currency_rounding', 'title' => 'Rounding', 'value' => '1'],
            ['code' => 'order_total', 'title' => 'Total', 'value' => '6530'],
        ], $projection['totals']);

        $unit = $this->detailedPayload($projection)['purchase_units'][0];
        $this->assertSame('6530', $unit['amount']['value']);
        $this->assertSame('1941', $unit['items'][0]['unit_amount']['value']);
        $this->assertSame('1', $unit['amount']['breakdown']['handling']['value']);
        $this->assertSame('79', $unit['amount']['breakdown']['discount']['value']);

        $this->assertGatewayAcceptsAmounts($projection);
    }

    public function test_base_currency_order_with_four_decimals_is_rounded_instead_of_rejected(): void
    {
        // 旧实现直接把 18.9905 当作 USD 金额提交，PaypalBMoney::normalize 会因多余小数位拒绝整笔付款。
        $projection = PaypalBMoney::projectOrder(
            [['source_product_id' => 31, 'name' => 'Cable', 'sku' => 'C-1', 'quantity' => 2, 'price' => '18.9905']],
            [
                ['code' => 'sub_total', 'title' => 'Sub-total', 'value' => '37.9810'],
                ['code' => 'shipping', 'title' => 'Shipping', 'value' => '0.0040'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '37.9850'],
            ],
            '37.9850',
            '1.00000000',
            'USD'
        );

        $this->assertSame('37.99', $projection['amount'], '37.985 必须按十进制四舍五入为 37.99，不能被二进制尾差舍成 37.98');
        $this->assertSame('18.99', $projection['items'][0]['unit_price']);
        $this->assertSame('37.98', $projection['items'][0]['line_total']);
        $this->assertSame([
            ['code' => 'sub_total', 'title' => 'Sub-total', 'value' => '37.98'],
            ['code' => 'shipping', 'title' => 'Shipping', 'value' => '0.00'],
            ['code' => 'currency_rounding', 'title' => 'Rounding', 'value' => '0.01'],
            ['code' => 'order_total', 'title' => 'Total', 'value' => '37.99'],
        ], $projection['totals']);

        $this->assertGatewayAcceptsAmounts($projection);

        $rejected = false;

        try {
            PaypalBMoney::normalize('18.9905', 'USD');
        } catch (\Throwable) {
            $rejected = true;
        }
        $this->assertTrue($rejected, '未经投影的 4 位小数基准金额不能直接当作 PayPal 金额。');
    }

    public function test_negative_rounding_difference_becomes_a_discount(): void
    {
        $projection = PaypalBMoney::projectOrder(
            [['source_product_id' => 41, 'name' => 'Pen', 'sku' => 'P-1', 'quantity' => 3, 'price' => '1.0050']],
            [
                ['code' => 'sub_total', 'title' => 'Sub-total', 'value' => '3.0150'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '3.0150'],
            ],
            '3.0150',
            1,
            'USD'
        );

        // 单价 1.005 → 1.01，行总额 3.03；总额 3.015 → 3.02，差额 -0.01。
        $this->assertSame('3.02', $projection['amount']);
        $this->assertSame('3.03', $projection['items'][0]['line_total']);
        $this->assertSame(['code' => 'currency_rounding', 'title' => 'Rounding', 'value' => '-0.01'], $projection['totals'][1]);

        $unit = $this->detailedPayload($projection)['purchase_units'][0];
        $this->assertSame('0.01', $unit['amount']['breakdown']['discount']['value']);
        $this->assertArrayNotHasKey('handling', $unit['amount']['breakdown']);

        $this->assertGatewayAcceptsAmounts($projection);
    }

    public function test_inconsistent_base_amounts_are_rejected_instead_of_absorbed_as_rounding(): void
    {
        $rejected = false;

        try {
            PaypalBMoney::projectOrder(
                [['source_product_id' => 51, 'name' => 'Box', 'sku' => 'X-1', 'quantity' => 1, 'price' => '10.0000']],
                [
                    ['code' => 'sub_total', 'title' => 'Sub-total', 'value' => '10.0000'],
                    ['code' => 'order_total', 'title' => 'Total', 'value' => '12.0000'],
                ],
                '12.0000',
                '0.979',
                'EUR'
            );
        } catch (\Throwable) {
            // 纯单元环境没有 Validator 门面，这里只验证一定会被拒绝。
            $rejected = true;
        }

        $this->assertTrue($rejected, '分项与总额相差远超舍入误差时必须拒绝，不能写成 currency_rounding。');
    }

    public function test_base_units_round_trip_for_mirror_orders(): void
    {
        // 9.79 EUR / 0.979 = 10.0000 USD；展示时 10 × 0.979 = 9.79，与 PayPal 实收一致。
        $this->assertSame('10.0000', PaypalBMoney::fromBaseUnits(PaypalBMoney::toBaseUnits('9.79', '0.979')));
        $this->assertSame('-1.5000', PaypalBMoney::fromBaseUnits(PaypalBMoney::toBaseUnits('-1.47', '0.98')));
        $this->assertSame('41.5100', PaypalBMoney::fromBaseUnits(PaypalBMoney::toBaseUnits('6529.523', '157.3')));
        $this->assertSame('12.5000', PaypalBMoney::fromBaseUnits(PaypalBMoney::toBaseUnits('12.5', 1)));
        $this->assertSame(2, PaypalBMoney::scale('USD'));
        $this->assertSame(0, PaypalBMoney::scale('jpy'));
    }

    private function detailedPayload(array $projection): array
    {
        return PaypalBOrderPayload::buildFromData([
            'reference'        => 'REF-LOCAL-1',
            'order_number'     => '202609250001',
            'amount'           => $projection['amount'],
            'currency'         => $projection['currency'],
            'order_items'      => $projection['items'],
            'order_totals'     => $projection['totals'],
            'shipping_address' => ['required' => false],
        ], 'https://b.example.test/paypal/return', 'https://b.example.test/paypal/cancel', 'Example Store', [
            'mode' => PaypalBOrderPayload::MODE_DETAILED,
        ]);
    }

    /**
     * 网关建会话、同账号重试与捕获前都会走 validateOrderTotals + assertOrderAmounts，
     * currency_rounding 行必须被当作普通的有符号费用行接受。
     */
    private function assertGatewayAcceptsAmounts(array $projection): void
    {
        $service  = new PaypalBTransactionService([]);
        $validate = new \ReflectionMethod($service, 'validateOrderTotals');
        $assert   = new \ReflectionMethod($service, 'assertOrderAmounts');
        $validate->setAccessible(true);
        $assert->setAccessible(true);

        $totals = $validate->invoke($service, $projection['totals']);
        $assert->invoke($service, $projection['items'], $totals, $projection['amount'], $projection['currency']);

        $this->assertSame($projection['totals'], $totals);
    }
}
