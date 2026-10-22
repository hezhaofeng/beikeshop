<?php

namespace Plugin\PaypalB\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportPaypalBCatalog extends Command
{
    protected $signature = 'paypal-b:import-catalog
                            {file : A 站 cyber-cloak:export-catalog 导出的 JSON 文件路径}
                            {--truncate : 导入前清空本站品牌、分类、商品和 SKU（危险，需确认）}
                            {--force : 与 --truncate 同用时跳过确认；生产环境必须显式指定且仍需确认}
                            {--dry-run : 只校验并统计文件，不写入数据库}';

    protected $description = '把 A 站 CyberCloak 展示商品库导入本站商品表，使本站商品与 PayPal 映射商品明细一致';

    /**
     * CyberCloak 导入器清空时不处理、但引用商品 ID 的表：清空商品后这些行全部指向已删除的商品。
     *
     * @var array<int, string>
     */
    private const PRODUCT_REFERENCE_TABLES = ['product_attributes', 'product_relations', 'cart_products', 'customer_wishlists'];

    /**
     * 复用 CyberCloak 的导入器写入本站默认数据库连接。文件保留稳定 ID，重复导入幂等；
     * 与展示库同 ID 的本站商品会被覆盖，因此只适用于专门为收款而建的 B 站。
     */
    public function handle(): int
    {
        $importer = 'Plugin\CyberCloak\Services\CatalogImportService';
        if (! class_exists($importer)) {
            $this->error('缺少 CyberCloak 插件目录（plugins/CyberCloak），无法复用其导入器。插件无需启用，但代码必须存在。');

            return self::FAILURE;
        }

        $truncate = (bool) $this->option('truncate');
        $dryRun   = (bool) $this->option('dry-run');
        if ($truncate && ! $dryRun && ! $this->confirmTruncate()) {
            return $this->option('force') || ! app()->environment('production') ? self::SUCCESS : self::FAILURE;
        }

        $connection = (string) config('database.default');

        try {
            $result = app($importer)->import(
                (string) $this->argument('file'),
                $connection,
                $truncate,
                $dryRun
            );
            if ($truncate && ! $dryRun) {
                $result += $this->purgeProductReferences($connection);
            }
        } catch (\Throwable $exception) {
            $this->error('商品库导入失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? '文件校验通过（试运行）' : '本站商品库导入完成');
        foreach ($result as $name => $count) {
            $this->line("{$name}: {$count}");
        }

        return self::SUCCESS;
    }

    /**
     * 非生产环境 --force 跳过确认；生产环境必须显式 --force，且仍要交互确认（--no-interaction 下视为取消）。
     */
    private function confirmTruncate(): bool
    {
        $question = '将清空本站全部品牌、分类、商品与 SKU 后重新导入，并删除引用旧商品的商品属性、相关商品、购物车和收藏记录，且不可恢复。确定继续？';
        if (app()->environment('production')) {
            if (! $this->option('force')) {
                $this->error('生产环境使用 --truncate 必须同时指定 --force。');

                return false;
            }
            if (! $this->confirm('【生产环境】' . $question, false)) {
                $this->line('已取消。');

                return false;
            }

            return true;
        }

        if ($this->option('force') || $this->confirm($question, false)) {
            return true;
        }
        $this->line('已取消。');

        return false;
    }

    /**
     * 清空商品后，CyberCloak 导入器不会处理的引用表整体清理：这些行引用的都是已删除的旧商品，
     * 即使新导入的商品沿用了相同 ID，含义也已不同。表不存在时跳过。
     *
     * @return array<string, int>
     */
    private function purgeProductReferences(string $connection): array
    {
        $database = DB::connection($connection);
        $schema   = Schema::connection($connection);
        $deleted  = [];

        $database->transaction(function () use ($database, $schema, &$deleted): void {
            foreach (self::PRODUCT_REFERENCE_TABLES as $table) {
                if ($schema->hasTable($table)) {
                    $deleted["{$table} (deleted)"] = $database->table($table)->delete();
                }
            }
        });

        return $deleted;
    }
}
