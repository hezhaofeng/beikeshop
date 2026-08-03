<?php

namespace Plugin\CyberCloak\Services;

use Beike\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class IpProviderSyncService
{
    public function __construct(
        private readonly IpProviderRegistry $registry,
        private readonly IpRangeNormalizer $normalizer,
    ) {
    }

    /**
     * 测试供应商连接并标准化响应，但不写入本地黑名单缓存。
     *
     * @return array{provider:string,fetched_count:int,accepted_count:int,invalid_count:int,ranges:array<int,string>}
     */
    public function test(?array $overrides = null): array
    {
        $config = array_merge($this->configuration(), $overrides ?: []);
        $result = $this->fetch($config);

        return [
            'provider'       => $config['provider'],
            'fetched_count'  => count($result->values),
            'accepted_count' => count($result->meta['ranges'] ?? []),
            'invalid_count'  => (int) ($result->meta['invalid_count'] ?? 0),
            'ranges'         => $result->meta['ranges'] ?? [],
        ];
    }

    /**
     * 拉取供应商数据并原子替换该供应商的本地缓存，前台请求只读取这份缓存。
     *
     * @return array{provider:string,fetched_count:int,accepted_count:int,invalid_count:int,expires_at:string,dry_run:bool}
     */
    public function sync(bool $dryRun = false, ?array $overrides = null): array
    {
        $config = array_merge($this->configuration(), $overrides ?: []);
        if (! $config['enabled']) {
            throw new RuntimeException('IP 供应商同步未启用');
        }

        $startedAt = now();
        try {
            $result = $this->fetch($config);
            $ranges = $result->meta['ranges'] ?? [];
            if ($ranges === [] && ! $config['allow_empty']) {
                throw new RuntimeException('IP 供应商返回空列表，已阻止清空本地缓存');
            }

            $expiresAt = now()->addMinutes($config['cache_ttl']);
            if (! $dryRun) {
                $this->replaceCache($config['provider'], $ranges, $expiresAt);
            }
            if (! $dryRun) {
                $this->writeLog($config['provider'], 'success', count($result->values), count($ranges), null, $startedAt, now());
            }

            return [
                'provider'       => $config['provider'],
                'fetched_count'  => count($result->values),
                'accepted_count' => count($ranges),
                'invalid_count'  => (int) ($result->meta['invalid_count'] ?? 0),
                'expires_at'     => $expiresAt->toIso8601String(),
                'dry_run'        => $dryRun,
            ];
        } catch (\Throwable $exception) {
            $this->writeLog($config['provider'], 'failed', 0, 0, $exception->getMessage(), $startedAt, now());

            throw $exception;
        }
    }

    /**
     * 读取未过期的本地供应商缓存，绝不在前台请求中调用远程适配器。
     *
     * @return array<int,string>
     */
    public function cachedRanges(): array
    {
        $config = $this->configuration();
        if (! $config['enabled'] || ! $this->hasTable('cyber_cloak_ip_provider_entries')) {
            return [];
        }

        return DB::table('cyber_cloak_ip_provider_entries')
            ->where('provider_code', $config['provider'])
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('ip')
            ->map(static fn ($ip): string => (string) $ip)
            ->all();
    }

    /**
     * fail_mode=public 且本地缓存失效时，强制当前请求留在展示模式。
     */
    public function isUnavailable(): bool
    {
        $config = $this->configuration();
        if (! $config['enabled'] || $config['fail_mode'] !== 'public') {
            return false;
        }
        if (! $this->hasTable('cyber_cloak_ip_provider_entries')) {
            return true;
        }
        if ($this->cachedRanges() !== []) {
            return false;
        }

        // 允许空列表时，成功同步本身就是有效缓存，不能把空黑名单误判成供应商故障。
        if ($config['allow_empty'] && $this->hasFreshSuccessLog($config['provider'], $config['cache_ttl'])) {
            return false;
        }

        return true;
    }

    /**
     * 返回后台和命令使用的供应商配置，token 保留在服务内，不写入日志结果。
     *
     * @return array{enabled:bool,provider:string,endpoint:string,token:string,timeout:int,cache_ttl:int,fail_mode:string,allow_empty:bool,schedule:string}
     */
    public function configuration(): array
    {
        return [
            'enabled'     => $this->boolSetting('ip_provider_enabled', false),
            'provider'    => (string) $this->setting('ip_provider', 'http'),
            'endpoint'    => trim((string) $this->setting('ip_provider_endpoint', '')),
            'token'       => (string) $this->setting('ip_provider_token', ''),
            'timeout'     => max(1, (int) $this->setting('ip_provider_timeout', 5)),
            'cache_ttl'   => max(1, (int) $this->setting('ip_provider_cache_ttl', 60)),
            'fail_mode'   => in_array($this->setting('ip_provider_fail_mode', 'public'), ['public', 'keep'], true)
                ? (string) $this->setting('ip_provider_fail_mode', 'public') : 'public',
            'allow_empty' => $this->boolSetting('ip_provider_allow_empty', false),
            'schedule'    => (string) $this->setting('ip_provider_schedule', 'hourly'),
        ];
    }

    /**
     * 当前适配器获取和标准化数据，统一处理供应商响应契约。
     */
    private function fetch(array $config): IpProviderResult
    {
        $result = $this->registry->resolve((string) $config['provider'])->fetch($config);
        $normalized = $this->normalizer->normalizeMany($result->values);

        return new IpProviderResult($result->values, array_merge($result->meta, $normalized));
    }

    /**
     * 用事务替换缓存，避免同步中途前台读到半批地址。
     */
    private function replaceCache(string $provider, array $ranges, Carbon $expiresAt): void
    {
        if (! $this->hasTable('cyber_cloak_ip_provider_entries')) {
            throw new RuntimeException('请先执行阶段六数据库迁移');
        }

        DB::transaction(function () use ($provider, $ranges, $expiresAt): void {
            DB::table('cyber_cloak_ip_provider_entries')->where('provider_code', $provider)->delete();
            $now = now();
            $rows = array_map(function (string $range) use ($provider, $expiresAt, $now): array {
                return [
                    'provider_code' => $provider,
                    'ip'            => $range,
                    'ip_version'    => str_contains($range, ':') ? 'ipv6' : 'ipv4',
                    'source'        => 'provider',
                    'active'        => true,
                    'expires_at'    => $expiresAt,
                    'synced_at'     => $now,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }, $ranges);
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('cyber_cloak_ip_provider_entries')->insert($chunk);
            }
        });
    }

    /**
     * 同步日志只保留数量和错误摘要，避免把 token 或完整响应写入日志。
     */
    private function writeLog(string $provider, string $status, int $fetched, int $accepted, ?string $error, Carbon $startedAt, Carbon $finishedAt): void
    {
        if (! $this->hasTable('cyber_cloak_ip_provider_sync_logs')) {
            return;
        }

        DB::table('cyber_cloak_ip_provider_sync_logs')->insert([
            'provider_code'  => $provider,
            'status'         => $status,
            'fetched_count'  => $fetched,
            'accepted_count' => $accepted,
            'error'          => $error ? mb_substr($error, 0, 1000) : null,
            'started_at'     => $startedAt,
            'finished_at'    => $finishedAt,
            'created_at'     => $finishedAt,
            'updated_at'     => $finishedAt,
        ]);
    }

    /**
     * 读取 settings 表，并在安装或单元测试早期回退到插件配置。
     */
    private function setting(string $name, mixed $default): mixed
    {
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
            // settings 表尚未建立时继续使用环境变量和配置默认值。
        }

        $value = function_exists('plugin_setting') ? plugin_setting("cyber_cloak.{$name}", null) : null;

        return ($value !== null && $value !== '') ? $value : config("cyber_cloak.{$name}", $default);
    }

    /**
     * 将后台表单、环境变量和 JSON 设置统一解释为布尔值。
     */
    private function boolSetting(string $name, bool $default): bool
    {
        return filter_var($this->setting($name, $default), FILTER_VALIDATE_BOOL);
    }

    /**
     * 判断阶段六表是否已经迁移，兼容插件尚未升级完成的旧实例。
     */
    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 判断空结果是否来自本周期内的成功同步。
     */
    private function hasFreshSuccessLog(string $provider, int $cacheTtl): bool
    {
        if (! $this->hasTable('cyber_cloak_ip_provider_sync_logs')) {
            return false;
        }

        $finishedAt = DB::table('cyber_cloak_ip_provider_sync_logs')
            ->where('provider_code', $provider)
            ->where('status', 'success')
            ->latest('id')
            ->value('finished_at');
        if (! $finishedAt) {
            return false;
        }

        try {
            return Carbon::parse($finishedAt)->greaterThan(now()->subMinutes($cacheTtl));
        } catch (\Throwable) {
            return false;
        }
    }
}
