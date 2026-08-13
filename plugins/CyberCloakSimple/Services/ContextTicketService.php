<?php

namespace Plugin\CyberCloakSimple\Services;

/**
 * 生成和验证不包含明文 key 的 HMAC 签名 Cookie 票据。
 */
class ContextTicketService
{
    /**
     * 注入 key 服务以便票据验证时复查 key 状态。
     */
    public function __construct(private readonly AccessKeyService $accessKeys)
    {
    }

    /**
     * 为当前有效 key 签发签名票据。
     */
    public function issue(array $record): string
    {
        $payload = [
            'version' => 1,
            'key_id'  => $record['id'],
            'issued'  => time(),
            'expires' => $this->accessKeys->expiresAtTimestamp($record),
            'nonce'   => bin2hex(random_bytes(16)),
        ];
        $encodedPayload = $this->encode($payload);

        return $encodedPayload . '.' . hash_hmac('sha256', $encodedPayload, $this->secret());
    }

    /**
     * 验证票据签名、过期时间和服务端 key 当前状态。
     *
     * @return array{record:array<string,mixed>,issued:int,expires:int}|null
     */
    public function verify(?string $ticket): ?array
    {
        if (! is_string($ticket) || substr_count($ticket, '.') !== 1) {
            return null;
        }

        [$encodedPayload, $signature] = explode('.', $ticket, 2);
        $expected = hash_hmac('sha256', $encodedPayload, $this->secret());
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = $this->decode($encodedPayload);
        if (! is_array($payload) || ($payload['version'] ?? null) !== 1) {
            return null;
        }

        $expires = (int) ($payload['expires'] ?? 0);
        if ($expires > 0 && $expires <= time()) {
            return null;
        }

        $record = $this->accessKeys->findValidById((string) ($payload['key_id'] ?? ''));
        if (! $record) {
            return null;
        }

        return [
            'record'  => $record,
            'issued'  => (int) ($payload['issued'] ?? 0),
            'expires' => $expires,
        ];
    }

    /**
     * 获取应用密钥，兼容 Laravel 的 base64: APP_KEY 格式。
     */
    private function secret(): string
    {
        $key = (string) config('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    /**
     * 将载荷编码为 URL 安全 Base64。
     */
    private function encode(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * 解码并校验票据 JSON。
     *
     * @return array<string,mixed>|null
     */
    private function decode(string $encoded): ?array
    {
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }
}
