<?php

/**
 * ProductRepo.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-23 11:19:23
 * @modified   2022-06-23 11:19:23
 */

namespace Beike\Repositories;

use Beike\Models\Attribute;
use Beike\Models\AttributeValue;
use Beike\Models\Product;
use Beike\Models\ProductAttribute;
use Beike\Models\ProductCategory;
use Beike\Models\ProductDescription;
use Beike\Models\ProductRelation;
use Beike\Models\ProductSku;
use Beike\Shop\Http\Resources\ProductSimple;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\HigherOrderBuilderProxy;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Plugin\CyberCloak\Services\SkuMappingService;
use Plugin\CyberCloak\Services\StoreContext;

class ProductRepo
{
    private static $allProductsWithName = [];

    private static function getLikeOperator(): string
    {
        return getDBDriver() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * 获取商品详情
     */
    public static function getProductDetail($product)
    {
        if (is_int($product)) {
            $product = Product::query()->findOrFail($product);
        }
        $relations = ['description', 'brand'];
        if (! self::isPublicCatalog()) {
            $relations[] = 'skus';
            $relations[] = 'masterSku';
            $relations[] = 'relations';
        } else {
            // 展示库只导入浏览数据，没有主库的商品关系和属性表。
            $mappings = app(SkuMappingService::class);
            // 映射索引位于展示库，避免把数万 SKU ID 读入 PHP 再拼接 WHERE IN。
            $relations['skus']      = fn ($query) => $mappings->constrainToPublishedPublicSkus($query);
            $relations['masterSku'] = fn ($query) => $mappings->constrainToPublishedPublicSkus($query);
            $product->setRelation('relations', new Collection);
            $product->setRelation('attributes', new Collection);
        }
        $product->load($relations);

        if (self::isPublicCatalog() && empty($product->masterSku)) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Product::class, [$product->getKey()]);
        }

        hook_filter('repo.product.get_detail', $product);

        return $product;
    }

    /**
     * 通过单个或多个商品分类获取商品列表
     *
     * @param $categoryId
     * @param $filterData
     * @return LengthAwarePaginator
     * @throws \Exception
     */
    public static function getProductsByCategory($categoryId, $filterData)
    {
        $builder = static::getBuilder(array_merge(['category_id' => $categoryId, 'active' => 1], $filterData));
        if (! self::isPublicCatalog()) {
            $builder->with('inCurrentWishlist');
        }

        return $builder
            ->paginate($filterData['per_page'] ?? perPage())
            ->withQueryString();
    }

    /**
     * 通过商品ID获取商品列表
     * @param $productIds
     * @return AnonymousResourceCollection
     * @throws \Exception
     */
    public static function getProductsByIds($productIds): AnonymousResourceCollection
    {
        if (! $productIds) {
            return ProductSimple::collection(new Collection);
        }
        $builder = static::getBuilder(['product_ids' => $productIds, 'active' => 1])->whereHas('masterSku');
        if (! self::isPublicCatalog()) {
            $builder->with('inCurrentWishlist');
        }
        $products = $builder->get();

        return ProductSimple::collection($products);
    }

    /**
     * 获取商品筛选对象
     *
     * @param array $filters
     * @return Builder
     * @throws \Exception
     */
    public static function getBuilder(array $filters = []): Builder
    {
        $driver            = getDBDriver();
        $likeOperator      = self::getLikeOperator();

        $with = ['description', 'brand'];
        if (self::isPublicCatalog()) {
            // 展示商品只能暴露当前已发布版本中有 confirmed 映射的 SKU。
            // with() 约束接收 HasMany 关系对象，应避免声明为 Eloquent Builder。
            $mappings           = app(SkuMappingService::class);
            $with['skus']       = fn ($query) => $mappings->constrainToPublishedPublicSkus($query);
            $with['masterSku']  = fn ($query) => $mappings->constrainToPublishedPublicSkus($query);
        } else {
            $with[] = 'skus';
            $with[] = 'masterSku';
        }
        if (! self::isPublicCatalog()) {
            $with[] = 'attributes';
        }
        $builder = Product::query()->with($with);

        if (self::isPublicCatalog()) {
            $builder->whereHas('skus', fn (Builder $query) => $mappings->constrainToPublishedPublicSkus($query));
        }

        $builder->leftJoin('product_descriptions as pd', function ($build) {
            $build->whereColumn('pd.product_id', 'products.id')
                ->where('locale', locale());
        });

        $builder->select(['products.*', 'pd.name', 'pd.content', 'pd.meta_title', 'pd.meta_description', 'pd.meta_keywords', 'pd.name']);

        if (isset($filters['category_id'])) {
            $builder->whereHas('categories', function ($query) use ($filters) {
                if (! system_setting('category_products_with_subcategory', 1)) {
                    if (is_array($filters['category_id'])) {
                        $query->whereIn('category_id', $filters['category_id']);
                    } else {
                        $query->where('category_id', $filters['category_id']);
                    }
                } else {
                    $categoryId = $filters['category_id'];
                    if (self::isPublicCatalog()) {
                        // 展示库没有 category_paths，阶段三按直接商品分类关系查询。
                        is_array($categoryId)
                            ? $query->whereIn('category_id', $categoryId)
                            : $query->where('category_id', $categoryId);
                    } else {
                        $query->whereHas('paths', function ($query) use ($categoryId) {
                            if (is_array($categoryId)) {
                                $query->whereIn('path_id', $categoryId);
                            } else {
                                $query->where('path_id', $categoryId);
                            }
                        });
                    }
                }

            });
        }

        $brandId = $filters['brand_id'] ?? 0;
        if ($brandId) {
            $builder->where('brand_id', $brandId);
        }

        $productIds = array_values(array_filter(array_map('intval', (array) ($filters['product_ids'] ?? [])), function ($id) {
            return $id > 0;
        }));
        if ($productIds) {
            $builder->whereIn('products.id', $productIds);

            if ($driver == 'mysql') {
                $productIds = implode(',', $productIds);
                $builder->orderByRaw("FIELD(products.id, {$productIds})");
            } else {

                $caseWhen = collect($productIds)->map(function ($id, $index) {
                    return "WHEN products.id = $id THEN $index";
                })->implode(' ');

                $builder->orderByRaw("CASE $caseWhen ELSE 99999999 END");
            }
        }

        // attr 格式:attr=10:10,13|11:34,23|3:4
        if (isset($filters['attr']) && $filters['attr']) {
            if (! self::isPublicCatalog()) {
                $attributes = self::parseFilterParamsAttr($filters['attr']);
                foreach ($attributes as $attribute) {
                    $builder->whereHas('attributes', function ($query) use ($attribute) {
                        $query->where('attribute_id', $attribute['attr'])
                            ->whereIn('attribute_value_id', $attribute['value']);
                    });
                }
            }
        }

        if (isset($filters['sku']) || isset($filters['model'])) {
            $builder->whereHas('skus', function ($query) use ($filters) {
                if (isset($filters['sku'])) {
                    $query->where('sku', 'like', "%{$filters['sku']}%");
                }
                if (isset($filters['model'])) {
                    $query->where('model', 'like', "%{$filters['model']}%");
                }
            });
        }

        if (isset($filters['price']) && $filters['price']) {
            $rate = current_currency_rate();
            $builder->whereHas('skus', function ($query) use ($driver, $filters, $rate) {
                $prices = explode('-', $filters['price']);

                // 转换回基准货币
                $min = isset($prices[0]) && is_numeric($prices[0]) ? floatval($prices[0]) / $rate : 0;
                $max = isset($prices[1]) && is_numeric($prices[1]) ? floatval($prices[1]) / $rate : null;

                $query->where('is_default', $driver == 'mysql' ? 1 : true);

                if (is_null($max)) {
                    $query->where('price', '>=', $min);
                } else {
                    $query->whereBetween('price', [$min, $max]);
                }
            });
        }

        if (isset($filters['name'])) {
            $builder->where('pd.name', $likeOperator, "%{$filters['name']}%");
        }

        $keyword = trim($filters['keyword'] ?? '');
        if ($keyword) {
            $keywords = explode(' ', $keyword);
            $keywords = array_unique($keywords);
            $keywords = array_diff($keywords, ['']);
            $builder->where(function (Builder $query) use ($keywords, $likeOperator) {
                $query->whereHas('skus', function (Builder $query) use ($keywords, $likeOperator) {
                    $keywordFirst = array_shift($keywords);
                    $query->where('sku', $likeOperator, "%{$keywordFirst}%")
                        ->orWhere('model', $likeOperator, "%{$keywordFirst}%");

                    foreach ($keywords as $keyword) {
                        $query->orWhere('sku', $likeOperator, "%{$keyword}%")
                            ->orWhere('model', $likeOperator, "%{$keyword}%");
                    }
                });
                foreach ($keywords as $keyword) {
                    $query->orWhere('pd.name', $likeOperator, "%{$keyword}%");
                }
            });
        }

        if (isset($filters['created_start'])) {
            $builder->where('products.created_at', '>', $filters['created_start']);
        }

        if (isset($filters['created_end'])) {
            $builder->where('products.created_at', '<', $filters['created_end']);
        }

        if (isset($filters['active'])) {
            $builder->where('products.active', (int) $filters['active']);
        }

        // 回收站
        if (isset($filters['trashed']) && $filters['trashed']) {
            $builder->onlyTrashed();
        }

        $sort  = $filters['sort']  ?? 'products.created_at';
        $order = $filters['order'] ?? 'desc';

        if ($sort == 'product_skus.price') {
            $builder->join('product_skus', function ($query) {
                $query->on('product_skus.product_id', '=', 'products.id')
                    ->where('is_default', true);
            });
        }

        if (in_array($sort, ['created_at', 'updated_at', 'sales', 'position'])) {
            $sort = 'products.' . $sort;
        }

        if (in_array($sort, ['products.created_at', 'products.updated_at', 'products.sales', 'pd.name', 'products.position', 'product_skus.price'])) {
            $builder->orderBy($sort, $order);

            if ($sort === 'products.position') {
                $builder->orderBy('products.id', 'desc');
            }
        }

        return hook_filter('repo.product.builder', $builder);
    }

    public static function parseFilterParamsAttr($attr)
    {
        $attributes = explode('|', $attr);
        $attributes = array_map(function ($item) {
            $itemArr = explode(':', $item);
            if (count($itemArr) != 2) {
                throw new \Exception('Params attr has an error format!');
            }

            return [
                'attr'  => $itemArr[0],
                'value' => explode(',', $itemArr[1]),
            ];
        }, $attributes);

        return $attributes;
    }

    public static function getFilterAttribute($data): array
    {
        if (self::isPublicCatalog()) {
            return [];
        }

        $builder = static::getBuilder(array_diff_key($data, ['attr' => '', 'price' => '']))
            ->select(['pa.attribute_id', 'pa.attribute_value_id'])
            ->with(['attributes.attribute.description', 'attributes.attribute_value.description'])
            ->leftJoin('product_attributes as pa', 'pa.product_id', 'products.id')
            ->whereNotNull('pa.attribute_id')
            ->distinct()
            ->reorder('pa.attribute_id');

        if ($attributesIds = system_setting('base.multi_filter', [])['attribute'] ?? []) {
            $builder->whereIn('pa.attribute_id', $attributesIds);
        }

        $productAttributes = $builder->get()->toArray();

        $attributeMap      = array_column(Attribute::query()->with('description')->orderBy('sort_order')->get()->toArray(), null, 'id');
        $attributeValueMap = array_column(AttributeValue::query()->with('description')->get()->toArray(), null, 'id');

        $attributes    = isset($data['attr']) ? self::parseFilterParamsAttr($data['attr']) : [];
        $attributeMaps = array_column($attributes, 'value', 'attr');
        $results       = [];
        foreach ($productAttributes as $item) {
            if (! isset($attributeMap[$item['attribute_id']]) || ! isset($attributeValueMap[$item['attribute_value_id']])) {
                continue;
            }
            $attribute      = $attributeMap[$item['attribute_id']];
            $attributeValue = $attributeValueMap[$item['attribute_value_id']];
            if (! isset($results[$item['attribute_id']])) {
                $results[$item['attribute_id']] = [
                    'id'   => $attribute['id'],
                    'name' => $attribute['description']['name'],
                ];
            }
            if (! isset($results[$item['attribute_id']]['values'][$item['attribute_value_id']])) {
                $results[$item['attribute_id']]['values'][$item['attribute_value_id']] = [
                    'id'       => $attributeValue['id'],
                    'name'     => $attributeValue['description']['name'],
                    'selected' => in_array($attributeValue['id'], $attributeMaps[$attribute['id']] ?? []),
                ];
            }
        }

        $results = array_map(function ($item) {
            $item['values'] = array_values($item['values']);

            return $item;
        }, $results);

        return array_values($results);
    }

    public static function getFilterPrice($data)
    {
        $selectPrice    = $data['price'] ?? '-';
        $currencyRate   = current_currency_rate();
        $priceArr       = explode('-', $selectPrice);

        $builder = static::getBuilder(['category_id' => $data['category_id']])
            ->leftJoin('product_skus as ps', 'products.id', '=', 'ps.product_id')
            ->where('ps.is_default', 1);

        $minRaw = $builder->min('ps.price');
        $maxRaw = $builder->max('ps.price');

        $min = round($minRaw * $currencyRate, 2);
        $max = round($maxRaw * $currencyRate, 2);

        $selectMin = floatval($priceArr[0] ?? 0);
        $selectMax = floatval($priceArr[1] ?? 0);

        return [
            'min'        => $min,
            'max'        => $max,
            'select_min' => ($selectMin && $selectMin > $min) ? $selectMin : $min,
            'select_max' => ($selectMax && $selectMax < $max) ? $selectMax : $max,
        ];
    }

    public static function list($data = [])
    {
        return static::getBuilder($data)->paginate($data['per_page'] ?? 20);
    }

    public static function autocomplete($name, $limit = 50)
    {
        $likeOperator = self::getLikeOperator();
        $products     = Product::query()->with('description')->where('active', 1)
            ->whereHas('description', function ($query) use ($name, $likeOperator) {
                $query->where('name', $likeOperator, "%{$name}%");
            });
        if (self::isPublicCatalog()) {
            $mappings = app(SkuMappingService::class);
            $products->whereHas('skus', fn (Builder $query) => $mappings->constrainToPublishedPublicSkus($query));
        }
        $products = $products->orderByDesc('created_at')->limit($limit)->get();

        return \Beike\Shop\Http\Resources\ProductSimple::collection($products)->jsonSerialize();
    }

    /**
     * 获取商品ID获取单个商品名称
     *
     * @param $id
     * @return HigherOrderBuilderProxy|mixed|string
     */
    public static function getNameById($id)
    {
        $product = Product::query()->find($id);
        if ($product) {
            return $product->description->name;
        }

        return '';
    }

    /**
     * 通过商品ID获取商品名称
     * @param $id
     * @return mixed|string
     */
    public static function getName($id)
    {
        return self::getNameById($id);
    }

    /**
     * 获取所有商品ID和名称列表
     *
     * @return array|null
     */
    public static function getAllProductsWithName(): ?array
    {
        $scope = self::catalogScope();
        if (array_key_exists($scope, self::$allProductsWithName)) {
            return self::$allProductsWithName[$scope];
        }

        $items      = [];
        $productIds = static::getBuilder()->select('products.id')->pluck('id');

        if ($productIds->isNotEmpty()) {
            $names = ProductDescription::query()
                ->whereIn('product_id', $productIds)
                ->where('locale', locale())
                ->select(['product_id', 'name'])
                ->get();

            foreach ($names as $row) {
                $items[$row->product_id] = [
                    'id'   => $row->product_id,
                    'name' => $row->name ?? '',
                ];
            }
        }

        return self::$allProductsWithName[$scope] = $items;
    }

    /**
     * @param $productIds
     * @return array
     */
    public static function getNames($productIds): array
    {
        $products = self::getListByProductIds($productIds);

        return $products->map(function ($product) {
            return [
                'id'    => $product->id,
                'name'  => $product->description->name ?? '',
                'image' => image_resize($product->image, 100, 100),
            ];
        })->toArray();
    }

    /**
     * 通过商品ID获取商品列表
     * @return array|Builder[]|Collection
     */
    public static function getListByProductIds(array $productIds)
    {
        if (empty($productIds)) {
            return collect();
        }

        $productIds = array_map('intval', $productIds);
        $query      = Product::query()
            ->with(['description'])
            ->whereIn('id', $productIds);
        $driver = $query->getModel()->getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $idList = implode(',', $productIds);
            $query->orderByRaw("FIELD(id, {$idList})");
        } else {
            $idList = implode(',', $productIds);
            $query->orderByRaw("array_position(ARRAY[{$idList}]::bigint[], id)");
        }

        return $query->get();
    }

    public static function DeleteByIds($ids)
    {
        Product::query()->whereIn('id', $ids)->delete();
    }

    public static function updateStatusByIds($ids, $status)
    {
        Product::query()->whereIn('id', $ids)->update(['active' => $status]);
    }

    public static function forceDeleteTrashed()
    {
        $products = Product::onlyTrashed();

        $productsIds = $products->pluck('id')->toArray();

        ProductRelation::query()->whereIn('product_id', $productsIds)->orWhere('relation_id', $productsIds)->delete();
        ProductAttribute::query()->whereIn('product_id', $productsIds)->delete();
        ProductCategory::query()->whereIn('product_id', $productsIds)->delete();
        ProductSku::query()->whereIn('product_id', $productsIds)->delete();
        ProductDescription::query()->whereIn('product_id', $productsIds)->delete();

        $products->forceDelete();
    }

    public static function viewAdd(Product $product)
    {
        $minutes = system_setting('base.product_view_minutes', 1);
        $count   = $product->views()->where('session_id', get_session_id())->where('created_at', '>', now()->subMinutes($minutes))->count();
        // 如果当前session_id对该商品$minutes分钟内有个访问记录，则不重复记录访问次数。
        if ($count) {
            return;
        }
        $product->views()->create([
            'customer_id' => current_customer()->id ?? 0,
            'ip'          => request()->getClientIp(),
            'session_id'  => get_session_id(),
            'referer'     => request()->header('referer'),
            'user_agent'  => request()->header('user-agent'),
        ]);
    }

    /**
     * 判断当前是否处于展示商品库请求，集中避免访问展示库不存在的主库表。
     */
    private static function isPublicCatalog(): bool
    {
        $context = app(StoreContext::class);

        return $context->isActive() && $context->isPublic();
    }

    /**
     * 生成商品仓储静态缓存的商品库作用域。
     */
    private static function catalogScope(): string
    {
        $context = app(StoreContext::class);
        if ($context->isActive()) {
            return $context->mode() . '|' . locale();
        }

        return StoreContext::REAL . '|' . locale();
    }
}
