<?php

namespace Plugin\Meilisearch\Console\Concerns;

use Plugin\Meilisearch\Services\CatalogContext;
use RuntimeException;

/**
 * 命令行统一解析 --catalog 选项；不传时处理当前站点全部可用商品库。
 */
trait ResolvesCatalogs
{
    /**
     * @return array<int,string>
     */
    protected function resolveCatalogs(): array
    {
        $available = CatalogContext::catalogs();
        $requested = array_values(array_filter((array) $this->option('catalog')));
        if (! $requested) {
            return $available;
        }

        $unknown = array_diff($requested, $available);
        if ($unknown) {
            throw new RuntimeException('商品库不可用：' . implode('、', $unknown));
        }

        return $requested;
    }
}
