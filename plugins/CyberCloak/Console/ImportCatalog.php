<?php

namespace Plugin\CyberCloak\Console;

use Illuminate\Console\Command;
use Plugin\CyberCloak\Services\CatalogImportService;

class ImportCatalog extends Command
{
    protected $signature = 'cyber-cloak:import-catalog
                            {file : 展示商品库 JSON 文件路径}
                            {--connection=catalog_public : 目标数据库连接}
                            {--truncate : 导入前清空展示库商品相关表}
                            {--dry-run : 只校验并统计文件，不写入数据库}';

    protected $description = '导入 cyberCloak 展示商品库数据';

    /**
     * 执行展示库导入，并输出本次处理数量。
     */
    public function handle(CatalogImportService $importer): int
    {
        try {
            $result = $importer->import(
                (string) $this->argument('file'),
                (string) $this->option('connection'),
                (bool) $this->option('truncate'),
                (bool) $this->option('dry-run')
            );
        } catch (\Throwable $exception) {
            $this->error('展示库导入失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('dry-run') ? '文件校验通过（试运行）' : '展示库导入完成');
        foreach ($result as $name => $count) {
            $this->line("{$name}: {$count}");
        }

        return self::SUCCESS;
    }
}
