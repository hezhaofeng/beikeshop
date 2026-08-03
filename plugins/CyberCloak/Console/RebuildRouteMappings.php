<?php

namespace Plugin\CyberCloak\Console;

use Illuminate\Console\Command;
use Plugin\CyberCloak\Services\CatalogRouteService;

class RebuildRouteMappings extends Command
{
    protected $signature = 'cyber-cloak:rebuild-route-mappings
                            {--type=product : 路由类型：product 或 category}
                            {--mapping-version= : 路由映射关联的 SKU 映射版本}
                            {--allow-unmapped : 允许分类存在未匹配展示记录并写入排查数据}';

    protected $description = '重建 cyberCloak 稳定数字 URL 映射';

    /**
     * 生成并输出稳定 URL 映射统计。
     */
    public function handle(CatalogRouteService $routes): int
    {
        try {
            $result = $routes->rebuild(
                (string) $this->option('type'),
                // 使用 mapping-version 避免与 Artisan 全局 --version 选项冲突。
                $this->option('mapping-version') ? (string) $this->option('mapping-version') : null,
                (bool) $this->option('allow-unmapped')
            );
        } catch (\Throwable $exception) {
            $this->error('稳定 URL 映射重建失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info("路由类型：{$result['type']}，版本：{$result['version']}");
        $this->line("映射：{$result['routes']}，未匹配展示记录：{$result['unmapped']}");
        if ($result['type'] === 'category') {
            $this->line("实际使用的 Cloak 分类：{$result['mapped_public_categories']}");
        }

        return self::SUCCESS;
    }
}
