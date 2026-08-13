<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Repositories\BrandRepo;
use Beike\Shop\Http\Resources\ProductSimple;
use Illuminate\Http\Request;
use Plugin\CyberCloak\Services\SkuMappingService;

class BrandController extends Controller
{
    public function index()
    {
        $brands = BrandRepo::listGroupByFirst();
        $data   = [
            'brands' => $brands,
        ];

        $data = hook_filter('brand.index.data', $data);

        return view('brand/list', $data);
    }

    public function show(int $id)
    {
        $brand    = BrandRepo::find($id);
        if (empty($brand)) {
            return redirect(shop_route('brands.index'));
        }

        $context = app(\Plugin\CyberCloak\Services\StoreContext::class);
        $with    = ['masterSku', 'description'];
        if (! ($context->isActive() && $context->isPublic())) {
            $with[] = 'inCurrentWishlist';
        }
        $products = $brand->products()
            ->where('active', 1)
            ->with($with);
        if ($context->isActive() && $context->isPublic()) {
            $mappings = app(SkuMappingService::class);
            $products->whereHas('skus', fn ($query) => $mappings->constrainToPublishedPublicSkus($query));
            $products->with(['masterSku' => fn ($query) => $mappings->constrainToPublishedPublicSkus($query)]);
        }
        $products = $products->paginate(perPage());

        $data = [
            'brand'           => $brand,
            'products'        => $products,
            'products_format' => ProductSimple::collection($products)->jsonSerialize(),
        ];

        $data = hook_filter('brand.show.data', $data);

        return view('brand/info', $data);
    }

    public function autocomplete(Request $request)
    {
        $brands = BrandRepo::autocomplete($request->get('name') ?? '');

        return json_success(trans('common.get_success'), $brands);
    }
}
