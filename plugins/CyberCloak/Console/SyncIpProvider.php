<?php

namespace Plugin\CyberCloak\Console;

use Illuminate\Console\Command;
use Plugin\CyberCloak\Services\IpProviderSyncService;

class SyncIpProvider extends Command
{
    protected $signature = 'cyber-cloak:sync-ip-provider
                            {--dry-run : 只请求和校验，不替换本地缓存}
                            {--provider= : 临时覆盖供应商适配器}
                            {--endpoint= : 临时覆盖供应商地址}
                            {--timeout= : 临时覆盖请求超时秒数}
                            {--cache-ttl= : 临时覆盖缓存有效分钟数}';

    protected $description = '同步 cyberCloak IP 黑名单供应商缓存';

    /**
     * 执行供应商同步，并在命令行输出脱敏后的统计信息。
     */
    public function handle(IpProviderSyncService $sync): int
    {
        try {
            $result = $sync->sync((bool) $this->option('dry-run'), $this->overrides());
        } catch (\Throwable $exception) {
            $this->error('IP 供应商同步失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['dry_run'] ? 'IP 供应商校验完成（试运行）' : 'IP 供应商同步完成');
        $this->line("供应商：{$result['provider']}");
        $this->line("获取：{$result['fetched_count']}，有效：{$result['accepted_count']}，无效：{$result['invalid_count']}");
        $this->line("缓存过期时间：{$result['expires_at']}");

        return self::SUCCESS;
    }

    /**
     * 只把命令行明确提供的覆盖项传给服务，避免覆盖后台配置默认值。
     *
     * @return array<string,mixed>
     */
    private function overrides(): array
    {
        $overrides = [];
        foreach (['provider', 'endpoint', 'timeout', 'cache-ttl'] as $option) {
            $value = $this->option($option);
            if ($value !== null && $value !== '') {
                $key = str_replace('-', '_', $option);
                $overrides[$key] = $option === 'timeout' || $option === 'cache-ttl' ? (int) $value : $value;
            }
        }

        return $overrides;
    }
}
