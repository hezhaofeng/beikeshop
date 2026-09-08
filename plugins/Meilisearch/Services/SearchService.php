<?php

namespace Plugin\Meilisearch\Services;

use Beike\Repositories\ProductRepo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Plugin\Meilisearch\Models\IndexState;
use RuntimeException;

class SearchService
{
    public function __construct(
        private ?MeilisearchClient $client = null,
        private ?ProductDocument $documents = null,
    ) {
        $this->client    ??= new MeilisearchClient();
        $this->documents ??= new ProductDocument();
    }

    public function search(string $keyword, string $locale, int $page, int $perPage): LengthAwarePaginator
    {
        $catalog = CatalogContext::current();
        $index   = $this->activeIndex($catalog, $locale);

        $result = $this->client->search($index, $keyword, $page, $perPage, $this->sortOptions());
        $ids    = $this->hitIds($result);
        $total  = (int) ($result['totalHits'] ?? $result['estimatedTotalHits'] ?? count($ids));

        // 完全匹配 SKU 的商品必须排在首页最前，与 MySQL 搜索保持一致的下单体验。
        if ($page === 1 && ! $this->hasExplicitSort()) {
            $ids = $this->promoteExactSkuMatches($index, $keyword, $ids, $perPage);
        }

        $products = $this->loadProducts($ids);
        if ($ids && $products->isEmpty()) {
            // 索引命中但数据库一件都取不到，说明索引严重滞后，交给上层回退 MySQL 而不是返回空页。
            throw new RuntimeException('Meilisearch 命中的商品在数据库中不可见，索引可能已过期');
        }

        return new LengthAwarePaginator(
            $products,
            $total,
            $perPage,
            $page,
            [
                'path'  => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    /**
     * 为搜索联想返回按相关度排序的商品 ID，精确 SKU 命中排在最前。
     *
     * @return array<int,int>
     */
    public function suggestIds(string $keyword, string $locale, int $limit): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $limit   = max(1, min($limit, 50));
        $catalog = CatalogContext::current();
        $index   = $this->activeIndex($catalog, $locale);
        $result  = $this->client->search($index, $keyword, 1, $limit);

        return $this->promoteExactSkuMatches($index, $keyword, $this->hitIds($result), $limit);
    }

    /**
     * 读取搜索弹窗的 Trending Products。商品数据仍回数据库读取，保持商品库隔离和可见性规则。
     *
     * @return Collection<int, \Beike\Models\Product>
     */
    public function trendingProducts(string $locale, int $limit): Collection
    {
        $catalog = CatalogContext::current();
        $index   = $this->activeIndex($catalog, $locale);
        $limit   = max(1, min($limit, 50));
        $result  = $this->client->search($index, '', 1, $limit, $this->configuredSortOptions(Settings::trendingSort()));

        return $this->loadProducts($this->hitIds($result));
    }

    /**
     * 读取当前商品库和语言的活动索引。
     */
    private function activeIndex(string $catalog, string $locale): string
    {
        $index = IndexState::query()
            ->where('catalog', $catalog)
            ->where('locale', $locale)
            ->value('active_index');
        if (! $index) {
            throw new RuntimeException("商品库 {$catalog} 的语言 {$locale} 尚未建立活动索引");
        }

        return $index;
    }

    /**
     * 按 Meilisearch 返回的相关度顺序取回商品。
     *
     * ProductRepo 只做 whereIn，不保留传入顺序；这里必须按命中顺序重排，否则相关度最高的
     * 商品会被数据库默认排序淹没，搜索质量反而不如原有的 MySQL LIKE。
     *
     * @param array<int,int> $ids
     */
    private function loadProducts(array $ids): Collection
    {
        if (! $ids) {
            return collect();
        }

        $products = ProductRepo::getBuilder(['product_ids' => $ids])->where('products.active', true)->get();
        $position = array_flip($ids);

        return $products
            ->sortBy(fn ($product) => $position[(int) $product->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * 把精确命中 SKU 的商品提到结果最前，并保持整体条数不变。
     *
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    private function promoteExactSkuMatches(string $index, string $keyword, array $ids, int $limit): array
    {
        $exact = $this->exactSkuIds($index, $keyword, $limit);
        if (! $exact) {
            return $ids;
        }

        $merged = array_values(array_unique(array_merge($exact, $ids)));

        return array_slice($merged, 0, max($limit, count($ids)));
    }

    /**
     * 用规格化 SKU 做过滤查询，命中的是完整 SKU 或型号，不受分词和容错影响。
     *
     * @return array<int,int>
     */
    private function exactSkuIds(string $index, string $keyword, int $limit): array
    {
        $normalized = $this->documents->normalize($keyword);
        // 过短的关键词精确匹配意义不大，还会把大量无关商品顶到前面。
        if (mb_strlen($normalized) < 3) {
            return [];
        }

        try {
            $result = $this->client->search($index, '', 1, $limit, [
                'filter' => ['visible = true', 'sku_exact = ' . json_encode($normalized, JSON_UNESCAPED_UNICODE)],
            ]);
        } catch (\Throwable) {
            // 精确匹配只是排序增强，索引尚未包含 sku_exact 字段时静默跳过。
            return [];
        }

        return $this->hitIds($result);
    }

    /**
     * @return array<int,int>
     */
    private function hitIds(array $result): array
    {
        return collect($result['hits'] ?? [])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 判断用户是否显式指定了排序；显式排序时不再插入精确匹配结果。
     */
    private function hasExplicitSort(): bool
    {
        return $this->sortOptions() !== [];
    }

    private function sortOptions(): array
    {
        $sortMap = [
            'products.sales'      => 'sales',
            'pd.name'             => 'name',
            'product_skus.price'  => 'price',
            'products.position'   => 'position',
            'products.created_at' => 'created_at',
            'products.updated_at' => 'updated_at',
        ];
        $requested = (string) request('sort');
        $field     = $sortMap[$requested] ?? $sortMap['products.' . $requested] ?? null;
        if ($field) {
            $order = strtolower((string) request('order')) === 'desc' ? 'desc' : 'asc';

            return ['sort' => ["{$field}:{$order}"]];
        }

        return $this->configuredSortOptions(Settings::searchSort());
    }

    private function configuredSortOptions(string $sort): array
    {
        $field = Settings::sortField($sort);

        return $field ? ['sort' => ["{$field}:" . Settings::sortOrder($sort)]] : [];
    }
}
