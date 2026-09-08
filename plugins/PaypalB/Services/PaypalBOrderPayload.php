<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Validation\ValidationException;
use Plugin\PaypalB\Models\PaypalBTransaction;

final class PaypalBOrderPayload
{
    public static function build(
        PaypalBTransaction $transaction,
        string $returnUrl,
        string $cancelUrl,
        string $brandName,
        array $orderItems = null
    ): array {
        return self::buildFromData([
            'reference'        => (string) $transaction->reference,
            'order_number'     => (string) $transaction->order_number,
            'amount'           => (string) $transaction->amount,
            'currency'         => (string) $transaction->currency,
            'order_items'      => $orderItems ?? (array) $transaction->order_items,
            'order_totals'     => (array) $transaction->order_totals,
            'shipping_address' => (array) $transaction->shipping_address,
        ], $returnUrl, $cancelUrl, $brandName);
    }

    public static function buildFromData(array $data, string $returnUrl, string $cancelUrl, string $brandName): array
    {
        $currency     = strtoupper((string) $data['currency']);
        $shipping     = (array) $data['shipping_address'];
        $required     = ! empty($shipping['required']);
        $purchaseUnit = [
            'reference_id' => (string) $data['reference'],
            'invoice_id'   => (string) $data['reference'],
            'custom_id'    => self::limit((string) $data['order_number'], 127),
            'description'  => self::description((array) $data['order_items']),
            'amount'       => [
                'currency_code' => $currency,
                'value'         => PaypalBMoney::normalize($data['amount'], $currency),
            ],
        ];

        $itemData = self::itemsAndBreakdown(
            (array) $data['order_items'],
            (array) $data['order_totals'],
            (string) $data['amount'],
            $currency,
            $required
        );
        $purchaseUnit['items']               = $itemData['items'];
        $purchaseUnit['amount']['breakdown'] = $itemData['breakdown'];

        if ($required) {
            $purchaseUnit['shipping'] = [
                'name'    => ['full_name' => self::limit((string) ($shipping['name'] ?? ''), 300)],
                'address' => array_filter([
                    'address_line_1' => self::limit((string) ($shipping['address_1'] ?? ''), 300),
                    'address_line_2' => self::limit((string) ($shipping['address_2'] ?? ''), 300),
                    'admin_area_2'   => self::limit((string) ($shipping['city'] ?? ''), 120),
                    'admin_area_1'   => self::limit((string) ($shipping['zone'] ?? ''), 300),
                    'postal_code'    => self::limit((string) ($shipping['postal_code'] ?? ''), 60),
                    'country_code'   => strtoupper((string) ($shipping['country_code'] ?? '')),
                ], static fn (string $value): bool => $value !== ''),
            ];
        }

        return [
            'intent'              => 'CAPTURE',
            'purchase_units'      => [$purchaseUnit],
            'application_context' => [
                'brand_name'          => self::limit($brandName, 127),
                'shipping_preference' => $required ? 'SET_PROVIDED_ADDRESS' : 'NO_SHIPPING',
                'user_action'         => 'PAY_NOW',
                'return_url'          => $returnUrl,
                'cancel_url'          => $cancelUrl,
            ],
        ];
    }

    private static function itemsAndBreakdown(array $items, array $totals, string $amount, string $currency, bool $physical): array
    {
        if ($items === []) {
            self::invalid('order_items', 'PayPal 订单必须包含真实商品明细。');
        }

        $paypalItems = [];
        $itemTotal   = 0;
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                self::invalid("order_items.{$index}", 'PayPal 商品明细格式无效。');
            }
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($quantity < 1) {
                self::invalid("order_items.{$index}.quantity", '商品数量必须大于零。');
            }

            $unitValue = PaypalBMoney::normalize($item['unit_price'] ?? '', $currency, "order_items.{$index}.unit_price");
            $lineValue = trim((string) ($item['line_total'] ?? ''));
            if ($lineValue === '') {
                self::invalid("order_items.{$index}.line_total", 'PayPal 商品明细缺少行总额。');
            }
            $expectedLineValue = PaypalBMoney::multiply(
                $unitValue,
                $quantity,
                $currency,
                "order_items.{$index}.unit_price"
            );
            if (! PaypalBMoney::equal($expectedLineValue, $lineValue, $currency)) {
                self::invalid("order_items.{$index}.line_total", '商品行总额不等于单价乘以数量。');
            }

            $lineMinor     = PaypalBMoney::toMinor($lineValue, $currency, "order_items.{$index}.line_total");
            $itemTotal     = self::addMinor($itemTotal, $lineMinor, 'order_items');
            $paypalItems[] = [
                'name'        => self::limit((string) ($item['name'] ?? ''), 127),
                'sku'         => self::limit((string) ($item['sku'] ?? ''), 127),
                'quantity'    => (string) $quantity,
                'category'    => $physical ? 'PHYSICAL_GOODS' : 'DIGITAL_GOODS',
                'unit_amount' => ['currency_code' => $currency, 'value' => $unitValue],
            ];
        }

        $shipping     = 0;
        $tax          = 0;
        $handling     = 0;
        $discount     = 0;
        $subTotal     = null;
        $orderTotal   = null;
        $adjustments  = 0;
        foreach ($totals as $index => $total) {
            if (! is_array($total)) {
                self::invalid("order_totals.{$index}", 'PayPal 订单金额明细格式无效。');
            }
            $code  = strtolower(trim((string) ($total['code'] ?? '')));
            $minor = PaypalBMoney::signedToMinor(
                $total['value'] ?? '',
                $currency,
                "order_totals.{$index}.value"
            );
            if ($code === 'sub_total') {
                if ($subTotal !== null || $minor < 0) {
                    self::invalid("order_totals.{$index}", 'PayPal 订单必须包含唯一且非负的商品小计。');
                }
                $subTotal = $minor;

                continue;
            }
            if ($code === 'order_total') {
                if ($orderTotal !== null || $minor < 0) {
                    self::invalid("order_totals.{$index}", 'PayPal 订单必须包含唯一且非负的订单总额。');
                }
                $orderTotal = $minor;

                continue;
            }

            $adjustments = self::addMinor($adjustments, $minor, 'order_totals');
            if ($code === 'shipping' && $minor >= 0) {
                $shipping = self::addMinor($shipping, $minor, 'order_totals.shipping');
            } elseif ($code === 'tax' && $minor >= 0) {
                $tax = self::addMinor($tax, $minor, 'order_totals.tax');
            } elseif ($minor < 0) {
                $discount = self::addMinor($discount, -$minor, 'order_totals.discount');
            } else {
                $handling = self::addMinor($handling, $minor, 'order_totals.handling');
            }
        }

        if ($subTotal === null || $orderTotal === null || $subTotal !== $itemTotal) {
            self::invalid('order_totals', '商品明细合计与订单金额小计不一致。');
        }
        $expectedAmount = PaypalBMoney::toMinor($amount, $currency);
        if (self::addMinor($itemTotal, $adjustments, 'order_totals') !== $orderTotal
            || $orderTotal                                           !== $expectedAmount
            || $expectedAmount <= 0) {
            self::invalid('amount', 'PayPal 商品、费用、折扣和订单总额不一致。');
        }

        $breakdown = [
            'item_total' => self::money($itemTotal, $currency),
        ];
        foreach (['shipping' => $shipping, 'tax_total' => $tax, 'handling' => $handling, 'discount' => $discount] as $key => $minor) {
            if ($minor > 0) {
                $breakdown[$key] = self::money($minor, $currency);
            }
        }

        return ['items' => $paypalItems, 'breakdown' => $breakdown];
    }

    private static function description(array $items): string
    {
        $names = array_values(array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['name'] ?? '')),
            $items
        )));

        return self::limit(implode('; ', $names) ?: 'Order payment', 127);
    }

    private static function money(int $minor, string $currency): array
    {
        return [
            'currency_code' => $currency,
            'value'         => PaypalBMoney::fromMinor($minor, $currency),
        ];
    }

    private static function addMinor(int $first, int $second, string $field): int
    {
        if (($second > 0 && $first > PHP_INT_MAX - $second)
            || ($second < 0 && $first < PHP_INT_MIN - $second)) {
            self::invalid($field, '订单金额超出可计算范围。');
        }

        return $first + $second;
    }

    private static function limit(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
