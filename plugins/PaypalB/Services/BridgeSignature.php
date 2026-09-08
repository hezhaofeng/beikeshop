<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * 与 PaypalA 保持完全一致的 canonical JSON/HMAC 协议实现。
 */
final class BridgeSignature
{
    public const HEADER_TIMESTAMP = 'X-Paypal-Bridge-Timestamp';

    public const HEADER_NONCE = 'X-Paypal-Bridge-Nonce';

    public const HEADER_SIGNATURE = 'X-Paypal-Bridge-Signature';

    public static function headers(array $payload, string $secret): array
    {
        $timestamp = (string) now()->timestamp;
        $nonce     = bin2hex(random_bytes(24));

        return [
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE     => $nonce,
            self::HEADER_SIGNATURE => self::signature($payload, $secret, $timestamp, $nonce),
        ];
    }

    public static function signature(array $payload, string $secret, string $timestamp, string $nonce): string
    {
        return hash_hmac('sha256', self::signatureBase($payload, $timestamp, $nonce), $secret);
    }

    public static function verify(
        array $payload,
        string $timestamp,
        string $nonce,
        string $signature,
        string $secret,
        string $scope,
        int $maxSkewSeconds = 300
    ): void {
        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > $maxSkewSeconds) {
            self::invalid('签名时间已过期或服务器时间不同步。');
        }
        if (! preg_match('/^[a-f0-9]{32,128}$/', $nonce)) {
            self::invalid('签名 nonce 格式无效。');
        }

        $expected = self::signature($payload, $secret, $timestamp, $nonce);
        if (! hash_equals($expected, strtolower($signature))) {
            self::invalid('A 站请求签名校验失败。');
        }

        $cacheKey = 'paypal_bridge_nonce:' . $scope . ':' . hash('sha256', $nonce);
        if (! Cache::store('database')->add($cacheKey, true, now()->addSeconds($maxSkewSeconds * 2))) {
            self::invalid('重复的签名请求已被拒绝。');
        }
    }

    public static function canonicalJson(array $payload): string
    {
        try {
            return json_encode(self::normalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('无法生成桥接签名内容。', 0, $exception);
        }
    }

    private static function signatureBase(array $payload, string $timestamp, string $nonce): string
    {
        return $timestamp . "\n" . $nonce . "\n" . self::canonicalJson($payload);
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['bridge_signature' => $message]);
    }
}
