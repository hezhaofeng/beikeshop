<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Validation\ValidationException;

final class PaypalBConfiguration
{
    /**
     * 镜像订单创建时机：收款成功后（历史默认）或 A 站建会话时（订单先以待支付出现在 B 站后台）。
     */
    public const MIRROR_ON_COMPLETED = 'on_completed';

    public const MIRROR_ON_SESSION = 'on_session';

    public static function all(): array
    {
        return self::from(plugin_setting('paypal_b', []));
    }

    public static function from(mixed $rawSetting): array
    {
        $setting = $rawSetting;
        if (! is_array($setting)) {
            $setting = [];
        }

        $bSiteUrl       = BridgeUrl::baseUrl($setting['public_url'] ?? '', 'public_url');
        $bridgeEnabled  = self::bridgeEnabled($setting);
        $aSiteUrl       = '';
        $requestSecret  = '';
        $callbackSecret = '';
        if ($bridgeEnabled) {
            $aSiteUrl = BridgeUrl::baseUrl($setting['a_site_url'] ?? '', 'a_site_url');
            if (self::sameOrigin($aSiteUrl, $bSiteUrl)) {
                throw ValidationException::withMessages(['a_site_url' => 'A 站和 B 站必须使用不同的公网域名或端口。']);
            }

            $requestSecret  = trim((string) ($setting['a_request_verification_secret'] ?? ''));
            $callbackSecret = trim((string) ($setting['a_callback_signing_secret'] ?? ''));
            if (strlen($requestSecret) < 32 || strlen($callbackSecret) < 32) {
                throw ValidationException::withMessages(['bridge_secret' => '启用 A/B 桥接时，两组签名密钥都必须至少 32 位。']);
            }
            if (hash_equals($requestSecret, $callbackSecret)) {
                throw ValidationException::withMessages(['bridge_secret' => '请求验签密钥和回调签名密钥不能复用。']);
            }
        }

        $timeout  = (int) ($setting['request_timeout_seconds'] ?? 10);
        $cooldown = (int) ($setting['account_cooldown_minutes'] ?? 10);
        if ($timeout < 3 || $timeout > 30 || $cooldown < 1 || $cooldown > 1440) {
            throw ValidationException::withMessages(['bridge_setting' => '网络超时或账号冷却时间配置不在允许范围内。']);
        }

        $cardEnabled   = self::booleanValue($setting['card_enabled'] ?? null, false);
        $walletEnabled = self::booleanValue($setting['wallet_enabled'] ?? null, true);
        if (! $cardEnabled && ! $walletEnabled) {
            throw ValidationException::withMessages(['payment_method' => 'PayPal 钱包收款和信用卡收款至少启用一项。']);
        }
        $nativePayload = self::booleanValue($setting['native_payload_mode'] ?? null, false);
        // 内嵌只对跨站会话有意义：没有 A 站就没有可信的父页面 origin 可以放进 frame-ancestors。
        $embeddedEnabled = $bridgeEnabled && self::booleanValue($setting['embedded_enabled'] ?? null, false);

        $mirrorTiming = strtolower(trim((string) (($setting['mirror_order_timing'] ?? null) ?: self::MIRROR_ON_COMPLETED)));
        if (! in_array($mirrorTiming, [self::MIRROR_ON_COMPLETED, self::MIRROR_ON_SESSION], true)) {
            throw ValidationException::withMessages(['mirror_order_timing' => '镜像订单创建时机必须为收款后或建会话时。']);
        }

        $merchantDisplayName = trim((string) ($setting['merchant_display_name'] ?? ''));
        $supportEmail        = trim((string) ($setting['customer_service_email'] ?? ''));
        $supportPhone        = trim((string) ($setting['customer_service_phone'] ?? ''));
        if ($merchantDisplayName === '' || mb_strlen($merchantDisplayName) > 127) {
            throw ValidationException::withMessages(['merchant_display_name' => 'B 站收款主体名称不能为空且不能超过 127 个字符。']);
        }
        if (! filter_var($supportEmail, FILTER_VALIDATE_EMAIL) || strlen($supportEmail) > 255) {
            throw ValidationException::withMessages(['customer_service_email' => 'B 站客服邮箱格式无效。']);
        }
        if (mb_strlen($supportPhone) > 64) {
            throw ValidationException::withMessages(['customer_service_phone' => 'B 站客服电话不能超过 64 个字符。']);
        }

        $refundPolicyUrl  = self::policyUrl($setting['refund_policy_url'] ?? '', $bSiteUrl, 'refund_policy_url');
        $privacyPolicyUrl = self::policyUrl($setting['privacy_policy_url'] ?? '', $bSiteUrl, 'privacy_policy_url');
        $termsUrl         = self::policyUrl($setting['terms_url'] ?? '', $bSiteUrl, 'terms_url');

        return [
            'public_url'                      => $bSiteUrl,
            'bridge_enabled'                  => $bridgeEnabled,
            'a_site_url'                      => $aSiteUrl,
            'a_request_verification_secret'   => $requestSecret,
            'a_callback_signing_secret'       => $callbackSecret,
            // 跨站会话沿用既有回调密钥，避免升级时使进行中的 capture token 失效；
            // 独立 B 站不依赖 A 站密钥，改用 Laravel APP_KEY 派生的稳定服务端密钥。
            'transaction_signing_secret'      => $bridgeEnabled
                ? $callbackSecret
                : self::localTransactionSigningSecret(),
            'audit_signing_secret'            => $bridgeEnabled
                ? $requestSecret
                : self::localTransactionSigningSecret(),
            'request_timeout_seconds'         => $timeout,
            'account_cooldown_minutes'        => $cooldown,
            'sandbox_mode'                    => self::booleanValue($setting['sandbox_mode'] ?? null, true),
            'card_enabled'                    => $cardEnabled,
            'wallet_enabled'                  => $walletEnabled,
            // 全局值保留给旧代码；实际页面形态按会话的 presentation 决定。
            'checkout_mode'                   => 'hosted',
            'embedded_enabled'                => $embeddedEnabled,
            // frame-ancestors 只接受 origin，不能带路径；直接由 a_site_url 派生，不提供自定义白名单。
            'a_site_origin'                   => $embeddedEnabled ? self::origin($aSiteUrl) : '',
            'mirror_order_timing'             => $mirrorTiming,
            // 镜像订单默认不扣 B 站库存：B 站目录只是展示与收款载体，实际发货在 A 站。
            'mirror_order_deduct_stock'       => self::booleanValue($setting['mirror_order_deduct_stock'] ?? null, false),
            // 兼容模式：向 PayPal 建单只提交订单号、金额和商品名称，不提交也不让买家选择收货地址；只影响之后新建的会话。
            'native_payload_mode'             => $nativePayload,
            'paypal_payload_mode'             => $nativePayload ? PaypalBOrderPayload::MODE_NATIVE : PaypalBOrderPayload::MODE_DETAILED,
            'merchant_display_name'           => $merchantDisplayName,
            'customer_service_email'          => $supportEmail,
            'customer_service_phone'          => $supportPhone,
            'refund_policy_url'               => $refundPolicyUrl,
            'privacy_policy_url'              => $privacyPolicyUrl,
            'terms_url'                       => $termsUrl,
        ];
    }

    /**
     * 兼容已有完整 A/B 配置：旧版本没有开关时，三项桥接字段都已填写便自动保持开启。
     * 新的独立 B 站可完全留空这些字段，不会再被迫填写一个不存在的 A 站。
     */
    private static function bridgeEnabled(array $setting): bool
    {
        if (array_key_exists('bridge_enabled', $setting)) {
            return self::booleanValue($setting['bridge_enabled'], false);
        }

        return trim((string) ($setting['a_site_url'] ?? ''))                    !== ''
            || trim((string) ($setting['a_request_verification_secret'] ?? '')) !== ''
            || trim((string) ($setting['a_callback_signing_secret'] ?? ''))     !== '';
    }

    private static function localTransactionSigningSecret(): string
    {
        $appKey = '';

        try {
            if (app()->bound('config')) {
                $appKey = trim((string) config('app.key', ''));
            }
        } catch (\Throwable) {
            // 窄单元测试不会完整引导配置容器；运行时仍必须有 APP_KEY。
        }
        if ($appKey === '') {
            // 配置缓存启用后 env() 读不到 .env，所以先取 config；未引导容器时才退回环境变量。
            $appKey = trim((string) env('APP_KEY', ''));
        }
        if ($appKey === '') {
            // 这里只能发生在非运行时的窄测试。正式 Laravel 请求在 bootstrap 阶段已要求 APP_KEY。
            $appKey = 'paypal-b-unit-test-fallback';
        }

        return hash_hmac('sha256', 'paypal_b:local_transaction_signing', $appKey);
    }

    public static function booleanValue(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? ((int) $value === 1);
    }

    private static function sameOrigin(string $first, string $second): bool
    {
        $firstParts  = parse_url($first);
        $secondParts = parse_url($second);
        if (! is_array($firstParts) || ! is_array($secondParts)) {
            return false;
        }

        return strtolower((string) ($firstParts['scheme'] ?? '')) === strtolower((string) ($secondParts['scheme'] ?? ''))
            && strtolower((string) ($firstParts['host'] ?? ''))   === strtolower((string) ($secondParts['host'] ?? ''))
            && self::port($firstParts)                            === self::port($secondParts);
    }

    /**
     * 浏览器意义上的 origin：协议 + 主机 + 显式端口。默认端口不写，否则与浏览器比较时不相等。
     */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $origin = $scheme . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port']) && (int) $parts['port'] !== ($scheme === 'http' ? 80 : 443)) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }

    /**
     * 责任条款地址为选填：留空时支付页回退到插件内置政策页；填写后必须是 B 站同域地址。
     */
    private static function policyUrl(mixed $value, string $bSiteUrl, string $field): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }

        $parts       = parse_url($url);
        $scheme      = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $httpAllowed = $scheme === 'http' && self::localHttpAllowed();
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || ! is_array($parts)
            || ($scheme !== 'https' && ! $httpAllowed)
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! self::sameOrigin($url, $bSiteUrl)) {
            throw ValidationException::withMessages([$field => '责任条款必须是 B 站同域的 HTTPS 地址（本地或测试环境可使用 HTTP）。']);
        }

        return $url;
    }

    private static function localHttpAllowed(): bool
    {
        try {
            $application = app();
            if (method_exists($application, 'environment')) {
                return $application->environment(['local', 'testing']);
            }
        } catch (\Throwable) {
            // 纯单元测试或应用尚未完成引导时回退到 APP_ENV。
        }

        return in_array(strtolower((string) env('APP_ENV', 'production')), ['local', 'testing'], true);
    }

    private static function port(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 80 : 443;
    }
}
