<?php

namespace Plugin\Meilisearch\Console;

use Illuminate\Console\Command;
use Plugin\Meilisearch\Console\Concerns\ResolvesCatalogs;
use Plugin\Meilisearch\Services\ProductIndexer;
use Plugin\Meilisearch\Services\Settings;

class ReindexProducts extends Command
{
    use ResolvesCatalogs;

    protected $signature = 'meilisearch:reindex-products
                            {--catalog=* : 仅重建指定商品库（real/public），默认全部}
                            {--locale=* : 仅重建指定语言}
                            {--batch= : 每批商品数}';

    protected $description = '为所有商品重建版本化 Meilisearch 索引';

    public function handle(ProductIndexer $indexer): int
    {
        $locales = array_values(array_filter((array) $this->option('locale'))) ?: Settings::locales();
        $batch   = $this->option('batch') ? max(1, (int) $this->option('batch')) : null;

        foreach ($this->resolveCatalogs() as $catalog) {
            foreach ($locales as $locale) {
                $count = 0;
                $this->info("开始重建商品库 {$catalog} 语言 {$locale} 的商品索引");
                $index = $indexer->rebuild($catalog, $locale, $batch, function (int $processed) use (&$count) {
                    $count += $processed;
                    $this->line("已处理 {$count} 个商品");
                });
                $this->info("索引已切换为 {$index}");
            }
        }

        return self::SUCCESS;
    }
}
