<?php

namespace Plugin\CyberCloak\Services;

use Beike\Models\Category;
use Beike\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogRouteService
{
    /**
     * 在同一个事务内重建商品和分类路由，供后台一键同步链接使用。
     *
     * @return array{version:string,product:int,category:int,unmapped:int}
     */
    public function rebuildAll(string $version = null, bool $allowUnmapped = false): array
    {
        $version = $version ?: $this->publishedMappingVersion();
        $main    = $this->mainConnection();
        if (! $version || ! $main->table('catalog_mapping_versions')->where('version', $version)->where('status', 'published')->exists()) {
            throw new \RuntimeException('稳定 URL 映射必须关联已发布的 SKU 映射版本');
        }

        $productRoutes  = $this->productRoutes($version);
        $categoryRoutes = $this->categoryRoutes($version);
        $unmapped       = count(array_filter($categoryRoutes, fn (array $route): bool => $route['public_record_id'] === null));
        if ($unmapped > 0 && ! $allowUnmapped) {
            throw new \RuntimeException("分类路由存在 {$unmapped} 个未匹配展示分类，请先处理商品映射");
        }

        $main->transaction(function () use ($main, $productRoutes, $categoryRoutes): void {
            $main->table('catalog_route_maps')->whereIn('route_type', ['product', 'category'])->delete();
            foreach (array_chunk(array_merge($productRoutes, $categoryRoutes), 500) as $chunk) {
                $main->table('catalog_route_maps')->insert($chunk);
            }
        });

        return [
            'version'  => $version,
            'product'  => count($productRoutes),
            'category' => count($categoryRoutes),
            'unmapped' => $unmapped,
        ];
    }

    /**
     * 重建商品或分类的稳定数字 URL 映射。
     *
     * @return array{type:string,version:string,routes:int,unmapped:int,mapped_public_categories:int}
     */
    public function rebuild(string $type, string $version = null, bool $allowUnmapped = false): array
    {
        if (! in_array($type, ['product', 'category'], true)) {
            throw new \InvalidArgumentException('路由类型只支持 product 或 category');
        }

        $version = $version ?: $this->publishedMappingVersion();
        $main    = $this->mainConnection();
        if (! $version || ! $main->table('catalog_mapping_versions')->where('version', $version)->where('status', 'published')->exists()) {
            throw new \RuntimeException('稳定 URL 映射必须关联已发布的 SKU 映射版本');
        }
        $routes  = $type === 'product'
            ? $this->productRoutes($version)
            : $this->categoryRoutes($version);

        $unmapped = count(array_filter($routes, fn (array $route): bool => $route['public_record_id'] === null));
        if ($type === 'category' && $unmapped > 0 && ! $allowUnmapped) {
            throw new \RuntimeException("分类路由存在 {$unmapped} 个未匹配展示分类；请补充商品映射，或使用 --allow-unmapped 排查");
        }

        $main->transaction(function () use ($main, $type, $routes): void {
            $main->table('catalog_route_maps')->where('route_type', $type)->delete();
            foreach (array_chunk($routes, 500) as $chunk) {
                $main->table('catalog_route_maps')->insert($chunk);
            }
        });

        return [
            'type'                     => $type,
            'version'                  => $version,
            'routes'                   => count($routes),
            'unmapped'                 => $unmapped,
            // 区分真实分类路由总数和实际被使用的 Cloak 分类数，便于排查随机映射覆盖率。
            'mapped_public_categories' => $type === 'category' ? $this->mappedPublicCategoryCount($routes) : 0,
        ];
    }

    /**
     * 根据前台数字 URL 解析商品，并把原始 URL 身份写入模型属性。
     */
    public function bindProduct(mixed $value, Route $route): ?Product
    {
        if (! ctype_digit((string) $value)) {
            return $this->defaultProductBinding($value);
        }

        $urlId    = (int) $value;
        $recordId = $this->recordId('product', $urlId);
        $product  = Product::query()->whereKey($recordId)->where('active', 1)->first();
        if (! $product) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$urlId]);
        }

        // 列表页和详情页都用同一个外部 URL 身份，避免展示库主键泄漏到链接中。
        $product->setAttribute('source_url_id', $urlId);

        return $product;
    }

    /**
     * 根据前台数字 URL 解析分类，并保留原始 URL 身份。
     */
    public function bindCategory(mixed $value, Route $route): ?Category
    {
        if (! ctype_digit((string) $value)) {
            return $this->defaultCategoryBinding($value);
        }

        $urlId    = (int) $value;
        $recordId = $this->recordId('category', $urlId);
        $category = Category::query()->whereKey($recordId)->where('active', 1)->first();
        if (! $category) {
            throw (new ModelNotFoundException)->setModel(Category::class, [$urlId]);
        }

        $category->setAttribute('source_url_id', $urlId);

        return $category;
    }

    /**
     * 获取商品当前模式对应的稳定外部 URL ID。
     */
    public function urlIdForProduct(Product $product): int
    {
        $sourceUrlId = (int) $product->getAttribute('source_url_id');
        if ($sourceUrlId > 0) {
            return $sourceUrlId;
        }

        return $this->urlId('product', (int) $product->getKey());
    }

    /**
     * 获取分类当前模式对应的稳定外部 URL ID。
     */
    public function urlIdForCategory(Category $category): int
    {
        $sourceUrlId = (int) $category->getAttribute('source_url_id');
        if ($sourceUrlId > 0) {
            return $sourceUrlId;
        }

        return $this->urlId('category', (int) $category->getKey());
    }

    /**
     * 获取当前稳定路由实际引用到的 Cloak 分类 ID。
     *
     * 返回 null 表示路由迁移尚未执行，调用方应保留旧版同 ID 兼容行为；
     * 返回空数组表示路由表存在但当前没有可用的展示分类路由。
     *
     * @return array<int,int>|null
     */
    public function mappedPublicCategoryIds(): ?array
    {
        try {
            if (! $this->routeTableExists()) {
                return null;
            }

            return $this->routeQuery()
                ->where('route_type', 'category')
                ->where('status', 'active')
                ->whereNotNull('public_record_id')
                ->pluck('public_record_id')
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            // 旧版本或迁移未完成时保留原有商品库分类，避免因诊断查询失败中断前台。
            return null;
        }
    }

    /**
     * 将后台菜单保存的真实商品或分类 ID 转换为稳定外部 URL ID。
     *
     * 后台菜单始终在真实库中维护，public 模式不能把它直接当作 Cloak 主键查询。
     */
    public function urlIdForRealRecord(string $type, int $realRecordId): int
    {
        if ($this->mode() === StoreContext::PUBLIC) {
            return $this->urlIdForPublicRealRecord($type, $realRecordId);
        }

        return $this->urlIdForColumn($type, 'real_record_id', $realRecordId);
    }

    /**
     * Cloak 模式只为存在展示记录的真实链接生成稳定 URL，避免 banner 跳转到 404。
     */
    private function urlIdForPublicRealRecord(string $type, int $realRecordId): int
    {
        if ($realRecordId <= 0) {
            return 0;
        }

        try {
            $route = $this->routeQuery()
                ->where('route_type', $type)
                ->where('status', 'active')
                ->where('real_record_id', $realRecordId)
                ->whereNotNull('public_record_id')
                ->orderBy('url_id')
                ->first();
            if ($route) {
                return (int) $route->url_id;
            }
            if ($this->routeTableExists()) {
                return 0;
            }
        } catch (\Throwable) {
            // 路由映射迁移尚未执行时保留同 ID 兼容行为。
        }

        return $realRecordId;
    }

    /**
     * 根据真实商品或分类 ID 查找当前映射对应的 Cloak 记录 ID。
     */
    public function publicRecordIdForRealRecord(string $type, int $realRecordId): int
    {
        if ($realRecordId <= 0) {
            return 0;
        }

        try {
            $publicRecordId = $this->routeQuery()
                ->where('route_type', $type)
                ->where('real_record_id', $realRecordId)
                ->where('status', 'active')
                ->value('public_record_id');
            if ($publicRecordId !== null) {
                return (int) $publicRecordId;
            }
            if ($this->routeTableExists()) {
                return 0;
            }
        } catch (\Throwable) {
            // 路由映射迁移尚未执行时保留同 ID 兼容行为。
        }

        return $realRecordId;
    }

    /**
     * 批量读取真实记录对应的 Cloak 记录，保留输入 ID 到展示 ID 的映射关系。
     *
     * 路由表尚未迁移时返回同 ID 兼容结果；路由表已经存在但记录未映射时不返回该 ID，
     * 由上层内容映射服务过滤掉未发布的首页商品。
     *
     * @param array<int,int|string> $realRecordIds
     * @return array<int,int>
     */
    public function publicRecordIdsForRealRecords(string $type, array $realRecordIds): array
    {
        $realRecordIds = array_values(array_unique(array_filter(array_map('intval', $realRecordIds), fn (int $id): bool => $id > 0)));
        if ($realRecordIds === []) {
            return [];
        }

        try {
            if (! $this->routeTableExists()) {
                return array_combine($realRecordIds, $realRecordIds);
            }

            return $this->routeQuery()
                ->where('route_type', $type)
                ->where('status', 'active')
                ->whereIn('real_record_id', $realRecordIds)
                ->whereNotNull('public_record_id')
                ->pluck('public_record_id', 'real_record_id')
                ->mapWithKeys(fn ($publicId, $realId): array => [(int) $realId => (int) $publicId])
                ->all();
        } catch (\Throwable) {
            // 路由映射查询异常时保留旧版本的同 ID 兼容行为，避免后台预览直接中断。
            return array_combine($realRecordIds, $realRecordIds);
        }
    }

    /**
     * 按当前模式把 URL 身份转换为商品库记录 ID。
     */
    private function recordId(string $type, int $urlId): int
    {
        $mode = $this->mode();

        try {
            $route = $this->routeQuery()
                ->where('route_type', $type)
                ->where('url_id', $urlId)
                ->where('status', 'active')
                ->first();
            if ($route) {
                return (int) ($mode === StoreContext::PUBLIC ? $route->public_record_id : $route->real_record_id);
            }
            if ($this->routeTableExists()) {
                return 0;
            }
        } catch (\Throwable) {
            // 路由映射迁移尚未执行时保留同 ID 兼容行为，便于分阶段上线。
        }

        return $urlId;
    }

    /**
     * 按当前模式把商品库记录 ID转换为对外 URL ID。
     */
    private function urlId(string $type, int $recordId): int
    {
        $column = $this->mode() === StoreContext::PUBLIC ? 'public_record_id' : 'real_record_id';

        return $this->urlIdForColumn($type, $column, $recordId);
    }

    /**
     * 按路由映射字段查询稳定 URL ID，映射表存在但没有记录时返回 0。
     */
    private function urlIdForColumn(string $type, string $column, int $recordId): int
    {
        if ($recordId <= 0) {
            return 0;
        }

        try {
            $route = $this->routeQuery()
                ->where('route_type', $type)
                ->where('status', 'active')
                ->where($column, $recordId)
                ->orderBy('url_id')
                ->first();
            if ($route) {
                return (int) $route->url_id;
            }
            if ($this->routeTableExists()) {
                return 0;
            }
        } catch (\Throwable) {
            // 兼容尚未建立路由映射表的旧数据。
        }

        return $recordId;
    }

    /**
     * 读取最新发布的映射版本。
     */
    private function publishedMappingVersion(): ?string
    {
        return $this->mainConnection()->table('catalog_mapping_versions')
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->value('version');
    }

    /**
     * 返回主库映射连接。
     */
    private function mainConnection()
    {
        return DB::connection((string) config('database.default', 'mysql'));
    }

    /**
     * 返回主库路由映射查询。
     */
    private function routeQuery()
    {
        return $this->mainConnection()->table('catalog_route_maps');
    }

    /**
     * 从已发布商品映射生成稳定商品 URL，允许多个真实商品指向同一展示商品。
     *
     * @return array<int,array<string,mixed>>
     */
    private function productRoutes(string $version): array
    {
        $real = DB::connection($this->connection(StoreContext::REAL))->table('products')
            ->where('active', 1)->whereNull('deleted_at')->pluck('id')->all();
        $publicIds = $this->mainConnection()->table('catalog_product_mappings')
            ->where('mapping_version', $version)->where('status', 'confirmed')
            ->whereNotNull('public_product_id')
            ->pluck('public_product_id', 'real_product_id')->all();
        $publicRecordIds = DB::connection($this->connection(StoreContext::PUBLIC))->table('products')
            ->where('active', 1)->whereNull('deleted_at')
            ->whereIn('id', array_values(array_map('intval', $publicIds)))
            ->pluck('id')->all();
        $publicRecordIds = array_fill_keys(array_map('intval', $publicRecordIds), true);
        $publicIds       = array_filter($publicIds, fn ($publicId): bool => isset($publicRecordIds[(int) $publicId]));
        $now             = now();

        return array_map(function ($realId) use ($publicIds, $version, $now): array {
            return [
                'route_type'       => 'product',
                'url_id'           => (int) $realId,
                'real_record_id'   => (int) $realId,
                'public_record_id' => isset($publicIds[$realId]) ? (int) $publicIds[$realId] : null,
                'status'           => 'active',
                'mapping_version'  => $version,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }, $real);
    }

    /**
     * 按已发布商品映射推导真实分类的展示分类，允许多条真实分类复用一个展示分类。
     *
     * @return array<int,array<string,mixed>>
     */
    private function categoryRoutes(string $version): array
    {
        $realConnection    = $this->connection(StoreContext::REAL);
        $publicConnection  = $this->connection(StoreContext::PUBLIC);
        $realCategories    = DB::connection($realConnection)->table('categories')->where('active', 1)->orderBy('parent_id')->orderBy('position')->orderBy('id')->get();
        $publicCategoryIds = DB::connection($publicConnection)->table('categories')
            ->where('active', 1)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $candidateCounts      = $this->categoryCandidateCounts($version, $realConnection, $publicConnection);
        $reachableCategoryIds = $this->candidatePublicCategoryIds($candidateCounts, $publicCategoryIds);
        $categoryData         = $realCategories->map(fn ($category): array => [
            'id'        => (int) $category->id,
            'parent_id' => (int) $category->parent_id,
        ])->all();
        $resolved = (new CatalogCategoryMappingResolver)->resolve(
            $categoryData,
            $candidateCounts,
            // 没有商品候选的真实根分类只可落到可售展示分类，避免导航出现空分类。
            $reachableCategoryIds,
            // 每个可售展示分类都要保留至少一个稳定 URL，不能被多数投票完全挤掉。
            $reachableCategoryIds,
        );
        $routes   = [];

        foreach ($realCategories as $category) {
            $categoryId = (int) $category->id;
            $routes[]   = [
                'route_type'       => 'category',
                'url_id'           => $categoryId,
                'real_record_id'   => $categoryId,
                'public_record_id' => $resolved[$categoryId] ?? null,
                'status'           => 'active',
                'mapping_version'  => $version,
                'created_at'       => now(),
                'updated_at'       => now(),
            ];
        }

        return array_values($routes);
    }

    /**
     * 根据真实商品所属分类、已发布商品映射和展示商品分类关系生成投票数据。
     *
     * 一个真实分类会汇总整个子树的商品；多个真实分类可以得到同一个展示分类。
     *
     * @return array<int,array<int,int>>
     */
    private function categoryCandidateCounts(string $version, string $realConnection, string $publicConnection): array
    {
        $realProductRows = DB::connection($realConnection)->table('product_categories as pc')
            ->join('products as p', 'p.id', '=', 'pc.product_id')
            ->where('p.active', 1)
            ->whereNull('p.deleted_at')
            ->select(['pc.category_id', 'pc.product_id'])
            ->get();
        if ($realProductRows->isEmpty()) {
            return [];
        }

        $realProductIds  = $realProductRows->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $productMappings = $this->mainConnection()->table('catalog_product_mappings')
            ->where('mapping_version', $version)
            ->where('status', 'confirmed')
            ->whereNotNull('public_product_id')
            ->whereIn('real_product_id', $realProductIds)
            ->get(['real_product_id', 'public_product_id']);
        if ($productMappings->isEmpty()) {
            return [];
        }

        $publicByReal     = [];
        $publicProductIds = [];
        foreach ($productMappings as $mapping) {
            $realProductId   = (int) $mapping->real_product_id;
            $publicProductId = (int) $mapping->public_product_id;
            if ($realProductId <= 0 || $publicProductId <= 0) {
                continue;
            }

            $publicByReal[$realProductId]       = $publicProductId;
            $publicProductIds[$publicProductId] = true;
        }
        if ($publicProductIds === []) {
            return [];
        }

        $publicProductRows = DB::connection($publicConnection)->table('product_categories as pc')
            ->join('products as p', 'p.id', '=', 'pc.product_id')
            ->join('categories as c', 'c.id', '=', 'pc.category_id')
            ->whereIn('pc.product_id', array_keys($publicProductIds))
            ->where('p.active', 1)
            ->whereNull('p.deleted_at')
            ->where('c.active', 1)
            ->select(['pc.product_id', 'pc.category_id'])
            ->get();
        $publicCategoriesByProduct = [];
        foreach ($publicProductRows as $row) {
            $publicCategoriesByProduct[(int) $row->product_id][] = (int) $row->category_id;
        }

        $candidateCounts = [];
        foreach ($realProductRows as $row) {
            $realProductId   = (int) $row->product_id;
            $publicProductId = $publicByReal[$realProductId] ?? null;
            if ($publicProductId === null) {
                continue;
            }

            foreach (array_unique($publicCategoriesByProduct[$publicProductId] ?? []) as $publicCategoryId) {
                $realCategoryId                                      = (int) $row->category_id;
                $candidateCounts[$realCategoryId][$publicCategoryId] = ($candidateCounts[$realCategoryId][$publicCategoryId] ?? 0) + 1;
            }
        }

        return $candidateCounts;
    }

    /**
     * 从商品投票中提取已确认映射实际覆盖的展示分类。
     *
     * 只有这些分类的商品可被展示库查询到；未覆盖分类不应仅因哈希兜底进入前台导航。
     *
     * @param array<int,array<int,int>> $candidateCounts
     * @param array<int,int>            $activePublicCategoryIds
     * @return array<int,int>
     */
    private function candidatePublicCategoryIds(array $candidateCounts, array $activePublicCategoryIds): array
    {
        $activeSet = array_fill_keys(array_map('intval', $activePublicCategoryIds), true);
        $result    = [];
        foreach ($candidateCounts as $candidates) {
            foreach ($candidates as $publicCategoryId => $votes) {
                $publicCategoryId = (int) $publicCategoryId;
                if ((int) $votes > 0 && isset($activeSet[$publicCategoryId])) {
                    $result[$publicCategoryId] = true;
                }
            }
        }

        $ids = array_map('intval', array_keys($result));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * 统计分类路由实际引用的去重 Cloak 分类数量。
     *
     * @param array<int,array<string,mixed>> $routes
     */
    private function mappedPublicCategoryCount(array $routes): int
    {
        return count(array_unique(array_filter(array_map(
            static fn (array $route): int => (int) ($route['public_record_id'] ?? 0),
            $routes,
        ), static fn (int $id): bool => $id > 0)));
    }

    /**
     * 判断路由映射表是否已经迁移；迁移前保留分阶段兼容行为。
     */
    private function routeTableExists(): bool
    {
        return Schema::connection((string) config('database.default', 'mysql'))->hasTable('catalog_route_maps');
    }

    /**
     * 返回模式对应连接名。
     */
    private function connection(string $mode): string
    {
        return (string) config("cyber_cloak.connections.{$mode}", $mode === StoreContext::PUBLIC ? 'catalog_public' : 'mysql');
    }

    /**
     * 获取当前请求模式，后台和命令行固定使用真实库。
     */
    private function mode(): string
    {
        $context = app(StoreContext::class);
        if ($context->isActive()) {
            return $context->mode();
        }

        return StoreContext::REAL;
    }

    /**
     * 非前台路由继续使用 Laravel 默认模型绑定。
     */
    private function defaultProductBinding(mixed $value): ?Product
    {
        return (new Product)->resolveRouteBinding($value);
    }

    /**
     * 非前台路由继续使用 Laravel 默认分类绑定。
     */
    private function defaultCategoryBinding(mixed $value): ?Category
    {
        return (new Category)->resolveRouteBinding($value);
    }
}
