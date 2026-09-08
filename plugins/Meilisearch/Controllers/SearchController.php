<?php

namespace Plugin\Meilisearch\Controllers;

use Beike\Repositories\CategoryRepo;
use Beike\Shop\Http\Resources\ProductSimple;
use Illuminate\Http\Request;
use Plugin\Meilisearch\Services\SearchService;

class SearchController
{
    public function __construct(private ?SearchService $search = null)
    {
        $this->search ??= new SearchService();
    }

    public function __invoke(Request $request)
    {
        $perPage = (int) $request->get('per_page', perPage());
        if (! in_array($perPage, CategoryRepo::getPerPages(), true)) {
            $perPage = perPage();
        }

        $products = $this->search->search(
            trim((string) $request->get('keyword')),
            locale(),
            max(1, (int) $request->get('page', 1)),
            $perPage
        );

        $data = [
            'products'  => $products,
            'items'     => ProductSimple::collection($products)->jsonSerialize(),
            'per_pages' => CategoryRepo::getPerPages(),
        ];

        return view('search', hook_filter('product.search.data', $data));
    }
}
