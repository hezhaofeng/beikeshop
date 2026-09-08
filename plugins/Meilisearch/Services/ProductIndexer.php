<?php

namespace Plugin\Meilisearch\Services;

use Beike\Models\Product;
use Illuminate\Support\Carbon;
use Plugin\Meilisearch\Models\IndexState;
use RuntimeException;

class ProductIndexer
{
    public function __construct(
        private ?MeilisearchClient $client = null,
        private ?ProductDocument $documents = null,
    ) {
        $this->client    ??= new MeilisearchClient();
        $this->documents ??= new ProductDocument();
    }

    /**
     * 同步单个商品到指定商品库的所有语言索引。
     */
    public function syncProduct(int $productId, string $catalog = CatalogContext::REAL): void
    {
        CatalogContext::assertCatalog($catalog);

        CatalogContext::runAs($catalog, function () use ($productId, $catalog): void {
            $product = $this->productQuery($catalog)->withTrashed()->find($productId);
            if (! $product || $product->trashed()) {
                $this->deleteProduct($productId, $catalog);

                return;
            }

            foreach (Settings::locales() as $locale) {
                $index = $this->activeIndex($catalog, $locale);
                if (! $index) {
                    continue;
                }
                $this->client->waitForTask($this->client->addDocuments($index, [
                    $this->documents->make($product, $catalog, $locale),
                ]));
            }
        });
    }

    /**
     * 从指定商品库的索引中删除商品；不传商品库时清理全部索引。
     */
    public function deleteProduct(int $productId, string $catalog = null): void
    {
        $states = IndexState::query()->whereNotNull('active_index');
        if ($catalog !== null) {
            CatalogContext::assertCatalog($catalog);
            $states->where('catalog', $catalog);
        }

        foreach ($states->get() as $state) {
            try {
                $this->client->waitForTask($this->client->deleteDocument($state->active_index, $productId));
            } catch (\Throwable $e) {
                if (! str_contains(strtolower($e->getMessage()), 'index_not_found')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * 重建指定商品库和语言的全量索引，完成后原子切换活动索引并清理旧版本。
     */
    public function rebuild(string $catalog, string $locale, int $batchSize = null, callable $progress = null): string
    {
        CatalogContext::assertCatalog($catalog);

        return CatalogContext::runAs($catalog, function () use ($catalog, $locale, $batchSize, $progress): string {
            $batchSize = $batchSize ?: Settings::batchSize();
            $startedAt = now();
            $newIndex  = Settings::baseIndexName($catalog, $locale) . '_v' . now()->format('YmdHis');
            $oldIndex  = IndexState::query()->where('catalog', $catalog)->where('locale', $locale)->value('active_index');

            try {
                $this->client->waitForTask($this->client->createIndex($newIndex));
                $this->client->waitForTask($this->client->configureIndex($newIndex));

                $this->productQuery($catalog)->orderBy('products.id')->chunkById($batchSize, function ($products) use ($catalog, $locale, $newIndex, $progress) {
                    $payload = $products->map(fn (Product $product) => $this->documents->make($product, $catalog, $locale))->all();
                    $this->client->waitForTask($this->client->addDocuments($newIndex, $payload));
                    $progress && $progress(count($payload));
                }, 'products.id', 'id');

                // 捕获全量扫描期间发生的更新，降低切换新索引时丢失变更的概率。
                $this->productQuery($catalog)
                    ->withTrashed()
                    ->where('products.updated_at', '>=', $startedAt)
                    ->orderBy('products.id')
                    ->chunkById($batchSize, function ($products) use ($catalog, $locale, $newIndex) {
                        $payload = $products
                            ->reject(fn (Product $product) => $product->trashed())
                            ->map(fn (Product $product) => $this->documents->make($product, $catalog, $locale))
                            ->values()
                            ->all();
                        $this->client->waitForTask($this->client->addDocuments($newIndex, $payload));

                        foreach ($products->filter(fn (Product $product) => $product->trashed()) as $product) {
                            $this->client->waitForTask($this->client->deleteDocument($newIndex, (int) $product->id));
                        }
                    }, 'products.id', 'id');

                IndexState::query()->updateOrCreate(
                    ['catalog' => $catalog, 'locale' => $locale],
                    [
                        'active_index'    => $newIndex,
                        'last_updated_at' => $startedAt,
                        'last_product_id' => 0,
                    ]
                );

                if ($oldIndex && $oldIndex !== $newIndex) {
                    try {
                        $this->client->waitForTask($this->client->deleteIndex($oldIndex));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }

                return $newIndex;
            } catch (\Throwable $e) {
                try {
                    $this->client->waitForTask($this->client->deleteIndex($newIndex));
                } catch (\Throwable) {
                    // 清理失败不覆盖原始异常。
                }

                throw $e;
            }
        });
    }

    /**
     * 按更新时间游标增量同步指定商品库的商品变更。
     */
    public function syncChanged(string $catalog, string $locale, Carbon $since = null, int $overlapSeconds = 120, callable $progress = null): int
    {
        CatalogContext::assertCatalog($catalog);

        return CatalogContext::runAs($catalog, function () use ($catalog, $locale, $since, $overlapSeconds, $progress): int {
            $state = IndexState::query()->firstOrCreate(['catalog' => $catalog, 'locale' => $locale]);
            if (! $state->active_index) {
                throw new RuntimeException("商品库 {$catalog} 的语言 {$locale} 尚未建立活动索引，请先执行全量索引");
            }

            $watermarkTime = $since ?: $state->last_updated_at;
            $watermarkId   = $since ? 0 : (int) $state->last_product_id;
            $queryTime     = $watermarkTime?->copy()->subSeconds($overlapSeconds);
            $count         = 0;

            $cursorTime = $queryTime;
            $cursorId   = $queryTime && $watermarkTime && $queryTime->equalTo($watermarkTime) ? $watermarkId : 0;

            do {
                $query = $this->productQuery($catalog)->withTrashed()
                    ->orderBy('products.updated_at')
                    ->orderBy('products.id')
                    ->limit(Settings::batchSize());

                if ($cursorTime) {
                    $query->where(function ($builder) use ($cursorTime, $cursorId) {
                        $builder->where('products.updated_at', '>', $cursorTime)
                            ->orWhere(function ($sameTimestamp) use ($cursorTime, $cursorId) {
                                $sameTimestamp->where('products.updated_at', $cursorTime)
                                    ->where('products.id', '>', $cursorId);
                            });
                    });
                }

                $products = $query->get();
                foreach ($products as $product) {
                    if ($product->trashed()) {
                        $this->client->waitForTask($this->client->deleteDocument($state->active_index, (int) $product->id));
                    } else {
                        $this->client->waitForTask($this->client->addDocuments($state->active_index, [
                            $this->documents->make($product, $catalog, $locale),
                        ]));
                    }

                    $state->last_updated_at = $product->updated_at;
                    $state->last_product_id = $product->id;
                    $state->save();
                    $cursorTime = $product->updated_at;
                    $cursorId   = (int) $product->id;
                    $count++;
                    $progress && $progress($product);
                }
            } while ($products->count() === Settings::batchSize());

            return $count;
        });
    }

    /**
     * 统计指定商品库中应当进入索引的商品数量，供后台展示索引覆盖率。
     */
    public function sourceCount(string $catalog): int
    {
        CatalogContext::assertCatalog($catalog);

        return CatalogContext::runAs($catalog, fn (): int => $this->productQuery($catalog)->count());
    }

    private function activeIndex(string $catalog, string $locale): ?string
    {
        $state = IndexState::query()->firstOrCreate(['catalog' => $catalog, 'locale' => $locale]);

        return $state->active_index;
    }

    /**
     * 构建商品查询。
     *
     * 展示库必须复用 CyberCloak 的已发布 SKU 白名单：既过滤掉没有任何已发布 SKU 的商品，
     * 也让预加载的 skus 关联只包含已发布 SKU，索引内不会留下未发布的展示 SKU。
     */
    private function productQuery(string $catalog)
    {
        // categories.descriptions 和 category_paths 供分类名称检索使用，必须预加载以避免全量索引退化成 N+1。
        // category_paths 的 pathCategory 包含叶子分类的全部父级分类名称。
        $with = [
            'descriptions',
            'productCategories',
            'categories.descriptions',
            'categories.paths.pathCategory.descriptions',
        ];
        if (CatalogContext::constrained($catalog)) {
            $with['skus'] = fn ($query) => CatalogContext::constrainSkus($catalog, $query);
        } else {
            $with[] = 'skus';
        }

        $query = Product::query()->with($with);

        // 浏览量记录统一存于主库；展示库的商品 ID 空间独立，不能把主库浏览记录
        // 误关联到展示商品。展示库排序仍可使用最新、销量和后台排序字段。
        if ($catalog === CatalogContext::REAL) {
            $query->withCount('views');
        }

        if (CatalogContext::constrained($catalog)) {
            $query->whereHas('skus', fn ($builder) => CatalogContext::constrainSkus($catalog, $builder));
        }

        return $query;
    }
}
