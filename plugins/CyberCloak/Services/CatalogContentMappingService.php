<?php

namespace Plugin\CyberCloak\Services;

class CatalogContentMappingService
{
    public function __construct(private readonly CatalogRouteService $routes)
    {
    }

    /**
     * 把首页装修保存的真实商品 ID转换为当前模式应查询的商品 ID。
     *
     * 真实模式保留后台配置的 ID；Cloak 模式读取已发布路由映射，未映射商品从展示列表中移除。
     * 输入顺序和重复项保持不变，确保装修排序不被运行时重排。
     *
     * @param mixed $productIds
     * @return array<int,int>
     */
    public function mapProductIds(mixed $productIds): array
    {
        $realProductIds = $this->normalizeIds($productIds);
        if (! $this->isPublicCatalog() || $realProductIds === []) {
            return $realProductIds;
        }

        $publicProductIds = $this->routes->publicRecordIdsForRealRecords('product', $realProductIds);

        return array_values(array_filter(array_map(
            fn (int $realId): int => (int) ($publicProductIds[$realId] ?? 0),
            $realProductIds,
        ), fn (int $publicId): bool => $publicId > 0));
    }

    /**
     * 统一处理装修器可能提交的纯 ID、带 id 字段数组和资源对象。
     *
     * @param mixed $ids
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
     * 判断当前是否为已经激活商品上下文的 Cloak 前台请求。
     */
    private function isPublicCatalog(): bool
    {
        if (! app()->bound(StoreContext::class)) {
            return false;
        }

        $context = app(StoreContext::class);

        return $context->isActive() && $context->isPublic();
    }
}
