<?php

namespace Plugin\CyberCloak\Console;

use Illuminate\Console\Command;
use Plugin\CyberCloak\Services\SkuMappingService;

class RebuildSkuMappings extends Command
{
    protected $signature = 'cyber-cloak:rebuild-sku-mappings
                            {--mode=exact : 匹配模式：exact、candidate 或 random}
                            {--mapping-version= : 自定义映射版本号}
                            {--publish : 构建完成后发布版本}
                            {--force : 存在 pending/conflict 时仍发布}';

    protected $description = '重建 cyberCloak 商品和 SKU 映射';

    /**
     * 执行批量映射并输出待确认和冲突数量。
     */
    public function handle(SkuMappingService $mappings): int
    {
        try {
            $result = $mappings->rebuild(
                (string) $this->option('mode'),
                // 使用 mapping-version 避免与 Artisan 全局 --version 选项冲突。
                $this->option('mapping-version') ? (string) $this->option('mapping-version') : null,
                (bool) $this->option('publish'),
                (bool) $this->option('force')
            );
        } catch (\Throwable $exception) {
            $this->error('映射重建失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info("映射版本：{$result['version']}");
        $this->line("商品：{$result['products']}，SKU：{$result['skus']}");
        $this->line("已确认：{$result['confirmed']}，待确认：{$result['pending']}，冲突：{$result['conflict']}");
        $this->line($result['published'] ? '版本状态：已发布' : '版本状态：草稿');

        return self::SUCCESS;
    }
}
