<?php

namespace Plugin\Meilisearch\Controllers;

use Beike\Repositories\ProductRepo;
use Beike\Shop\Http\Resources\ProductSimple;
use Illuminate\Http\Request;
use Plugin\Meilisearch\Services\SearchService;

/**
 * 用 Meilisearch 结果驱动搜索联想。
 *
 * 索引只提供有序的商品 ID，商品数据仍由 ProductRepo 回库读取，联想因此和列表页共享同一套
 * CyberCloak 可见性规则，不会把未发布的展示商品透出到下拉面板。
 */
class SuggestController
{
    public function __construct(private ?SearchService $search = null)
    {
        $this->search ??= new SearchService();
    }

    public function __invoke(Request $request)
    {
        $keyword = trim((string) $request->get('name'));
        $limit   = (int) ($request->get('limit') ?: 10);
        $ids     = $this->search->suggestIds($keyword, locale(), $limit);
        $items   = ProductSimple::collection($this->loadProducts($ids))->jsonSerialize();

        if ($request->get('html')) {
            return view('product.search-product', [
                'products' => $items,
                'class'    => $request->get('class') ?? '',
            ]);
        }

        return json_success(trans('common.get_success'), $items);
    }

    /**
     * 按 Meilisearch 的相关度顺序回库读取商品，精确 SKU 命中因此保持在首位。
     *
     * @param array<int,int> $ids
     */
    private function loadProducts(array $ids)
    {
        if (! $ids) {
            return collect();
        }

        $products = ProductRepo::getBuilder(['product_ids' => $ids, 'active' => 1])
            ->whereHas('masterSku')
            ->get();
        $position = array_flip($ids);

        return $products
            ->sortBy(fn ($product) => $position[(int) $product->id] ?? PHP_INT_MAX)
            ->values();
    }
}
