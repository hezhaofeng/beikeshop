<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Validation\ValidationException;

final class PaypalBConfiguration
{
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

        $bSiteUrl = BridgeUrl::baseUrl($setting['public_url'] ?? '', 'public_url');
        $aSiteUrl = BridgeUrl::baseUrl($setting['a_site_url'] ?? '', 'a_site_url');
        if (self::sameOrigin($aSiteUrl, $bSiteUrl)) {
            throw ValidationException::withMessages(['a_site_url' => 'A 站和 B 站必须使用不同的公网域名或端口。']);
        }

        $requestSecret  = trim((string) ($setting['a_request_verification_secret'] ?? ''));
        $callbackSecret = trim((string) ($setting['a_callback_signing_secret'] ?? ''));
        if (strlen($requestSecret) < 32 || strlen($callbackSecret) < 32) {
            throw ValidationException::withMessages(['bridge_secret' => '双向签名密钥都必须至少 32 位。']);
        }
        if (hash_equals($requestSecret, $callbackSecret)) {
            throw ValidationException::withMessages(['bridge_secret' => '请求验签密钥和回调签名密钥不能复用。']);
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

        $refundPolicyUrl = self::policyUrl($setting['refund_policy_url'] ?? '', $bSiteUrl, 'refund_policy_url');
        $privacyPolicyUrl = self::policyUrl($setting['privacy_policy_url'] ?? '', $bSiteUrl, 'privacy_policy_url');
        $termsUrl        = self::policyUrl($setting['terms_url'] ?? '', $bSiteUrl, 'terms_url');

        return [
            'public_url'                      => $bSiteUrl,
            'a_site_url'                      => $aSiteUrl,
            'a_request_verification_secret'   => $requestSecret,
            'a_callback_signing_secret'       => $callbackSecret,
            'request_timeout_seconds'         => $timeout,
            'account_cooldown_minutes'        => $cooldown,
            'sandbox_mode'                    => self::booleanValue($setting['sandbox_mode'] ?? null, true),
            'card_enabled'                    => $cardEnabled,
            'wallet_enabled'                  => $walletEnabled,
            'checkout_mode'                   => 'hosted',
            'merchant_display_name'           => $merchantDisplayName,
            'customer_service_email'          => $supportEmail,
            'customer_service_phone'          => $supportPhone,
            'refund_policy_url'               => $refundPolicyUrl,
            'privacy_policy_url'              => $privacyPolicyUrl,
            'terms_url'                       => $termsUrl,
        ];
    }

    private static function booleanValue(mixed $value, bool $default): bool
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

    private static function policyUrl(mixed $value, string $bSiteUrl, string $field): string
    {
        $url   = trim((string) $value);
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
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
