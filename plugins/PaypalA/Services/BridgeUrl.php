<?php

namespace Plugin\PaypalA\Services;

use Illuminate\Validation\ValidationException;

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

        if (! in_array($scheme, ['http', 'https'], true)) {
            self::invalid($field, '地址协议只能是 HTTP 或 HTTPS。');
        }

        return rtrim($url, '/');
    }

    public static function join(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    public static function sameOrigin(string $first, string $second): bool
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

    private static function port(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 80 : 443;
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

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
