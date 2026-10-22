<?php

namespace Tests\Unit\PaypalA;

use PHPUnit\Framework\TestCase;
use Plugin\PaypalA\Services\PaypalAMoney;
use Plugin\PaypalA\Services\PaypalAOrderAmounts;
use Plugin\PaypalB\Services\PaypalBOrderPayload;

/**
 * 订单金额的买家币种投影：基础货币金额按订单汇率换算、按 PayPal 币种精度取整，
 * 舍入差额以 currency_rounding 行补齐，保证发给 B 站的明细能通过 B 站逐项核对。
 */
class PaypalAOrderAmountsTest extends TestCase
{
    public function test_eur_order_is_converted_per_line_and_rounding_row_closes_the_gap(): void
    {
        $projection = PaypalAOrderAmounts::project(
            [
                ['source_product_id' => 11, 'name' => 'Jersey', 'sku' => 'J-1', 'quantity' => 2, 'price' => '20.0000'],
                ['source_product_id' => 12, 'name' => 'Socks', 'sku' => 'S-1', 'quantity' => 1, 'price' => '7.5000'],
            ],
            [
                ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '47.5000'],
                ['code' => 'shipping', 'title' => 'Shipping', 'value' => '6.0000'],
                ['code' => 'customer_discount', 'title' => 'Discount', 'value' => '-2.0000'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '51.5000'],
            ],
            '51.5000',
            'eur',
            '0.97900000'
        );

        $this->assertSame('EUR', $projection['currency']);
        $this->assertSame(0.979, $projection['rate']);
        // 单价先换算再取整（20 × 0.979 = 19.58，7.5 × 0.979 = 7.3425 → 7.34），行总额 = 单价 × 数量。
        $this->assertSame([
            ['source_product_id' => 11, 'name' => 'Jersey', 'sku' => 'J-1', 'quantity' => 2, 'unit_price' => '19.58', 'line_total' => '39.16'],
            ['source_product_id' => 12, 'name' => 'Socks', 'sku' => 'S-1', 'quantity' => 1, 'unit_price' => '7.34', 'line_total' => '7.34'],
        ], $projection['items']);
        // 小计由行总额累加（46.50，而不是 47.5 × 0.979 = 46.50 的独立换算）；
        // 总额 51.5 × 0.979 = 50.4185 → 50.42，分项合计 46.50 + 5.87 − 1.96 = 50.41，差 0.01 插在总额行之前。
        $this->assertSame([
            ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '46.50'],
            ['code' => 'shipping', 'title' => 'Shipping', 'value' => '5.87'],
            ['code' => 'customer_discount', 'title' => 'Discount', 'value' => '-1.96'],
            ['code' => 'currency_rounding', 'title' => 'Rounding', 'value' => '0.01'],
            ['code' => 'order_total', 'title' => 'Total', 'value' => '50.42'],
        ], $projection['totals']);
        $this->assertSame('50.42', $projection['amount']);
        $this->assertTotalsBalance($projection);
    }

    public function test_cny_order_with_four_decimal_base_amounts_needs_no_rounding_row(): void
    {
        // 基础货币 USD 保存 4 位小数：18.9905 × 3 + 4.0095 = 60.981。
        $projection = PaypalAOrderAmounts::project(
            [['source_product_id' => 21, 'name' => 'Cap', 'sku' => 'C-1', 'quantity' => 3, 'price' => '18.9905']],
            [
                ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '56.9715'],
                ['code' => 'shipping', 'title' => 'Shipping', 'value' => '4.0095'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '60.9810'],
            ],
            '60.9810',
            'CNY',
            7.1121
        );

        // 18.9905 × 7.1121 = 135.0623… → 135.06；4.0095 × 7.1121 = 28.5159… → 28.52；60.981 × 7.1121 = 433.7029… → 433.70。
        $this->assertSame('135.06', $projection['items'][0]['unit_price']);
        $this->assertSame('405.18', $projection['items'][0]['line_total']);
        $this->assertSame(['sub_total', 'shipping', 'order_total'], array_column($projection['totals'], 'code'));
        $this->assertSame(['405.18', '28.52', '433.70'], array_column($projection['totals'], 'value'));
        $this->assertSame('433.70', $projection['amount']);
        $this->assertTotalsBalance($projection);
    }

    public function test_jpy_order_uses_zero_decimal_precision(): void
    {
        $projection = PaypalAOrderAmounts::project(
            [['source_product_id' => 31, 'name' => 'Scarf', 'sku' => 'SC-1', 'quantity' => 2, 'price' => '12.3400']],
            [
                ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '24.6800'],
                ['code' => 'shipping', 'title' => 'Shipping', 'value' => '3.3300'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '28.0100'],
            ],
            '28.0100',
            'JPY',
            '149.87'
        );

        // 12.34 × 149.87 = 1849.3958 → 1849；3.33 × 149.87 = 499.0671 → 499；28.01 × 149.87 = 4197.8587 → 4198。
        $this->assertSame('1849', $projection['items'][0]['unit_price']);
        $this->assertSame('3698', $projection['items'][0]['line_total']);
        $this->assertSame(
            [['sub_total', '3698'], ['shipping', '499'], ['currency_rounding', '1'], ['order_total', '4198']],
            array_map(static fn (array $row): array => [$row['code'], $row['value']], $projection['totals'])
        );
        $this->assertSame('4198', $projection['amount']);
        $this->assertTotalsBalance($projection);
    }

    public function test_base_usd_with_four_decimal_totals_is_rounded_to_what_the_buyer_saw(): void
    {
        $exact = PaypalAOrderAmounts::project(
            [['source_product_id' => 41, 'name' => 'Ball', 'sku' => 'B-1', 'quantity' => 1, 'price' => '18.9905']],
            [
                ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '18.9905'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '18.9905'],
            ],
            '18.9905',
            'USD',
            '1.00000000'
        );
        $this->assertSame('18.99', $exact['amount']);
        $this->assertSame(['18.99', '18.99'], array_column($exact['totals'], 'value'));
        $this->assertNotContains('currency_rounding', array_column($exact['totals'], 'code'));

        // 6.6667 × 3 = 20.0001：单价 6.67、行总额 20.01，买家看到的总额 20.00，舍入行为负数（B 站按折扣处理）。
        $negative = PaypalAOrderAmounts::project(
            [['source_product_id' => 42, 'name' => 'Pin', 'sku' => 'P-1', 'quantity' => 3, 'price' => '6.6667']],
            [
                ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '20.0001'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '20.0001'],
            ],
            '20.0001',
            'USD',
            1
        );
        $this->assertSame('20.01', $negative['items'][0]['line_total']);
        $this->assertSame(
            [['sub_total', '20.01'], ['currency_rounding', '-0.01'], ['order_total', '20.00']],
            array_map(static fn (array $row): array => [$row['code'], $row['value']], $negative['totals'])
        );
        $this->assertSame('20.00', $negative['amount']);
        $this->assertTotalsBalance($negative);
    }

    public function test_missing_or_invalid_rate_falls_back_to_one(): void
    {
        foreach ([null, '', '0', 0, '-1.5', 'abc'] as $rate) {
            $this->assertSame(1.0, PaypalAOrderAmounts::rate($rate), var_export($rate, true));
        }
        $this->assertSame(7.1121, PaypalAOrderAmounts::rate('7.11210000'));
    }

    public function test_rounding_row_is_appended_when_order_has_no_order_total_row(): void
    {
        $projection = PaypalAOrderAmounts::project(
            [['source_product_id' => 51, 'name' => 'Pin', 'sku' => 'P-2', 'quantity' => 3, 'price' => '6.3333']],
            [['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '18.9999']],
            '18.9999',
            'USD',
            1
        );

        $this->assertSame([['sub_total', '18.99'], ['currency_rounding', '0.01']], array_map(
            static fn (array $row): array => [$row['code'], $row['value']],
            $projection['totals']
        ));
        $this->assertSame('19.00', $projection['amount']);
    }

    public function test_inconsistent_order_totals_are_not_hidden_by_a_rounding_row(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PaypalAOrderAmounts::project(
            [['source_product_id' => 61, 'name' => 'Shirt', 'sku' => 'SH-1', 'quantity' => 1, 'price' => '10.0000']],
            [
                ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '10.0000'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '50.0000'],
            ],
            '50.0000',
            'USD',
            1
        );
    }

    public function test_invalid_amounts_and_quantities_are_rejected(): void
    {
        foreach ([
            [[['source_product_id' => 1, 'quantity' => 0, 'price' => '1.00']], '0.00'],
            [[['source_product_id' => 1, 'quantity' => 1, 'price' => '-1.00']], '0.00'],
            [[['source_product_id' => 1, 'quantity' => 1, 'price' => '1,00']], '1.00'],
            [[['source_product_id' => 1, 'quantity' => 1, 'price' => '1.00']], '-1.00'],
        ] as $index => [$items, $total]) {
            try {
                PaypalAOrderAmounts::project($items, [], $total, 'USD', 1);
                $this->fail("第 {$index} 组无效金额没有被拒绝。");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_money_minor_unit_helpers_follow_paypal_precision(): void
    {
        $this->assertSame(2, PaypalAMoney::scale('usd'));
        $this->assertSame(0, PaypalAMoney::scale('JPY'));
        $this->assertSame(1899, PaypalAMoney::toMinor('18.9900', 'USD'));
        $this->assertSame(4198, PaypalAMoney::toMinor('4198', 'JPY'));
        $this->assertSame('-0.01', PaypalAMoney::fromMinor(-1, 'USD'));
        $this->assertSame('0.05', PaypalAMoney::fromMinor(5, 'EUR'));
        $this->assertSame('-3', PaypalAMoney::fromMinor(-3, 'HUF'));
    }

    /**
     * 与 B 站实际使用的校验对拍：B 站按同一份明细生成 PayPal breakdown，未知的 currency_rounding 正数计入 handling、负数计入 discount。
     */
    public function test_projection_passes_site_b_item_and_breakdown_validation(): void
    {
        $cases = [
            'EUR' => [
                [['source_product_id' => 11, 'name' => 'Jersey', 'sku' => 'J-1', 'quantity' => 2, 'price' => '20.0000']],
                [
                    ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '40.0000'],
                    ['code' => 'shipping', 'title' => 'Shipping', 'value' => '6.0000'],
                    ['code' => 'customer_discount', 'title' => 'Discount', 'value' => '-2.0000'],
                    ['code' => 'order_total', 'title' => 'Total', 'value' => '44.0000'],
                ],
                '44.0000',
                '0.979',
                // 44 × 0.979 = 43.076 → 43.08；39.16 + 5.87 − 1.96 = 43.07，舍入差 0.01 计入 handling。
                ['item_total' => '39.16', 'shipping' => '5.87', 'handling' => '0.01', 'discount' => '1.96'],
            ],
            'JPY' => [
                [['source_product_id' => 31, 'name' => 'Scarf', 'sku' => 'SC-1', 'quantity' => 2, 'price' => '12.3400']],
                [
                    ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '24.6800'],
                    ['code' => 'shipping', 'title' => 'Shipping', 'value' => '3.3300'],
                    ['code' => 'order_total', 'title' => 'Total', 'value' => '28.0100'],
                ],
                '28.0100',
                '149.87',
                ['item_total' => '3698', 'shipping' => '499', 'handling' => '1'],
            ],
            'USD' => [
                [['source_product_id' => 42, 'name' => 'Pin', 'sku' => 'P-1', 'quantity' => 3, 'price' => '6.6667']],
                [
                    ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '20.0001'],
                    ['code' => 'order_total', 'title' => 'Total', 'value' => '20.0001'],
                ],
                '20.0001',
                '1',
                ['item_total' => '20.01', 'discount' => '0.01'],
            ],
        ];

        foreach ($cases as $currency => [$items, $totals, $orderTotal, $rate, $expectedBreakdown]) {
            $projection = PaypalAOrderAmounts::project($items, $totals, $orderTotal, $currency, $rate);
            $payload    = PaypalBOrderPayload::buildFromData([
                'reference'        => 'REF-' . $currency,
                'order_number'     => 'ORDER-' . $currency,
                'amount'           => $projection['amount'],
                'currency'         => $currency,
                'order_items'      => $projection['items'],
                'order_totals'     => $projection['totals'],
                'shipping_address' => [
                    'required'     => true,
                    'name'         => 'Buyer Name',
                    'address_1'    => '1 Main Street',
                    'address_2'    => '',
                    'city'         => 'San Jose',
                    'zone'         => 'CA',
                    'postal_code'  => '95131',
                    'country_code' => 'US',
                ],
            ], 'https://pay.example.test/return', 'https://pay.example.test/cancel', 'B Merchant LLC');

            $amount = $payload['purchase_units'][0]['amount'];
            $this->assertSame($projection['amount'], $amount['value'], $currency);
            $this->assertSame($currency, $amount['currency_code'], $currency);
            $this->assertSame(
                $expectedBreakdown,
                array_map(static fn (array $money): string => $money['value'], $amount['breakdown']),
                $currency
            );
        }
    }

    /**
     * 与 B 站一致的恒等式：小计 = 行总额之和，小计 + 其余各行 = 总额 = amount。
     */
    private function assertTotalsBalance(array $projection): void
    {
        $currency  = $projection['currency'];
        $toMinor   = static fn (string $value): int => str_starts_with($value, '-')
            ? -PaypalAMoney::toMinor(substr($value, 1), $currency)
            : PaypalAMoney::toMinor($value, $currency);
        $itemTotal = array_sum(array_map(static fn (array $item): int => $toMinor($item['line_total']), $projection['items']));

        $subTotal    = null;
        $orderTotal  = null;
        $adjustments = 0;
        foreach ($projection['totals'] as $row) {
            if ($row['code'] === 'sub_total') {
                $subTotal = $toMinor($row['value']);
            } elseif ($row['code'] === 'order_total') {
                $orderTotal = $toMinor($row['value']);
            } else {
                $adjustments += $toMinor($row['value']);
            }
        }

        $this->assertSame($itemTotal, $subTotal);
        $this->assertSame($toMinor($projection['amount']), $orderTotal);
        $this->assertSame($orderTotal, $subTotal + $adjustments);
    }
}
