<?php

namespace Plugin\CyberCloak\Services;

use Beike\Models\Setting;
use Beike\Repositories\SettingRepo;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AccessKeyService
{
    /**
     * 获取全部 key 记录，并统一兼容后台 JSON 和环境变量数组两种来源。
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $configured = $this->configured('access_keys', []);
        if (is_string($configured)) {
            $configured = json_decode($configured, true);
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($record) => is_array($record) ? $this->normalize($record) : null,
            $configured
        )));
    }

    /**
     * 根据访问 key 查找当前有效记录，比较过程不保存或返回明文 key。
     */
    public function findValid(string $plainKey): ?array
    {
        $plainKey = trim($plainKey);
        if ($plainKey === '') {
            return null;
        }

        foreach ($this->all() as $record) {
            if (! $this->isUsable($record) || ! $this->matches($plainKey, $record)) {
                continue;
            }

            return $record;
        }

        return null;
    }

    /**
     * 根据票据中的 key 标识查找当前有效记录。
     */
    public function findValidById(string $id): ?array
    {
        foreach ($this->all() as $record) {
            if ($record['id'] === $id && $this->isUsable($record)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * 创建只保存 SHA-256 摘要的 key 记录。
     */
    public function create(string $plainKey, DateTimeInterface $expiresAt = null): array
    {
        $plainKey = trim($plainKey);
        if ($plainKey === '') {
            throw new InvalidArgumentException('访问 key 不能为空');
        }

        $record = [
            'id'         => (string) Str::uuid(),
            'hash'       => hash('sha256', $plainKey),
            'status'     => 'enabled',
            'expires_at' => $expiresAt?->format(DateTimeInterface::ATOM),
        ];
        $records   = $this->all();
        $records[] = $record;
        $this->save($records);

        return $record;
    }

    /**
     * 禁用指定 key，禁用后已有 Cookie 也会在下一次请求回到展示模式。
     */
    public function disable(string $id): bool
    {
        return $this->updateStatus($id, 'disabled');
    }

    /**
     * 启用指定 key，已过期记录仍然保持不可用。
     */
    public function enable(string $id): bool
    {
        return $this->updateStatus($id, 'enabled');
    }

    /**
     * 删除指定 key 记录。
     */
    public function remove(string $id): bool
    {
        $records  = $this->all();
        $filtered = array_values(array_filter($records, fn (array $record) => $record['id'] !== $id));
        if (count($filtered) === count($records)) {
            return false;
        }

        $this->save($filtered);

        return true;
    }

    /**
     * 将有效期转换为票据需要的 Unix 时间戳，0 表示永久有效。
     */
    public function expiresAtTimestamp(array $record): int
    {
        $expiresAt = $record['expires_at'] ?? null;
        if ($expiresAt === null || $expiresAt === '' || $expiresAt === 0 || $expiresAt === '0') {
            return 0;
        }

        return Carbon::parse($expiresAt)->timestamp;
    }

    /**
     * 判断 key 是否处于启用状态且未过期。
     */
    private function isUsable(array $record): bool
    {
        $status = $record['status'] ?? ($record['enabled'] ?? true);
        if ($status === false || in_array(strtolower((string) $status), ['0', 'disabled', 'inactive'], true)) {
            return false;
        }

        $expiresAt = $record['expires_at'] ?? null;
        if ($expiresAt === null || $expiresAt === '' || $expiresAt === 0 || $expiresAt === '0') {
            return true;
        }

        try {
            return Carbon::parse($expiresAt)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 使用摘要比较 key，兼容旧配置中的明文 key 记录以便平滑迁移。
     */
    private function matches(string $plainKey, array $record): bool
    {
        $hash = (string) ($record['hash'] ?? '');
        if ($hash !== '' && hash_equals($hash, hash('sha256', $plainKey))) {
            return true;
        }

        $legacyKey = (string) ($record['key'] ?? '');

        return $legacyKey !== '' && hash_equals($legacyKey, $plainKey);
    }

    /**
     * 标准化单条配置，缺少 id 的旧记录使用摘要生成稳定标识。
     */
    private function normalize(array $record): array
    {
        $identity = (string) ($record['hash'] ?? $record['key'] ?? Str::uuid());

        return [
            'id'         => (string) ($record['id'] ?? substr(hash('sha256', $identity), 0, 32)),
            'hash'       => $record['hash']       ?? null,
            'key'        => $record['key']        ?? null,
            'status'     => $record['status']     ?? (($record['enabled'] ?? true) ? 'enabled' : 'disabled'),
            'expires_at' => $record['expires_at'] ?? null,
        ];
    }

    /**
     * 更新 key 状态并持久化到插件设置。
     */
    private function updateStatus(string $id, string $status): bool
    {
        $records = $this->all();
        $updated = false;
        foreach ($records as &$record) {
            if ($record['id'] !== $id) {
                continue;
            }
            $record['status'] = $status;
            $updated          = true;
        }
        unset($record);

        if ($updated) {
            $this->save($records);
        }

        return $updated;
    }

    /**
     * 保存 key 列表，使用 settings 表的 JSON 字段保存结构化数据。
     */
    private function save(array $records): void
    {
        SettingRepo::storeValue('access_keys', $records, 'cyber_cloak', 'plugin');
    }

    /**
     * 读取插件设置，并在设置尚未初始化时回退到插件配置。
     */
    private function configured(string $name, mixed $default): mixed
    {
        // 测试用例通过插件配置注入隔离 key，避免复用本地 settings 表中的真实测试数据。
        if (app()->environment('testing')) {
            $testingValue = function_exists('plugin_setting') ? plugin_setting("cyber_cloak.{$name}", null) : null;
            if ($testingValue !== null && $testingValue !== '') {
                return $testingValue;
            }
            if (config()->has("cyber_cloak.{$name}")) {
                return config("cyber_cloak.{$name}", $default);
            }
        }

        // 常驻 worker 中直接读取 settings 表，避免后台保存后继续使用启动时的 config 快照。
        try {
            $setting = Setting::query()
                ->where('type', 'plugin')
                ->where('space', 'cyber_cloak')
                ->where('name', $name)
                ->first();
            if ($setting) {
                return $setting->json ? json_decode((string) $setting->value, true) : $setting->value;
            }
        } catch (\Throwable) {
            // 单元测试或迁移早期可能尚未建立 settings 表，继续使用已有配置回退值。
        }

        $value = function_exists('plugin_setting') ? plugin_setting("cyber_cloak.{$name}", null) : null;

        return ($value !== null && $value !== '') ? $value : config("cyber_cloak.{$name}", $default);
    }
}
