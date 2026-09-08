<?php

namespace Beike\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Agent;
use Throwable;

class SearchKeywordService
{
    private const DEDUPLICATION_MINUTES = 10;

    private const TRENDING_DAYS = 30;

    private const TRENDING_LIMIT = 10;

    private const TRENDING_CACHE_MINUTES = 5;

    private const RETENTION_DAYS = 90;

    /**
     * 记录一次有效搜索。失败时交由调用方降级，不能影响正常搜索请求。
     */
    public function record(Request $request): bool
    {
        $keyword = $this->normalizeKeyword((string) $request->query('keyword', ''));
        if ($keyword === '' || ! $this->isInitialSearch($request) || $this->isRobot($request)) {
            return false;
        }

        $locale  = mb_substr((string) locale(), 0, 20);
        $catalog = $this->currentCatalog();
        if (! $this->claimVisitorSearch($request, $catalog, $locale, $keyword)) {
            return false;
        }

        $now               = now();
        $connection        = DB::connection($this->primaryConnection());
        $normalizedKeyword = $this->normalizedForAggregation($keyword);
        $connection->table('search_keyword_daily_stats')->upsert([
            [
                'catalog'            => $catalog,
                'locale'             => $locale,
                'keyword'            => $keyword,
                'normalized_keyword' => $normalizedKeyword,
                'search_date'        => $now->toDateString(),
                'search_count'       => 1,
                'last_searched_at'   => $now,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ], ['catalog', 'locale', 'normalized_keyword', 'search_date'], [
            'keyword',
            'search_count' => $connection->raw('search_count + 1'),
            'last_searched_at',
            'updated_at',
        ]);
        Cache::forget($this->trendingCacheKey($catalog, $locale, self::TRENDING_LIMIT));
        $this->pruneExpiredStats($connection);

        return true;
    }

    /**
     * 获取当前商品库、当前语言最近 30 天的搜索热词。
     * 单次真实搜索也会进入排行，避免测试环境或低流量站点因最低次数门槛无法展示。
     *
     * @return array<int,string>
     */
    public function trending(string $locale, int $limit = self::TRENDING_LIMIT): array
    {
        $locale   = mb_substr(trim($locale), 0, 20);
        $catalog  = $this->currentCatalog();
        $limit    = max(1, min($limit, self::TRENDING_LIMIT));
        $cacheKey = $this->trendingCacheKey($catalog, $locale, $limit);

        try {
            return Cache::remember($cacheKey, now()->addMinutes(self::TRENDING_CACHE_MINUTES), function () use ($catalog, $locale, $limit): array {
                return DB::connection($this->primaryConnection())
                    ->table('search_keyword_daily_stats')
                    ->where('catalog', $catalog)
                    ->where('locale', $locale)
                    ->where('search_date', '>=', now()->subDays(self::TRENDING_DAYS - 1)->toDateString())
                    ->select('normalized_keyword')
                    ->selectRaw('MAX(keyword) AS keyword')
                    ->selectRaw('SUM(search_count) AS searches')
                    ->selectRaw('MAX(last_searched_at) AS last_searched_at')
                    ->groupBy('normalized_keyword')
                    ->orderByDesc('searches')
                    ->orderByDesc('last_searched_at')
                    ->limit($limit)
                    ->pluck('keyword')
                    ->map(static fn ($keyword): string => (string) $keyword)
                    ->all();
            });
        } catch (Throwable) {
            // 代码先于迁移发布或统计存储暂时不可用时，由组件回退到后台配置。
            return [];
        }
    }

    /**
     * 清理控制字符、多余空白并限制入库长度。
     */
    public function normalizeKeyword(string $keyword): string
    {
        $keyword = preg_replace('/[\x{0000}-\x{001F}\x{007F}]+/u', ' ', $keyword) ?? '';
        $keyword = preg_replace('/\s+/u', ' ', trim($keyword))                    ?? '';

        return mb_substr($keyword, 0, 100);
    }

    private function normalizedForAggregation(string $keyword): string
    {
        return mb_substr(mb_strtolower($keyword), 0, 100);
    }

    /**
     * 只统计首次打开搜索结果页；翻页、排序和筛选不代表新的搜索意图。
     */
    private function isInitialSearch(Request $request): bool
    {
        if ((int) $request->query('page', 1) !== 1) {
            return false;
        }

        foreach (['sort', 'order', 'attr', 'price'] as $parameter) {
            if ($request->filled($parameter)) {
                return false;
            }
        }

        return true;
    }

    private function isRobot(Request $request): bool
    {
        $userAgent = (string) $request->userAgent();

        return $userAgent === '' || (new Agent([], $userAgent))->isRobot();
    }

    /**
     * 同一会话短时间内重复刷新只计一次；缓存不可用时允许继续聚合，搜索本身仍由外层兜底。
     */
    private function claimVisitorSearch(Request $request, string $catalog, string $locale, string $keyword): bool
    {
        $sessionId = $request->hasSession() ? $request->session()->getId() : '';
        $visitor   = $sessionId !== ''
            ? 'session:' . $sessionId
            : 'request:' . $request->ip() . '|' . $request->userAgent();
        $signature = hash_hmac(
            'sha256',
            implode('|', [$catalog, $locale, $this->normalizedForAggregation($keyword), $visitor]),
            (string) config('app.key', 'beikeshop')
        );

        try {
            return Cache::add(
                'search_keyword_deduplicate:' . $signature,
                true,
                now()->addMinutes(self::DEDUPLICATION_MINUTES)
            );
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * CyberCloak 存在时隔离真实/展示商品库的热词，防止两套目录互相泄漏搜索趋势。
     */
    private function currentCatalog(): string
    {
        foreach ([
            'Plugin\\CyberCloak\\Services\\StoreContext',
            'Plugin\\CyberCloakSimple\\Services\\StoreContext',
        ] as $contextClass) {
            if (! class_exists($contextClass) || ! app()->bound($contextClass)) {
                continue;
            }

            $context = app($contextClass);
            if (method_exists($context, 'isActive') && ! $context->isActive()) {
                continue;
            }
            if (method_exists($context, 'isPublic') && $context->isPublic()) {
                return 'public';
            }
            if (method_exists($context, 'isReal') && $context->isReal()) {
                return 'real';
            }
        }

        return 'real';
    }

    private function primaryConnection(): string
    {
        return (string) config('database.default', 'mysql');
    }

    private function trendingCacheKey(string $catalog, string $locale, int $limit): string
    {
        return implode(':', [
            'search_keyword_trending',
            $catalog,
            $locale,
            self::TRENDING_DAYS,
            $limit,
        ]);
    }

    /**
     * 每天最多触发一次过期数据清理，避免按日聚合表无限增长。
     */
    private function pruneExpiredStats(mixed $connection): void
    {
        try {
            if (! Cache::add('search_keyword_stats_prune', true, now()->addDay())) {
                return;
            }

            $connection->table('search_keyword_daily_stats')
                ->where('search_date', '<', now()->subDays(self::RETENTION_DAYS)->toDateString())
                ->delete();
        } catch (Throwable) {
            // 清理失败不影响当次统计，下一次缓存锁失效后会自动重试。
        }
    }
}
