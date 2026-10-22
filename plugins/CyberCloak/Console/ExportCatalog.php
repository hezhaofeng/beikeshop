<?php

namespace Plugin\CyberCloak\Console;

use Illuminate\Console\Command;
use Plugin\CyberCloak\Services\CatalogExportService;

class ExportCatalog extends Command
{
    protected $signature = 'cyber-cloak:export-catalog
                            {file : 导出的 JSON 文件路径}
                            {--connection=catalog_public : 来源数据库连接}
                            {--active-only : 只导出启用中的品牌、分类和商品}';

    protected $description = '把 cyberCloak 展示商品库导出为可再次导入的 JSON（供 B 站 paypal-b:import-catalog 使用）';

    /**
     * 导出展示库，输出本次处理数量。文件可用 cyber-cloak:import-catalog 或 paypal-b:import-catalog 导入。
     */
    public function handle(CatalogExportService $exporter): int
    {
        try {
            $result = $exporter->export(
                (string) $this->argument('file'),
                (string) $this->option('connection'),
                (bool) $this->option('active-only')
            );
        } catch (\Throwable $exception) {
            $this->error('展示库导出失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info('展示库导出完成：' . $this->argument('file'));
        foreach ($result as $name => $count) {
            $this->line("{$name}: {$count}");
        }

        return self::SUCCESS;
    }
}
