<?php

namespace Plugin\CyberCloak\Console;

use Illuminate\Console\Command;
use Plugin\CyberCloak\Services\SkuMappingService;

class SyncPublishedSkuIndex extends Command
{
    protected $signature = 'cyber-cloak:sync-published-sku-index
                            {--mapping-version= : 指定已发布映射版本，默认使用当前版本}';

    protected $description = '将已发布 CyberCloak SKU 映射同步到展示库本地索引';

    /**
     * 为存量已发布映射创建展示库索引，前台查询无需再加载全量 SKU ID。
     */
    public function handle(SkuMappingService $mappings): int
    {
        try {
            $result = $mappings->syncPublishedSkuIndex(
                $this->option('mapping-version') ? (string) $this->option('mapping-version') : null
            );
        } catch (\Throwable $exception) {
            $this->error('展示 SKU 索引同步失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info("展示 SKU 索引已同步：{$result['version']}，SKU {$result['skus']} 条");

        return self::SUCCESS;
    }
}
