<?php

namespace Plugin\CyberCloakSimple\Services;

use Carbon\Carbon;

/**
 * 校验已摘要化的访问 key，并处理启用状态和过期时间。
 */
class AccessKeyService
{
    /**
     * 注入配置读取服务。
     */
    public function __construct(private readonly SettingService $settings)
    {
    }

    /**
     * 查找匹配且仍有效的 key 记录。
     *
     * @return array<string,mixed>|null
     */
    public function findValid(string $plainKey): ?array
    {
        $plainKey = trim($plainKey);
        if ($plainKey === '') {
            return null;
        }

        foreach ($this->all() as $record) {
            if (! $this->isUsable($record)) {
                continue;
            }

            $hash = (string) ($record['hash'] ?? '');
            if ($hash !== '' && hash_equals($hash, hash('sha256', $plainKey))) {
                return $record;
            }
        }

        return null;
    }

    /**
     * 根据 Cookie 票据中的 key 标识重新确认该 key 仍有效。
     *
     * @return array<string,mixed>|null
     */
    public function findValidById(string $id): ?array
    {
        foreach ($this->all() as $record) {
            if (($record['id'] ?? '') === $id && $this->isUsable($record)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * 将 key 记录的过期时间转换为 Unix 时间戳；0 表示长期有效。
     */
    public function expiresAtTimestamp(array $record): int
    {
        $expiresAt = $record['expires_at'] ?? null;
        if ($expiresAt === null || $expiresAt === '' || $expiresAt === 0 || $expiresAt === '0') {
            return 0;
        }

        try {
            return Carbon::parse($expiresAt)->timestamp;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 标准化后台 JSON 中的 key 记录。
     *
     * @return array<int,array<string,mixed>>
     */
    private function all(): array
    {
        $records = [];
        foreach ($this->settings->records('access_keys') as $record) {
            $hash = strtolower(trim((string) ($record['hash'] ?? '')));
            // 后台允许直接配置 value；进入运行时记录前立即转换为摘要，后续逻辑不会保留明文。
            if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                $value = $record['value'] ?? $record['key'] ?? null;
                $hash  = is_scalar($value) && trim((string) $value) !== ''
                    ? hash('sha256', trim((string) $value))
                    : '';
            }
            if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                continue;
            }

            $records[] = [
                'id'         => trim((string) ($record['id'] ?? substr($hash, 0, 32))),
                'hash'       => $hash,
                'status'     => strtolower(trim((string) ($record['status'] ?? 'enabled'))),
                'expires_at' => $record['expires_at'] ?? null,
            ];
        }

        return array_values(array_filter($records, static fn (array $record): bool => $record['id'] !== ''));
    }

    /**
     * 判断 key 是否未禁用且尚未过期。
     */
    private function isUsable(array $record): bool
    {
        if (in_array((string) ($record['status'] ?? ''), ['disabled', 'inactive', '0'], true)) {
            return false;
        }

        $expiresAt = $record['expires_at'] ?? null;
        if ($expiresAt === null || $expiresAt === '' || $expiresAt === 0 || $expiresAt === '0') {
            return true;
        }

        try {
            return Carbon::parse($expiresAt)->isFuture();
        } catch (\Throwable) {
            // 格式错误的过期时间按失效处理，避免配置问题让 key 永久有效。
            return false;
        }
    }
}
