<?php

namespace Plugin\CyberCloak\Console;

use Illuminate\Console\Command;
use Plugin\CyberCloak\Services\SkuMappingService;

class ConfirmSkuMapping extends Command
{
    protected $signature = 'cyber-cloak:confirm-sku-mapping
                            {version : 映射版本号}
                            {real_sku_id : 真实 SKU ID}
                            {public_sku_id : 展示 SKU ID}
                            {--publish : 确认后尝试发布版本}';

    protected $description = '确认 cyberCloak 的待处理 SKU 映射';

    /**
     * 写回人工确认结果，并在没有剩余歧义时发布版本。
     */
    public function handle(SkuMappingService $mappings): int
    {
        try {
            $result = $mappings->confirm(
                (string) $this->argument('version'),
                (int) $this->argument('real_sku_id'),
                (int) $this->argument('public_sku_id')
            );
            $this->info("SKU 映射已确认：真实 {$result['real_sku_id']} -> 展示 {$result['public_sku_id']}");

            if ($this->option('publish')) {
                $published = $mappings->publishConfirmed((string) $this->argument('version'));
                $this->line($published ? '版本状态：已发布' : '版本仍有待确认映射');
            }
        } catch (\Throwable $exception) {
            $this->error('SKU 映射确认失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
