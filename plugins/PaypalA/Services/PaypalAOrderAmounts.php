<?php

namespace Plugin\PaypalA\Services;

use Beike\Models\Order;

/**
 * 订单金额的买家币种投影，是本插件唯一的金额换算入口。
 *
 * BeikeShop 的 orders.total、order_products.price、order_totals.value 都按系统基础货币保存（最多 4 位小数）；
 * 买家看到并应支付的是 orders.currency_code 币种、按下单时汇率 orders.currency_value 换算后的金额。
 * 发给 B 站的商品行与费用明细、写入 paypal_a_transactions 的金额、回调与拉取核验的金额都必须来自这里，
 * 否则 B 站逐行核对或 A 站回调核验会因口径不同而失败。
 *
 * 规则：
 * - 商品单价 = round(price × rate, 精度)，行总额 = 单价 × 数量（整数最小单位，精确）；
 * - sub_total 由行总额重新累加，不单独换算；
 * - 其余费用行各自 round(value × rate, 精度)；
 * - order_total = round(orders.total × rate, 精度)，即买家在商城看到的应付金额；
 * - 分项舍入与总额不一致时追加一行 currency_rounding，差额可正可负，B 站按手续费或折扣处理。
 */
final class PaypalAOrderAmounts
{
    public const ROUNDING_CODE = 'currency_rounding';

    public const ROUNDING_TITLE = 'Rounding';

    /**
     * @return array{currency:string, rate:float, items:array<int, array>, totals:array<int, array>, amount:string}
     */
    public static function fromOrder(Order $order): array
    {
        $order->loadMissing(['orderProducts', 'orderTotals']);

        return self::project(
            $order->orderProducts->map(static fn ($item): array => [
                'source_product_id' => (int) $item->product_id,
                'name'              => trim((string) $item->name),
                'sku'               => trim((string) $item->product_sku),
                'quantity'          => (int) $item->quantity,
                'price'             => $item->price,
            ])->values()->all(),
            $order->orderTotals->map(static fn ($total): array => [
                'code'  => trim((string) $total->code),
                'title' => trim((string) $total->title),
                'value' => $total->value,
            ])->values()->all(),
            $order->total,
            (string) $order->currency_code,
            $order->currency_value
        );
    }

    /**
     * 买家应付总额（订单币种）。建会话、落交易记录和支付页展示都用它。
     */
    public static function orderAmount(Order $order): string
    {
        $currency = self::currency((string) $order->currency_code);
        $minor    = self::convertToMinor($order->total, self::rate($order->currency_value), $currency, 'order_total');
        if ($minor < 0) {
            throw new \InvalidArgumentException('订单总额不能为负数。');
        }

        return PaypalAMoney::fromMinor($minor, $currency);
    }

    /**
     * 纯函数：输入基础货币的商品行（price、quantity 及其余透传字段）和费用行，输出订单币种的投影。
     *
     * @param array<int, array> $items
     * @param array<int, array> $totals
     */
    public static function project(array $items, array $totals, mixed $orderTotal, string $currency, mixed $currencyValue): array
    {
        $currency = self::currency($currency);
        $rate     = self::rate($currencyValue);

        $projectedItems = [];
        $itemTotal      = 0;
        $quantityTotal  = 0;
        foreach (array_values($items) as $index => $item) {
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($quantity < 1) {
                throw new \InvalidArgumentException("第 {$index} 个订单商品数量无效。");
            }
            $unitMinor = self::convertToMinor($item['price'] ?? '', $rate, $currency, "items.{$index}.price");
            if ($unitMinor < 0) {
                throw new \InvalidArgumentException("第 {$index} 个订单商品单价不能为负数。");
            }
            if ($unitMinor > intdiv(PHP_INT_MAX, $quantity)) {
                throw new \InvalidArgumentException("第 {$index} 个订单商品金额超出可计算范围。");
            }

            $lineMinor = $unitMinor * $quantity;
            $itemTotal += $lineMinor;
            $quantityTotal += $quantity;

            unset($item['price']);
            $projectedItems[] = array_merge($item, [
                'unit_price' => PaypalAMoney::fromMinor($unitMinor, $currency),
                'line_total' => PaypalAMoney::fromMinor($lineMinor, $currency),
            ]);
        }

        $orderTotalMinor = self::convertToMinor($orderTotal, $rate, $currency, 'order_total');
        if ($orderTotalMinor < 0) {
            throw new \InvalidArgumentException('订单总额不能为负数。');
        }

        $rows        = [];
        $adjustments = 0;
        foreach (array_values($totals) as $index => $total) {
            $code  = trim((string) ($total['code'] ?? ''));
            $title = trim((string) ($total['title'] ?? ''));
            $kind  = strtolower($code);
            if ($kind === 'sub_total') {
                $minor = $itemTotal;
            } elseif ($kind === 'order_total') {
                $minor = $orderTotalMinor;
            } else {
                $minor = self::convertToMinor($total['value'] ?? '', $rate, $currency, "totals.{$index}.value");
                $adjustments += $minor;
            }
            $rows[] = ['code' => $code, 'title' => $title, 'minor' => $minor, 'kind' => $kind];
        }

        $difference = $orderTotalMinor - ($itemTotal + $adjustments);
        if ($difference !== 0) {
            // 每个换算值的舍入误差不超过半个最小单位，差额超过这个量级说明订单金额明细本身就不一致，不能用舍入行掩盖。
            $tolerance = $quantityTotal + count($rows) + 1;
            if (abs($difference) > $tolerance) {
                throw new \InvalidArgumentException('订单金额明细与订单总额不一致，无法生成支付明细。');
            }

            $rounding = ['code' => self::ROUNDING_CODE, 'title' => self::ROUNDING_TITLE, 'minor' => $difference, 'kind' => self::ROUNDING_CODE];
            $position = array_search('order_total', array_column($rows, 'kind'), true);
            if ($position === false) {
                $rows[] = $rounding;
            } else {
                array_splice($rows, (int) $position, 0, [$rounding]);
            }
        }

        return [
            'currency' => $currency,
            'rate'     => $rate,
            'items'    => $projectedItems,
            'totals'   => array_map(static fn (array $row): array => [
                'code'  => $row['code'],
                'title' => $row['title'],
                'value' => PaypalAMoney::fromMinor($row['minor'], $currency),
            ], $rows),
            'amount'   => PaypalAMoney::fromMinor($orderTotalMinor, $currency),
        ];
    }

    /**
     * 订单汇率。缺失、非数字或不大于零时按 1 处理（即订单币种就是基础货币）。
     */
    public static function rate(mixed $currencyValue): float
    {
        $rate = is_numeric($currencyValue) ? (float) $currencyValue : 0.0;

        return is_finite($rate) && $rate > 0 ? $rate : 1.0;
    }

    private static function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('订单币种无效。');
        }

        return $currency;
    }

    /**
     * 与商城 CurrencyService::format 相同的换算方式（先乘汇率再按精度四舍五入），保证与买家看到的金额一致。
     */
    private static function convertToMinor(mixed $value, float $rate, string $currency, string $field): int
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } elseif (is_string($value) && preg_match('/^-?\d{1,12}(?:\.\d+)?$/', trim($value))) {
            $number = (float) trim($value);
        } else {
            throw new \InvalidArgumentException("订单金额 {$field} 格式无效。");
        }
        if (! is_finite($number) || abs($number) >= 1e12) {
            throw new \InvalidArgumentException("订单金额 {$field} 超出可计算范围。");
        }

        $scale     = PaypalAMoney::scale($currency);
        $converted = round($number * $rate, $scale);

        return (int) round($converted * (10 ** $scale));
    }
}
