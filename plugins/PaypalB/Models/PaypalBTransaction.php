<?php

namespace Plugin\PaypalB\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalBTransaction extends Model
{
    public const SOURCE_BRIDGE = 'bridge';

    public const SOURCE_LOCAL = 'local';

    public const PRESENTATION_REDIRECT = 'redirect';

    public const PRESENTATION_EMBEDDED = 'embedded';

    protected $table = 'paypal_b_transactions';

    protected $fillable = [
        'transaction_id',
        'public_token',
        'account_id',
        'account_fingerprint',
        'reference',
        'order_number',
        'source',
        'presentation',
        'amount',
        'currency',
        'buyer_locale',
        'callback_url',
        'mode',
        'status',
        'paypal_order_id',
        'paypal_capture_id',
        'shop_order_id',
        'approval_url',
        'provider_status',
        'order_snapshot_hash',
        'buyer_snapshot',
        'order_items',
        'paypal_order_items',
        'paypal_item_source',
        'order_totals',
        'shipping_address',
        'fulfillment_evidence',
        'fulfillment_updated_at',
        'buyer_email_hash',
        'buyer_ip_hash',
        'risk_fingerprint',
        'risk_flags',
        'paypal_client_metadata_id',
        'payer_email_hash',
        'payment_exception_status',
        'payment_exception_payload',
        'failure_retryable',
        'failure_class',
        'bridge_request',
        'provider_payload',
        'callback_payload',
        'callback_attempts',
        'callback_status',
        'callback_next_attempt_at',
        'callback_last_sent_at',
        'callback_last_error',
        'expires_at',
        'paid_at',
        'refunded_amount',
        'refund_status',
        'dispute_status',
        'dispute_payload',
    ];

    protected $casts = [
        'bridge_request'            => 'array',
        'buyer_snapshot'            => 'encrypted:array',
        'order_items'               => 'encrypted:array',
        'paypal_order_items'        => 'encrypted:array',
        'order_totals'              => 'array',
        'shipping_address'          => 'encrypted:array',
        'fulfillment_evidence'      => 'encrypted:array',
        'fulfillment_updated_at'    => 'datetime',
        'risk_flags'                => 'array',
        'payment_exception_payload' => 'encrypted:array',
        'failure_retryable'         => 'boolean',
        'provider_payload'          => 'encrypted:array',
        'callback_payload'          => 'array',
        'callback_attempts'         => 'integer',
        'callback_next_attempt_at'  => 'datetime',
        'callback_last_sent_at'     => 'datetime',
        'expires_at'                => 'datetime',
        'paid_at'                   => 'datetime',
        'dispute_payload'           => 'encrypted:array',
    ];

    public function account()
    {
        return $this->belongsTo(PaypalBAccount::class, 'account_id');
    }

    public function isLocal(): bool
    {
        return (string) $this->source === self::SOURCE_LOCAL;
    }

    /**
     * A 站以 iframe 内嵌本站结账页的跨站会话。呈现方式只认落库记录，绝不从请求参数读取，
     * 否则任何页面都能通过改 URL 让本站放开 frame-ancestors。
     */
    public function isEmbedded(): bool
    {
        return ! $this->isLocal() && (string) $this->presentation === self::PRESENTATION_EMBEDDED;
    }

    /**
     * PayPal 已受理捕获但资金尚未确认（审核、电子支票等）。买家已经付过款，
     * 这种会话不能过期、不能重复建单，只能等待 PayPal 给出最终结果。
     */
    public function hasPendingCapture(): bool
    {
        return trim((string) $this->paypal_capture_id) !== '' && (string) $this->status === 'pending';
    }

    /**
     * 本站订单是否存在已捕获、仍等 PayPal 确认的会话：买家已付款，只是资金尚未到账。
     */
    public static function hasLocalPendingCaptureForOrder(int $orderId): bool
    {
        return static::query()
            ->where('source', self::SOURCE_LOCAL)
            ->where('shop_order_id', $orderId)
            ->where('status', 'pending')
            ->whereNotNull('paypal_capture_id')
            ->exists();
    }

    /**
     * 该本站订单是否为跨站交易生成的镜像订单（而非 B 站自营订单）。
     */
    public static function isMirrorOrder(int $orderId): bool
    {
        return static::query()
            ->where('source', '<>', self::SOURCE_LOCAL)
            ->where('shop_order_id', $orderId)
            ->exists();
    }

    /**
     * PayPal 给出的待处理原因，例如 PENDING_REVIEW、ECHECK。
     */
    public function pendingReason(): string
    {
        $reason = (string) data_get($this->payment_exception_payload, 'reason', '');
        if ($reason === '') {
            $reason = (string) data_get($this->provider_payload, 'purchase_units.0.payments.captures.0.status_details.reason', '');
        }

        return trim($reason);
    }

    public function sellerProtectionStatus(): string
    {
        $status = (string) data_get($this->provider_payload, 'purchase_units.0.payments.captures.0.seller_protection.status', '');
        if ($status === '') {
            $status = (string) data_get($this->payment_exception_payload, 'seller_protection', '');
        }

        return trim($status);
    }

    /**
     * PayPal 交易上记录的收货地址。兼容模式由买家在 PayPal 自选，可能与商城订单不同；
     * 只有服务端捕获响应带这份数据，经 Webhook 完成的交易没有。
     */
    public function paypalShippingAddress(): ?array
    {
        return self::shippingAddressFromProvider((array) $this->provider_payload);
    }

    public static function shippingAddressFromProvider(array $provider): ?array
    {
        $shipping = data_get($provider, 'purchase_units.0.shipping');
        $address  = is_array($shipping) ? ($shipping['address'] ?? null) : null;
        if (! is_array($address) || trim((string) ($address['country_code'] ?? '')) === '') {
            return null;
        }

        return [
            'name'         => trim((string) data_get($shipping, 'name.full_name', '')),
            'address_1'    => trim((string) ($address['address_line_1'] ?? '')),
            'address_2'    => trim((string) ($address['address_line_2'] ?? '')),
            'city'         => trim((string) ($address['admin_area_2'] ?? '')),
            'zone'         => trim((string) ($address['admin_area_1'] ?? '')),
            'postal_code'  => trim((string) ($address['postal_code'] ?? '')),
            'country_code' => strtoupper(trim((string) ($address['country_code'] ?? ''))),
        ];
    }

    /**
     * 卖家保障只覆盖按 PayPal 交易地址发出的包裹，地址不一致时后台必须看到提示。
     * 返回 null 表示无法比较：没有 PayPal 地址，或订单本身不需要配送。
     */
    public function paypalShippingMatchesOrder(): ?bool
    {
        $paypal = $this->paypalShippingAddress();
        $order  = (array) $this->shipping_address;
        if ($paypal === null || empty($order['required'])) {
            return null;
        }

        return self::shippingAddressesMatch($paypal, $order);
    }

    /**
     * 只比较街道、邮编和国家：城市与州的写法两边常有大小写和缩写差异，不能作为不一致的依据。
     */
    public static function shippingAddressesMatch(array $paypal, array $order): bool
    {
        $normalize = static fn (mixed $value): string => (string) preg_replace('/[^a-z0-9]+/', '', strtolower((string) $value));

        return $normalize($paypal['address_1'] ?? '')                     === $normalize($order['address_1'] ?? '')
            && $normalize($paypal['postal_code'] ?? '')                   === $normalize($order['postal_code'] ?? '')
            && strtoupper(trim((string) ($paypal['country_code'] ?? ''))) === strtoupper(trim((string) ($order['country_code'] ?? '')));
    }

    public function paypalShippingSummary(): string
    {
        $address = $this->paypalShippingAddress();
        if ($address === null) {
            return '';
        }

        return implode(' ', array_filter(
            [$address['name'], $address['address_1'], $address['address_2'], $address['city'], $address['zone'], $address['postal_code'], $address['country_code']],
            static fn (string $value): bool => $value !== ''
        ));
    }

    /**
     * 写入订单历史的收款备注；PayPal 地址与商城订单不一致时把 PayPal 地址附在后面。
     */
    public function paidComment(string $base): string
    {
        $note = $this->shippingMismatchNote();

        return $note === '' ? $base : $base . ' ' . $note;
    }

    /**
     * 镜像订单备注：只在 PayPal 记录的收货地址与订单收货地址不一致时给出说明，否则为空。
     */
    public function shippingMismatchNote(): string
    {
        if ($this->paypalShippingMatchesOrder() !== false) {
            return '';
        }

        return 'PayPal 记录的收货地址：' . $this->paypalShippingSummary() . '，与订单收货地址不一致，卖家保障以 PayPal 地址为准。';
    }

    public function refunds()
    {
        return $this->hasMany(PaypalBRefund::class, 'transaction_id');
    }
}
