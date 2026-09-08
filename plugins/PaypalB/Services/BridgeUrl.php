<?php

namespace Plugin\PaypalB\Services;

final class BridgeUrl
{
    public static function baseUrl(mixed $value, string $field): string
    {
        $url   = trim((string) $value);
        $parts = parse_url($url);
        if (! is_array($parts)
            || empty($parts['scheme'])
            || empty($parts['host'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            self::invalid($field, '必须填写不含账号、查询参数和片段的完整公网地址。');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https' && ! ($scheme === 'http' && self::localHttpAllowed())) {
            self::invalid($field, '生产环境只允许 HTTPS 地址。');
        }

        return rtrim($url, '/');
    }

    public static function join(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    public static function isAllowedCallback(string $callbackUrl, string $aSiteUrl): bool
    {
        $callback = parse_url($callbackUrl);
        $base     = parse_url($aSiteUrl);
        if (! is_array($callback) || ! is_array($base)) {
            return false;
        }

        $scheme     = strtolower((string) ($callback['scheme'] ?? ''));
        $host       = strtolower((string) ($callback['host'] ?? ''));
        $baseScheme = strtolower((string) ($base['scheme'] ?? ''));
        $baseHost   = strtolower((string) ($base['host'] ?? ''));
        $port       = (int) ($callback['port'] ?? ($scheme === 'http' ? 80 : 443));
        $basePort   = (int) ($base['port'] ?? ($baseScheme === 'http' ? 80 : 443));

        return in_array($scheme, ['http', 'https'], true)
            && $scheme === $baseScheme
            && $host   === $baseHost
            && $port   === $basePort
            // 两站不可能同一秒完成部署，升级窗口内新旧回调路径都必须放行。
            && in_array($callback['path'] ?? '', [
                '/api/paypal/bridge/callback',
                '/api/paypal-a/bridge/callback',
            ], true)
            && empty($callback['user'])
            && empty($callback['pass'])
            && empty($callback['query'])
            && empty($callback['fragment']);
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
}
