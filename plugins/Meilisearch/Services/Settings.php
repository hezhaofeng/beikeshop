<?php

namespace Plugin\Meilisearch\Services;

use Beike\Models\Language;

class Settings
{
    public const SORT_RELEVANCE = 'relevance';
    public const SORT_LATEST    = 'latest';
    public const SORT_VIEWS     = 'views';
    public const SORT_SALES     = 'sales';
    public const SORT_DEFAULT   = 'default';
    public const SORT_UPDATED   = 'updated';

    /**
     * 商品搜索索引只维护中文和英文，避免为未使用语言创建和同步索引。
     */
    private const INDEX_LOCALES = ['zh_cn', 'en'];

    public static function host(): string
    {
        return rtrim((string) env('MEILISEARCH_HOST', plugin_setting('meilisearch.host', 'http://127.0.0.1:7700')), '/');
    }

    public static function apiKey(): string
    {
        return (string) env('MEILISEARCH_KEY', plugin_setting('meilisearch.api_key', ''));
    }

    public static function indexPrefix(): string
    {
        $prefix = (string) env('MEILISEARCH_INDEX_PREFIX', plugin_setting('meilisearch.index_prefix', 'beikeshop'));

        return trim(preg_replace('/[^a-zA-Z0-9_-]+/', '_', $prefix), '_') ?: 'beikeshop';
    }

    public static function timeout(): float
    {
        return max(0.1, (float) env('MEILISEARCH_TIMEOUT', plugin_setting('meilisearch.timeout', 1)));
    }

    public static function batchSize(): int
    {
        return min(1000, max(50, (int) env('MEILISEARCH_BATCH_SIZE', plugin_setting('meilisearch.batch_size', 300))));
    }

    public static function searchEnabled(): bool
    {
        return filter_var(plugin_setting('meilisearch.search_enabled', false), FILTER_VALIDATE_BOOL);
    }

    public static function mysqlFallback(): bool
    {
        return filter_var(env('MEILISEARCH_MYSQL_FALLBACK', plugin_setting('meilisearch.mysql_fallback', true)), FILTER_VALIDATE_BOOL);
    }

    /**
     * 分类名称是否参与商品检索。
     *
     * searchableAttributes 属于索引级设置，改动后需要重建索引才会生效。
     */
    public static function categorySearchEnabled(): bool
    {
        return filter_var(env('MEILISEARCH_CATEGORY_SEARCH', plugin_setting('meilisearch.category_search', true)), FILTER_VALIDATE_BOOL);
    }

    /**
     * 搜索结果未带 sort 参数时使用的排序策略。
     * 默认按商品创建时间倒序，避免全文相关度把新商品长期压在后面。
     */
    public static function searchSort(): string
    {
        return self::normalizeSort(plugin_setting('meilisearch.search_sort', self::SORT_LATEST));
    }

    /**
     * 搜索框为空时 Trending Products 使用的排序策略。
     */
    public static function trendingSort(): string
    {
        return self::normalizeSort(plugin_setting('meilisearch.trending_sort', self::SORT_LATEST));
    }

    public static function sortField(string $sort): ?string
    {
        return [
            self::SORT_LATEST    => 'created_at',
            self::SORT_VIEWS     => 'views',
            self::SORT_SALES     => 'sales',
            self::SORT_DEFAULT   => 'position',
            self::SORT_UPDATED   => 'updated_at',
            self::SORT_RELEVANCE => null,
        ][$sort] ?? null;
    }

    public static function sortOrder(string $sort): string
    {
        return in_array($sort, [self::SORT_DEFAULT], true) ? 'asc' : 'desc';
    }

    private static function normalizeSort(mixed $sort): string
    {
        $sort = (string) $sort;
        $allowed = [
            self::SORT_RELEVANCE,
            self::SORT_LATEST,
            self::SORT_VIEWS,
            self::SORT_SALES,
            self::SORT_DEFAULT,
            self::SORT_UPDATED,
        ];

        return in_array($sort, $allowed, true) ? $sort : self::SORT_LATEST;
    }

    public static function locales(): array
    {
        $locales = null;
        try {
            $locales = Language::query()->where('status', true)->pluck('code')->filter()->values()->all();
        } catch (\Throwable) {
            // 数据库暂时不可用时仍保留固定的搜索语言集合。
        }

        if ($locales === null) {
            return self::INDEX_LOCALES;
        }

        $locales = array_values(array_intersect(self::INDEX_LOCALES, $locales));

        return $locales ?: self::INDEX_LOCALES;
    }

    /**
     * 索引名包含商品库标识，避免 CyberCloak 真实库和展示库的商品 ID 写进同一个索引。
     */
    public static function baseIndexName(string $catalog, string $locale): string
    {
        CatalogContext::assertCatalog($catalog);
        $locale = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $locale);

        return self::indexPrefix() . '_products_' . $catalog . '_' . $locale;
    }
}
