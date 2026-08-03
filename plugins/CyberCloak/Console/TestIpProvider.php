<?php

namespace Plugin\CyberCloak\Console;

use Illuminate\Console\Command;
use Plugin\CyberCloak\Services\IpProviderSyncService;

class TestIpProvider extends Command
{
    protected $signature = 'cyber-cloak:test-ip-provider
                            {--provider= : 临时覆盖供应商适配器}
                            {--endpoint= : 临时覆盖供应商地址}
                            {--timeout= : 临时覆盖请求超时秒数}';

    protected $description = '测试 cyberCloak IP 黑名单供应商连接';

    /**
     * 测试供应商并输出标准化地址，不写入缓存或同步日志。
     */
    public function handle(IpProviderSyncService $sync): int
    {
        try {
            $result = $sync->test($this->overrides());
        } catch (\Throwable $exception) {
            $this->error('IP 供应商测试失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info("IP 供应商 {$result['provider']} 连接正常");
        $this->line("获取：{$result['fetched_count']}，有效：{$result['accepted_count']}，无效：{$result['invalid_count']}");
        foreach ($result['ranges'] as $range) {
            $this->line($range);
        }

        return self::SUCCESS;
    }

    /**
     * 读取命令行临时覆盖项。
     *
     * @return array<string,mixed>
     */
    private function overrides(): array
    {
        $overrides = [];
        foreach (['provider', 'endpoint', 'timeout'] as $option) {
            $value = $this->option($option);
            if ($value !== null && $value !== '') {
                $overrides[$option] = $option === 'timeout' ? (int) $value : $value;
            }
        }

        return $overrides;
    }
}
