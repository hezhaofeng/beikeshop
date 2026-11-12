<?php

namespace Plugin\Bestseller\Services;

use Beike\Repositories\ProductRepo;
use Beike\Services\StateMachineService;
use Illuminate\Support\Facades\DB;
use Plugin\CyberCloak\Services\CatalogContentMappingService;

class AllTimeSalesService
{
    /**
     * 按所有有效订单的销售件数取真实商品 ID，不依赖商品累计销量字段或当前展示库。
     */
    public function rankedProductIds(int $limit, int $offset = 0): array
    {
        // 订单始终保存在默认业务库，不能随前台商品上下文切换到展示库。
        return DB::connection()->table('order_products as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->join('products as p', 'p.id', '=', 'op.product_id')
            ->whereIn('o.status', StateMachineService::getValidStatuses())
            ->whereNull('o.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.active', 1)
            ->where('op.quantity', '>', 0)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('product_skus as ps')
                    ->whereColumn('ps.product_id', 'p.id')
                    ->where('ps.is_default', 1);
            })
            ->groupBy('op.product_id')
            ->orderByRaw('SUM(op.quantity) DESC')
            ->orderByRaw('MAX(o.created_at) DESC')
            ->orderByDesc('op.product_id')
            ->offset(max(0, $offset))
            ->limit(max(1, min($limit, 500)))
            ->pluck('op.product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 返回当前商品库可展示的榜单，未映射或已失效的商品由后续名次补足。
     */
    public function products(int $limit): array
    {
        $limit     = max(1, min($limit, 500));
        $batchSize = max(50, $limit);
        $products  = [];

        for ($offset = 0; count($products) < $limit; $offset += $batchSize) {
            $ids = $this->rankedProductIds($batchSize, $offset);
            if ($ids === []) {
                break;
            }

            $hasMore = count($ids) === $batchSize;

            if (app()->bound(CatalogContentMappingService::class)) {
                $ids = app(CatalogContentMappingService::class)->mapProductIds($ids);
            }

            foreach ($this->loadProducts(array_values(array_unique($ids))) as $product) {
                $products[$product['id']] ??= $product;
            }

            if (! $hasMore) {
                break;
            }
        }

        return array_slice(array_values($products), 0, $limit);
    }

    protected function loadProducts(array $ids): array
    {
        return $ids === [] ? [] : ProductRepo::getProductsByIds($ids)->jsonSerialize();
    }
}
