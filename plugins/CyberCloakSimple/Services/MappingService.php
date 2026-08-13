<?php

namespace Plugin\CyberCloakSimple\Services;

use Beike\Models\Category;
use Beike\Models\Product;
use Beike\Repositories\SettingRepo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;

/**
 * 管理同一数据库内真实记录到展示记录的运行时映射。
 */
class MappingService
{
    /**
     * 注入请求级上下文和后台设置读取服务。
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly SettingService $settings,
    ) {
    }

    /**
     * 在展示模式中将路由参数解析为对应展示商品。
     */
    public function bindProduct(mixed $value, Route $route): ?Product
    {
        $id = $this->recordIdForRequest('product', $value);
        if ($id <= 0) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$value]);
        }

        $product = Product::query()->whereKey($id)->where('active', 1)->first();
        if (! $product) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$value]);
        }

        // 保留外部 URL 身份，列表中的展示记录仍会链接回映射源 ID。
        $product->setAttribute('source_url_id', $this->urlIdForRequest('product', (int) $value, $id));

        return $product;
    }

    /**
     * 在展示模式中将路由参数解析为对应展示分类。
     */
    public function bindCategory(mixed $value, Route $route): ?Category
    {
        $id = $this->recordIdForRequest('category', $value);
        if ($id <= 0) {
            throw (new ModelNotFoundException)->setModel(Category::class, [$value]);
        }

        $category = Category::query()->whereKey($id)->where('active', 1)->first();
        if (! $category) {
            throw (new ModelNotFoundException)->setModel(Category::class, [$value]);
        }

        // 保留外部 URL 身份，分类树与首页链接可持续使用映射源 ID。
        $category->setAttribute('source_url_id', $this->urlIdForRequest('category', (int) $value, $id));

        return $category;
    }

    /**
     * 首页商品模块在展示模式中只保留已映射的展示商品 ID。
     *
     * @param mixed $productIds
     * @return array<int,int>
     */
    public function mapHomepageProductIds(mixed $productIds): array
    {
        $ids = $this->normalizeIds($productIds);
        if (! $this->context->isPublic()) {
            return $ids;
        }

        return array_values(array_filter(array_map(
            fn (int $id): int => $this->publicIdForSource('product', $id),
            $ids,
        ), static fn (int $id): bool => $id > 0));
    }

    /**
     * 一键补齐同库商品或分类映射；已配置的真实到展示关系保持不变。
     *
     * @return array{type:string,added:int,total:int,mappings:array<int,array{real_id:int,public_id:int}>}
     */
    public function autoMap(string $type): array
    {
        $modelClass = match ($type) {
            'product' => Product::class,
            'category' => Category::class,
            default => throw new \InvalidArgumentException('只支持商品或分类映射'),
        };
        $settingName = "{$type}_mappings";
        $existing    = $this->mappings($type);
        $activeIds   = $modelClass::query()->where('active', 1)->orderBy('id')->pluck('id')->map(
            static fn (mixed $id): int => (int) $id
        )->all();
        $added = 0;
        foreach ($activeIds as $id) {
            if (isset($existing[$id])) {
                continue;
            }

            // 单库没有第二套商品身份时，先使用稳定同 ID 目标；人工配置的展示目标优先保留。
            $existing[$id] = $id;
            $added++;
        }

        $records = [];
        foreach ($existing as $sourceId => $publicId) {
            $records[] = ['real_id' => (int) $sourceId, 'public_id' => (int) $publicId];
        }
        usort($records, static fn (array $left, array $right): int => $left['real_id'] <=> $right['real_id']);
        SettingRepo::storeValue($settingName, $records, 'cyber_cloak_simple', 'plugin');

        return [
            'type'     => $type,
            'added'    => $added,
            'total'    => count($records),
            'mappings' => $records,
        ];
    }

    /**
     * 将首页装修链接替换为同一映射源的公开 URL；未配置的商品/分类链接清空。
     *
     * @param array{type?:mixed,value?:mixed,url?:mixed} $link
     * @return array{type?:mixed,value?:mixed,url?:mixed,handled?:bool}
     */
    public function mapUrl(array $link): array
    {
        if (! $this->context->isPublic()) {
            return $link;
        }

        $type = strtolower(trim((string) ($link['type'] ?? '')));
        if (! in_array($type, ['product', 'category'], true)) {
            return $link;
        }

        $value = $link['value'] ?? null;
        // Url::link 可直接接收 Eloquent 模型；模型 URL 会由 model.*.url Hook 处理，不能在此强制转换。
        if (! is_int($value) && ! is_string($value)) {
            return $link;
        }

        $sourceId = ctype_digit((string) $value) ? (int) $value : 0;
        if ($sourceId <= 0) {
            return $link;
        }
        if ($this->publicIdForSource($type, $sourceId) <= 0) {
            $link['url']     = '';
            $link['handled'] = true;

            return $link;
        }

        $link['url']     = $this->routeUrl($type, $sourceId);
        $link['handled'] = true;

        return $link;
    }

    /**
     * 为展示商品或分类输出映射源 URL，防止目标 ID 作为公开路由泄露。
     *
     * @param array{url?:string,product?:Product,category?:Category} $data
     * @return array{url?:string,product?:Product,category?:Category}
     */
    public function mapModelUrl(string $type, array $data): array
    {
        if (! $this->context->isPublic()) {
            return $data;
        }

        $model = $type === 'product' ? ($data['product'] ?? null) : ($data['category'] ?? null);
        if (! $model instanceof Model) {
            return $data;
        }

        $sourceId = (int) $model->getAttribute('source_url_id');
        if ($sourceId <= 0) {
            $sourceId = $this->sourceIdForPublicRecord($type, (int) $model->getKey());
        }
        $data['url'] = $sourceId > 0 ? $this->routeUrl($type, $sourceId) : '';

        return $data;
    }

    /**
     * 判断指定展示记录是否可出现在展示模式的分类树中。
     */
    public function isMappedPublicRecord(string $type, int $recordId): bool
    {
        return $recordId > 0 && $this->sourceIdForPublicRecord($type, $recordId) > 0;
    }

    /**
     * 把请求中的映射源 ID或展示目标 ID转换为当前应查询的记录 ID。
     */
    private function recordIdForRequest(string $type, mixed $value): int
    {
        if (! ctype_digit((string) $value)) {
            return 0;
        }

        $requested = (int) $value;
        if (! $this->context->isPublic()) {
            return $requested;
        }

        // 首页覆盖也属于当前请求的最终映射，详情页必须使用同一目标，避免首页点击后切回通用商品。
        $mapped = $this->publicIdForSource($type, $requested);
        if ($mapped > 0) {
            return $mapped;
        }

        return $this->sourceIdForPublicRecord($type, $requested) > 0 ? $requested : 0;
    }

    /**
     * 用请求 URL 优先保留源 ID；直接访问展示 ID时选择稳定的最小源 ID。
     */
    private function urlIdForRequest(string $type, int $requestedId, int $recordId): int
    {
        if ($this->publicIdForSource($type, $requestedId) === $recordId) {
            return $requestedId;
        }

        return $this->sourceIdForPublicRecord($type, $recordId);
    }

    /**
     * 查询某个真实源记录的展示目标。
     */
    private function publicIdForSource(string $type, int $sourceId): int
    {
        if ($sourceId <= 0) {
            return 0;
        }

        $mappings = $this->mappings($type);

        return (int) ($mappings[$sourceId] ?? 0);
    }

    /**
     * 查找展示记录的稳定源 ID，选择最小 ID避免配置顺序改变链接。
     */
    private function sourceIdForPublicRecord(string $type, int $publicId): int
    {
        $sources = [];
        foreach ($this->mappings($type) as $sourceId => $mappedPublicId) {
            if ($mappedPublicId === $publicId) {
                $sources[] = (int) $sourceId;
            }
        }

        if ($sources === []) {
            return 0;
        }

        sort($sources, SORT_NUMERIC);

        return $sources[0];
    }

    /**
     * 读取当前商品或分类的统一映射。
     *
     * @return array<int,int>
     */
    private function mappings(string $type): array
    {
        return $this->normalizeMappings($this->settings->records("{$type}_mappings"));
    }

    /**
     * 校验映射记录，只接受正整数 ID；相同源记录以最后一项为准。
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,int>
     */
    private function normalizeMappings(array $records): array
    {
        $result = [];
        foreach ($records as $record) {
            $sourceId = (int) ($record['real_id'] ?? 0);
            $publicId = (int) ($record['public_id'] ?? 0);
            if ($sourceId > 0 && $publicId > 0) {
                $result[$sourceId] = $publicId;
            }
        }

        return $result;
    }

    /**
     * 将装修器可能传入的 ID、数组或对象统一转换为正整数列表。
     *
     * @return array<int,int>
     */
    private function normalizeIds(mixed $ids): array
    {
        $result = [];
        foreach ((array) $ids as $item) {
            if (is_array($item)) {
                $item = $item['id'] ?? 0;
            } elseif (is_object($item)) {
                $item = $item->id ?? 0;
            }

            $id = (int) $item;
            if ($id > 0) {
                $result[] = $id;
            }
        }

        return $result;
    }

    /**
     * 返回商品或分类的前台路由。
     */
    private function routeUrl(string $type, int $sourceId): string
    {
        return $type === 'product'
            ? shop_route('products.show', ['product' => $sourceId])
            : shop_route('categories.show', ['category' => $sourceId]);
    }
}
