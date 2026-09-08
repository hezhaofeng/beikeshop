<?php

namespace Plugin\PaypalA\Services;

use Beike\Models\Order;
use Beike\Services\StateMachineService;
use Beike\Shop\Services\PaymentService;
use Illuminate\Validation\ValidationException;

class PaypalAPaymentService extends PaymentService
{
    public const CODE = 'paypal_a';

    public const ITEM_SOURCE_A_ORDER = 'a_order';

    public const ITEM_SOURCE_MAPPING = 'mapped';

    public function __construct($order)
    {
        parent::__construct($order);

        if ($this->paymentMethodCode !== self::CODE) {
            throw new \LogicException('订单支付方式不是 PayPal A。');
        }
    }

    public function instruction(array $setting = []): array
    {
        $configuration = self::bridgeConfiguration($setting);

        return [
            'order_number'   => (string) $this->order->number,
            'amount'         => PaypalAMoney::normalize($this->order->total, (string) $this->order->currency_code),
            'currency'       => strtoupper((string) $this->order->currency_code),
            // 插件路由未注册语言前缀；支付入口必须保持与实际 POST 路由一致。
            'start_url'      => shop_route('paypal_a.orders.start', ['number' => $this->order->number], false),
            'guest_email'    => (string) $this->order->email,
            'expires_in'     => $configuration['payment_expiration_minutes'],
            'wallet_enabled' => $configuration['wallet_enabled'],
            'card_enabled'   => $configuration['card_enabled'],
        ];
    }

    public function mobilePaymentData(array $setting = []): array
    {
        $instruction = $this->instruction($setting);

        return [
            'payment_type'   => self::CODE,
            'status'         => 'requires_redirect',
            'start_url'      => $instruction['start_url'],
            'order_number'   => $instruction['order_number'],
            'guest_email'    => $instruction['guest_email'],
            'wallet_enabled' => $instruction['wallet_enabled'],
            'card_enabled'   => $instruction['card_enabled'],
            'methods'        => self::enabledMethods(self::bridgeConfiguration($setting)),
        ];
    }

    public static function assertPaymentMethodEnabled(string $method, array $configuration): void
    {
        if (! in_array($method, self::enabledMethods($configuration), true)) {
            throw ValidationException::withMessages([
                'payment' => trans('PaypalA::common.method_disabled'),
            ]);
        }
    }

    public static function enabledMethods(array $configuration): array
    {
        $methods = [];
        if (! empty($configuration['wallet_enabled'])) {
            $methods[] = 'wallet';
        }
        if (! empty($configuration['card_enabled'])) {
            $methods[] = 'card';
        }

        return $methods;
    }

    public static function assertPayableOrder(Order $order): void
    {
        if ($order->payment_method_code !== self::CODE || $order->status !== StateMachineService::UNPAID) {
            throw ValidationException::withMessages([
                'order' => trans('PaypalA::common.order_not_payable'),
            ]);
        }
    }

    /**
     * 统一读取并校验 A 端的桥接配置，避免前台与接口对配置含义理解不一致。
     */
    public static function bridgeConfiguration(mixed $setting): array
    {
        if (! is_array($setting)) {
            $setting = [];
        }

        $publicUrl = BridgeUrl::baseUrl($setting['public_url'] ?? '', 'public_url');
        $bSiteUrl  = BridgeUrl::baseUrl($setting['b_site_url'] ?? '', 'b_site_url');
        if (BridgeUrl::sameOrigin($publicUrl, $bSiteUrl)) {
            throw ValidationException::withMessages([
                'b_site_url' => 'A 站和 B 站必须使用不同的公网域名或端口。',
            ]);
        }

        $requestSecret  = trim((string) ($setting['request_signing_secret'] ?? ''));
        $callbackSecret = trim((string) ($setting['callback_verification_secret'] ?? ''));
        if (strlen($requestSecret) < 32 || strlen($callbackSecret) < 32) {
            throw ValidationException::withMessages([
                'bridge_secret' => '双向签名密钥都必须至少 32 位。',
            ]);
        }
        if (hash_equals($requestSecret, $callbackSecret)) {
            throw ValidationException::withMessages([
                'bridge_secret' => '请求签名密钥和回调验签密钥不能复用。',
            ]);
        }

        $expires = (int) ($setting['payment_expiration_minutes'] ?? 30);
        $timeout = (int) ($setting['request_timeout_seconds'] ?? 10);
        if ($expires < 5 || $expires > 120 || $timeout < 3 || $timeout > 30) {
            throw ValidationException::withMessages([
                'bridge_setting' => '支付会话有效期或请求超时配置不在允许范围内。',
            ]);
        }

        $walletEnabled = self::booleanValue($setting['wallet_enabled'] ?? null, true);
        $cardEnabled   = self::booleanValue($setting['card_enabled'] ?? null, false);
        if (! $walletEnabled && ! $cardEnabled) {
            throw ValidationException::withMessages([
                'payment_method' => 'PayPal 钱包付款和信用卡付款至少启用一项。',
            ]);
        }
        $itemSource = self::itemSource($setting['bridge_item_source'] ?? null);

        return [
            'public_url'                   => $publicUrl,
            'b_site_url'                   => $bSiteUrl,
            'request_signing_secret'       => $requestSecret,
            'callback_verification_secret' => $callbackSecret,
            'payment_expiration_minutes'   => $expires,
            'request_timeout_seconds'      => $timeout,
            'wallet_enabled'               => $walletEnabled,
            'card_enabled'                 => $cardEnabled,
            'bridge_item_source'           => $itemSource,
        ];
    }

    private static function itemSource(mixed $value): string
    {
        $source = strtolower(trim((string) ($value ?: self::ITEM_SOURCE_A_ORDER)));
        if (! in_array($source, [self::ITEM_SOURCE_A_ORDER, self::ITEM_SOURCE_MAPPING], true)) {
            throw ValidationException::withMessages([
                'bridge_item_source' => '订单商品明细模式必须为原商品明细或映射商品明细。',
            ]);
        }

        return $source;
    }

    private static function booleanValue(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? ((int) $value === 1);
    }
}
