<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Models\Product;
use Beike\Repositories\CategoryRepo;
use Beike\Repositories\ProductRepo;
use Beike\Shop\Http\Resources\ProductDetail;
use Beike\Shop\Http\Resources\ProductSimple;
use Illuminate\Http\Request;
use Plugin\Bestseller\Repositories\ProductRepo as BestsellerProductRepo;
use Plugin\CyberCloak\Services\StoreContext;

class ProductController extends Controller
{
    /**
     * 商品详情页
     * @param Request $request
     * @param Product $product
     * @return mixed
     */
    public function show(Request $request, Product $product)
    {
        $context     = app()->bound(StoreContext::class) ? app(StoreContext::class) : null;
        $relationIds = $context?->isReal()
            ? $product->relations->pluck('id')->toArray()
            : [];
        $product     = ProductRepo::getProductDetail($product);
        ProductRepo::viewAdd($product);

        $data        = [
            'product'   => (new ProductDetail($product))->jsonSerialize(),
            'relations' => ProductRepo::getProductsByIds($relationIds)->jsonSerialize(),
            'has_video' => data_get($product, 'video'),
            'iconfont'  => '<i class="iconfont">&#xe628;</i>',
        ];

        $data = hook_filter('product.show.data', $data);

        return view('product/product', $data);
    }

    /**
     * 通过关键字搜索商品
     *
     * @param Request $request
     * @return mixed
     */
    public function search(Request $request)
    {
        $filters = $request->only(['keyword', 'attr', 'price', 'sort', 'order', 'per_page']);
        $perPage = (int) ($filters['per_page'] ?? perPage());
        if (! in_array($perPage, CategoryRepo::getPerPages(), true)) {
            $perPage = perPage();
        }

        $products = ProductRepo::getBuilder($filters)
            ->where('active', true)
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'products'  => $products,
            'items'     => ProductSimple::collection($products)->jsonSerialize(),
            'per_pages' => CategoryRepo::getPerPages(),
        ];

        $data = hook_filter('product.search.data', $data);

        return view('search', $data);
    }

    public function autocomplete(Request $request)
    {
        $isHtml   = $request->get('html')  ?? false;
        $class    = $request->get('class') ?? '';
        $limit    = $request->get('limit');
        $products = ProductRepo::autocomplete($request->get('name') ?? '', $limit);

        if ($isHtml) {
            $data = [
                'products' => $products,
                'class'    => $class,
            ];

            return view('product.search-product', $data);
        }

        return json_success(trans('common.get_success'), $products);
    }

    /**
     * 获取热销商品（用于搜索弹窗 AJAX 懒加载）
     */
    public function hotProducts()
    {
        $products = BestsellerProductRepo::getBestSellerProducts(5);

        return view('product.search-product', ['products' => $products, 'class' => '', 'showAll' => false]);
    }
}
