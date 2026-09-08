<?php

namespace Plugin\Meilisearch\Controllers;

use Beike\Shop\Http\Resources\ProductSimple;
use Illuminate\Http\Request;
use Plugin\Meilisearch\Services\SearchService;

class TrendingController
{
    public function __construct(private ?SearchService $search = null)
    {
        $this->search ??= new SearchService();
    }

    public function __invoke(Request $request)
    {
        $products = $this->search->trendingProducts(locale(), 5);

        return view('product.search-product', [
            'products' => ProductSimple::collection($products)->jsonSerialize(),
            'class'    => '',
            'showAll'  => false,
        ]);
    }
}
