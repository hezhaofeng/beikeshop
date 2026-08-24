<?php

namespace Plugin\AdTracking\Services;

use Beike\Models\Order;

class TrackingEventService
{
    /**
     * 根据商品详情资源生成 ViewContent 标准事件。
     */
    public static function product(array $product): array
    {
        $sku       = is_array($product['skus'][0] ?? null) ? $product['skus'][0] : [];
        $price     = (float) ($sku['price'] ?? 0);
        $productId = (string) ($product['id'] ?? $sku['product_id'] ?? '');
        $skuId     = (string) ($sku['sku'] ?? $sku['id'] ?? '');
        $brand     = $product['brand_name']    ?? null;
        $category  = $product['category_name'] ?? null;

        return self::event('ViewContent', [
            'content_type'     => 'product',
            'content_ids'      => array_values(array_filter([$productId])),
            'content_name'     => (string) ($product['name'] ?? ''),
            'content_category' => $category,
            'item_id'          => $skuId,
            'item_variant'     => $skuId,
            'item_brand'       => $brand,
            'availability'     => array_key_exists('quantity', $sku)
                ? ((int) $sku['quantity'] > 0 ? 'in_stock' : 'out_of_stock')
                : null,
            'value'        => $price,
            'currency'     => current_currency_code(),
            'items'        => [[
                'item_id'       => $skuId,
                'item_name'     => (string) ($product['name'] ?? ''),
                'item_brand'    => $brand,
                'item_category' => $category,
                'item_variant'  => $skuId,
                'price'         => $price,
                'quantity'      => 1,
            ]],
        ]);
    }

    /**
     * 根据结账页数据生成 InitiateCheckout 标准事件。
     */
    public static function checkout(array $data): array
    {
        $carts = data_get($data, 'carts.carts', []);
        $items = collect($carts)->map(function ($cart): array {
            $itemId   = (string) ($cart['product_sku'] ?? $cart['sku'] ?? $cart['sku_id'] ?? '');
            $price    = (float) ($cart['price'] ?? 0);
            $quantity = (int) ($cart['quantity'] ?? 1);

            return [
                'item_id'      => $itemId,
                'product_id'   => (string) ($cart['product_id'] ?? ''),
                'item_name'    => (string) ($cart['name'] ?? ''),
                'item_variant' => $itemId,
                'price'        => $price,
                'quantity'     => $quantity,
                'line_total'   => (float) ($cart['subtotal'] ?? $price * $quantity),
            ];
        })->values()->all();

        $total = collect($data['totals'] ?? [])->last();

        return self::event('InitiateCheckout', [
            'content_type'     => 'product',
            'content_ids'      => array_values(array_filter(array_column($items, 'item_id'))),
            'product_ids'      => array_values(array_filter(array_column($items, 'product_id'))),
            'num_items'        => array_sum(array_column($items, 'quantity')),
            'num_unique_items' => count($items),
            'value'            => (float) ($total['amount'] ?? 0),
            'currency'         => current_currency_code(),
            'checkout_step'    => 'checkout',
            'items'            => $items,
        ]);
    }

    /**
     * 根据订单生成 Purchase 标准事件。
     */
    public static function order(Order $order): array
    {
        return self::event('Purchase', ConversionService::purchaseData($order));
    }

    /**
     * 统一包装事件名称和参数，供 Blade 页面与前端平台脚本复用。
     */
    public static function event(string $name, array $params = []): array
    {
        return [
            'name'   => $name,
            'params' => array_filter($params, static fn ($value): bool => $value !== null),
        ];
    }
}
