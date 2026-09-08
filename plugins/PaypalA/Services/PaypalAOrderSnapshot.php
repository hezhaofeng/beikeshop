<?php

namespace Plugin\PaypalA\Services;

use Beike\Models\Country;
use Beike\Models\Order;

final class PaypalAOrderSnapshot
{
    public static function fromOrder(Order $order): array
    {
        $order->loadMissing(['orderProducts', 'orderTotals', 'orderShipments']);

        return [
            'buyer'           => self::buyer($order),
            'items'           => $order->orderProducts->map(static fn ($item): array => [
                'source_product_id' => (int) $item->product_id,
                'name'              => trim((string) $item->name),
                'sku'               => trim((string) $item->product_sku),
                'quantity'          => (int) $item->quantity,
                'unit_price'        => self::decimal($item->price),
                'line_total'        => self::multiplyDecimal($item->price, (int) $item->quantity),
            ])->values()->all(),
            'totals'          => $order->orderTotals->map(static fn ($total): array => [
                'code'  => trim((string) $total->code),
                'title' => trim((string) $total->title),
                'value' => self::decimal($total->value),
            ])->values()->all(),
            'shipping_address' => self::shippingAddress($order),
            'fulfillment'      => self::fulfillment($order),
            'order'            => [
                'number'               => (string) $order->number,
                'currency'             => strtoupper((string) $order->currency_code),
                'amount'               => PaypalAMoney::normalize($order->total, (string) $order->currency_code),
                'shipping_required'    => trim((string) $order->shipping_method_code) !== '',
                'shipping_method_code' => trim((string) $order->shipping_method_code),
                'shipping_method_name' => trim((string) $order->shipping_method_name),
                'created_at'           => $order->created_at?->toIso8601String(),
                'updated_at'           => $order->updated_at?->toIso8601String(),
            ],
        ];
    }

    private static function buyer(Order $order): array
    {
        $ip = trim((string) $order->ip);
        if ($ip === '') {
            try {
                $ip = trim((string) request()->getClientIp());
            } catch (\Throwable) {
                // 队列或命令行生成快照时没有 HTTP 请求，保留空值让上层明确报错。
            }
        }

        $userAgent = trim((string) $order->user_agent);
        if ($userAgent === '') {
            try {
                $userAgent = trim((string) request()->userAgent());
            } catch (\Throwable) {
                // 队列或命令行生成快照时没有 HTTP 请求，保留空值让上层明确报错。
            }
        }

        return [
            'name'            => trim((string) ($order->customer_name ?: $order->payment_customer_name)),
            'email'           => trim((string) $order->email),
            'calling_code'    => trim((string) $order->calling_code),
            'telephone'       => trim((string) $order->telephone),
            'ip'              => $ip,
            'user_agent'      => $userAgent,
            'comment'         => trim((string) $order->comment),
            'billing_address' => [
                'name'         => trim((string) $order->payment_customer_name),
                'calling_code' => trim((string) $order->payment_calling_code),
                'telephone'    => trim((string) $order->payment_telephone),
                'address_1'    => trim((string) $order->payment_address_1),
                'address_2'    => trim((string) $order->payment_address_2),
                'city'         => trim((string) $order->payment_city),
                'zone'         => trim((string) $order->payment_zone),
                'postal_code'  => trim((string) $order->payment_zipcode),
                'country'      => trim((string) $order->payment_country),
                'country_code' => self::countryCode((int) $order->payment_country_id),
            ],
        ];
    }

    private static function shippingAddress(Order $order): array
    {
        return [
            'required'     => trim((string) $order->shipping_method_code) !== '',
            'name'         => trim((string) $order->shipping_customer_name),
            'calling_code' => trim((string) $order->shipping_calling_code),
            'telephone'    => trim((string) $order->shipping_telephone),
            'address_1'    => trim((string) $order->shipping_address_1),
            'address_2'    => trim((string) $order->shipping_address_2),
            'city'         => trim((string) $order->shipping_city),
            'zone'         => trim((string) $order->shipping_zone),
            'postal_code'  => trim((string) $order->shipping_zipcode),
            'country'      => trim((string) $order->shipping_country),
            'country_code' => self::countryCode((int) $order->shipping_country_id),
        ];
    }

    private static function fulfillment(Order $order): array
    {
        return [
            'order_status' => (string) $order->status,
            'shipments'    => $order->orderShipments->map(static fn ($shipment): array => [
                'carrier_code' => trim((string) $shipment->express_code),
                'carrier_name' => trim((string) $shipment->express_company),
                'tracking_no'  => trim((string) $shipment->express_number),
                'shipped_at'   => $shipment->created_at?->toIso8601String(),
                'updated_at'   => $shipment->updated_at?->toIso8601String(),
            ])->values()->all(),
            'synced_at'     => now()->toIso8601String(),
        ];
    }

    private static function countryCode(int $countryId): string
    {
        if ($countryId <= 0) {
            return '';
        }

        return strtoupper(trim((string) Country::query()->whereKey($countryId)->value('code')));
    }

    private static function decimal(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,4}))?$/', $value, $matches)) {
            throw new \InvalidArgumentException('订单快照包含无效金额。');
        }

        return $matches[1] . $matches[2] . '.' . str_pad($matches[3] ?? '', 4, '0');
    }

    private static function multiplyDecimal(mixed $value, int $quantity): string
    {
        $normalized           = self::decimal($value);
        $negative             = str_starts_with($normalized, '-');
        [$integer, $fraction] = explode('.', ltrim($normalized, '-'), 2);
        $minor                = (((int) $integer * 10000) + (int) $fraction) * $quantity;
        $result               = intdiv($minor, 10000) . '.' . str_pad((string) ($minor % 10000), 4, '0', STR_PAD_LEFT);

        return $negative ? '-' . $result : $result;
    }
}
