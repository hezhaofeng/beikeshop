<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Validation\ValidationException;
use Plugin\PaypalB\Models\PaypalBTransaction;

final class PaypalBOrderPayload
{
    /**
     * PaypalB 默认格式：订单号、支付引用、商品行、金额拆分、收货地址与收款主体名称全部提交。
     */
    public const MODE_DETAILED = 'detailed';

    /**
     * 兼容模式：只提交订单号、金额和商品名称，不提交商城收货地址；实物订单由买家在 PayPal 页面自选地址。
     */
    public const MODE_NATIVE = 'native';

    private const DESCRIPTION_LIMIT = 127;

    /**
     * @param array{mode?: string} $options
     */
    public static function build(
        PaypalBTransaction $transaction,
        string $returnUrl,
        string $cancelUrl,
        string $brandName,
        array $orderItems = null,
        array $options = []
    ): array {
        return self::buildFromData([
            'reference'        => (string) $transaction->reference,
            'order_number'     => (string) $transaction->order_number,
            'amount'           => (string) $transaction->amount,
            'currency'         => (string) $transaction->currency,
            'order_items'      => $orderItems ?? (array) $transaction->order_items,
            'order_totals'     => (array) $transaction->order_totals,
            'shipping_address' => (array) $transaction->shipping_address,
        ], $returnUrl, $cancelUrl, $brandName, $options);
    }

    /**
     * @param array{mode?: string} $options
     */
    public static function buildFromData(array $data, string $returnUrl, string $cancelUrl, string $brandName, array $options = []): array
    {
        $mode     = self::normalizeMode($options['mode'] ?? self::MODE_DETAILED);
        $currency = strtoupper((string) $data['currency']);
        $shipping = (array) $data['shipping_address'];
        $required = ! empty($shipping['required']);
        $amount   = PaypalBMoney::normalize($data['amount'], $currency);

        // 两种格式都先核对商品行、费用与总额：兼容模式虽不提交明细，收款金额同样必须与订单一致。
        $itemData = self::itemsAndBreakdown(
            (array) $data['order_items'],
            (array) $data['order_totals'],
            (string) $data['amount'],
            $currency,
            $required
        );

        if ($mode === self::MODE_NATIVE) {
            $orderNumber = self::limit((string) $data['order_number'], 127);

            return [
                'intent'         => 'CAPTURE',
                'purchase_units' => [[
                    // 订单号放在发票编号里，PayPal 交易详情和买家账单都能看到；custom_id 供报表与 API 对账。
                    'invoice_id'  => $orderNumber,
                    'custom_id'   => $orderNumber,
                    'description' => self::description((array) $data['order_items']),
                    'amount'      => [
                        'currency_code' => $currency,
                        'value'         => $amount,
                    ],
                ]],
                'application_context' => [
                    // 不提交商城地址：实物订单让买家在 PayPal 从自己的地址簿选择，PayPal 会把所选地址记在交易上，
                    // 卖家保障按该地址发货才有效；虚拟订单不需要地址。
                    'shipping_preference' => $required ? 'GET_FROM_FILE' : 'NO_SHIPPING',
                    // 回跳地址只用于浏览器不支持弹窗时的整页跳转回退，PayPal 页面与交易详情不会展示。
                    'return_url'          => $returnUrl,
                    'cancel_url'          => $cancelUrl,
                ],
            ];
        }

        $purchaseUnit = [
            'reference_id' => (string) $data['reference'],
            'invoice_id'   => (string) $data['reference'],
            'custom_id'    => self::limit((string) $data['order_number'], 127),
            'description'  => self::description((array) $data['order_items']),
            'amount'       => [
                'currency_code' => $currency,
                'value'         => $amount,
                'breakdown'     => $itemData['breakdown'],
            ],
            'items' => $itemData['items'],
        ];

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

    /**
     * 会话创建时记录的格式优先于当前配置：同账号重试沿用同一个 PayPal-Request-Id，
     * 请求体不能因为管理员中途切换开关而改变。
     */
    public static function modeFor(PaypalBTransaction $transaction, array $configuration): string
    {
        $stored = trim((string) data_get($transaction->bridge_request, 'paypal_payload_mode', ''));

        return self::normalizeMode($stored !== '' ? $stored : ($configuration['paypal_payload_mode'] ?? self::MODE_DETAILED));
    }

    public static function normalizeMode(mixed $mode): string
    {
        return strtolower(trim((string) $mode)) === self::MODE_NATIVE ? self::MODE_NATIVE : self::MODE_DETAILED;
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

        $shipping    = 0;
        $tax         = 0;
        $handling    = 0;
        $discount    = 0;
        $subTotal    = null;
        $orderTotal  = null;
        $adjustments = 0;
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

        return self::limit(implode('; ', $names) ?: 'Order payment', self::DESCRIPTION_LIMIT);
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
