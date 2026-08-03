<?php

namespace Plugin\CyberCloak\Services;

class ContextTicketService
{
    public function __construct(private readonly AccessKeyService $accessKeys)
    {
    }

    /**
     * 为真实模式生成不包含明文 key 的签名票据。
     */
    public function issue(array $keyRecord): string
    {
        $payload = [
            'version' => 1,
            'key_id'  => $keyRecord['id'],
            'issued'  => time(),
            'expires' => $this->accessKeys->expiresAtTimestamp($keyRecord),
            'nonce'   => bin2hex(random_bytes(16)),
        ];
        $encodedPayload = $this->encode($payload);
        $signature      = hash_hmac('sha256', $encodedPayload, $this->secret());

        return $encodedPayload . '.' . $signature;
    }

    /**
     * 验证 Cookie 票据签名、有效期和服务端 key 状态。
     *
     * @return array{record: array<string, mixed>, issued: int, expires: int}|null
     */
    public function verify(?string $ticket): ?array
    {
        if (! $ticket || substr_count($ticket, '.') !== 1) {
            return null;
        }

        [$encodedPayload, $signature] = explode('.', $ticket, 2);
        $expected                     = hash_hmac('sha256', $encodedPayload, $this->secret());
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

        $keyId  = (string) ($payload['key_id'] ?? '');
        $record = $this->accessKeys->findValidById($keyId);
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
     * 判断票据是否接近续期时间。
     */
    public function shouldRefresh(array $ticket): bool
    {
        $refreshMinutes = max(0, (int) config('cyber_cloak.cookie_refresh_minutes', 1440));
        if ($refreshMinutes === 0) {
            return false;
        }

        return ((int) ($ticket['issued'] ?? 0) + ($refreshMinutes * 60)) <= time();
    }

    /**
     * 使用应用密钥生成 HMAC 密钥，兼容 Laravel 的 base64: APP_KEY 格式。
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
     * 对票据 JSON 做 URL 安全编码。
     */
    private function encode(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * 解码并校验票据 JSON。
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
