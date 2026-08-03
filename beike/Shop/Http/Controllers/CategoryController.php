<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Models\Category;
use Beike\Repositories\CategoryRepo;
use Beike\Repositories\FlattenCategoryRepo;
use Beike\Repositories\ProductRepo;
use Beike\Shop\Http\Resources\CategoryDetail;
use Beike\Shop\Http\Resources\ProductSimple;
use Illuminate\Http\Request;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\StoreContext;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        return redirect('/');
    }

    public function show(Request $request, Category $category, CatalogRouteService $routes)
    {
        if (! $category->active) {
            return redirect(shop_route('home.index'));
        }
        $filterData = $request->only('attr', 'price', 'sort', 'order', 'per_page');
        $products   = ProductRepo::getProductsByCategory($category->id, $filterData);
        $category->load('description');
        $filterData = array_merge($filterData, ['category_id' => $category->id, 'active' => 1]);

        // 分类详情的子分类必须与左侧分类树使用同一份稳定路由映射，避免生成死链接。
        $childrenQuery = $category->activeChildren();
        $context       = app(StoreContext::class);
        if ($context->isActive() && $context->isPublic()) {
            $mappedIds = $routes->mappedPublicCategoryIds();
            if ($mappedIds !== null) {
                $childrenQuery->whereIn('id', $mappedIds);
            }
        }

        $children = $childrenQuery->with('description')->get();
        // 保留关系加载状态，分类资源和主题模板可继续按原方式读取子分类。
        $category->setRelation('activeChildren', $children);

        $data       = [
            'all_categories'  => FlattenCategoryRepo::getCategoryList(),
            'category'        => $category,
            'children'        => CategoryDetail::collection($children)->jsonSerialize(),
            'filter_data'     => [
                'attr'  => ProductRepo::getFilterAttribute($filterData),
                'price' => ProductRepo::getFilterPrice($filterData),
            ],
            'products_format' => ProductSimple::collection($products)->jsonSerialize(),
            'products'        => $products,
            'per_pages'       => CategoryRepo::getPerPages(),
        ];

        $data = hook_filter('category.show.data', $data);

        return view('category', $data);
    }
}
