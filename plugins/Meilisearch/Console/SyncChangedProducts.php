<?php

namespace Plugin\Meilisearch\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Plugin\Meilisearch\Console\Concerns\ResolvesCatalogs;
use Plugin\Meilisearch\Services\ProductIndexer;
use Plugin\Meilisearch\Services\Settings;

class SyncChangedProducts extends Command
{
    use ResolvesCatalogs;

    protected $signature = 'meilisearch:sync-changed
                            {--catalog=* : 仅同步指定商品库（real/public），默认全部}
                            {--locale=* : 仅同步指定语言}
                            {--since= : ISO 日期时间}
                            {--overlap=120 : 重叠扫描秒数}';

    protected $description = '按更新时间增量同步商品到 Meilisearch';

    public function handle(ProductIndexer $indexer): int
    {
        $locales = array_values(array_filter((array) $this->option('locale'))) ?: Settings::locales();
        $since   = $this->option('since') ? Carbon::parse($this->option('since')) : null;
        $overlap = max(0, (int) $this->option('overlap'));

        foreach ($this->resolveCatalogs() as $catalog) {
            foreach ($locales as $locale) {
                $count = $indexer->syncChanged($catalog, $locale, $since, $overlap);
                $this->info("商品库 {$catalog} 语言 {$locale} 已同步 {$count} 个商品");
            }
        }

        return self::SUCCESS;
    }
}
