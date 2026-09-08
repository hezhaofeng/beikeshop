<?php

namespace Plugin\Meilisearch\Console;

use Illuminate\Console\Command;
use Plugin\Meilisearch\Console\Concerns\ResolvesCatalogs;
use Plugin\Meilisearch\Services\ProductIndexer;
use Plugin\Meilisearch\Services\Settings;

class RepairProducts extends Command
{
    use ResolvesCatalogs;

    protected $signature = 'meilisearch:repair-products
                            {--catalog=* : 仅修复指定商品库（real/public），默认全部}
                            {--locale=* : 仅修复指定语言}';

    protected $description = '通过完整重建修复商品索引差异和孤儿文档';

    public function handle(ProductIndexer $indexer): int
    {
        $locales = array_values(array_filter((array) $this->option('locale'))) ?: Settings::locales();

        foreach ($this->resolveCatalogs() as $catalog) {
            foreach ($locales as $locale) {
                $index = $indexer->rebuild($catalog, $locale);
                $this->info("商品库 {$catalog} 语言 {$locale} 修复完成，活动索引：{$index}");
            }
        }

        return self::SUCCESS;
    }
}
