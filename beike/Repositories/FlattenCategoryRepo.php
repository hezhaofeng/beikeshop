<?php

/**
 * FlattenCategoryRepo.php
 *
 * @copyright  2024 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2024-01-24 16:00:54
 * @modified   2024-01-24 16:00:54
 */

namespace Beike\Repositories;

use Beike\Models\Category;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Plugin\CyberCloak\Services\CatalogResolver;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\StoreContext;

class FlattenCategoryRepo
{
    public static $categories = [];

    public static $children = [];

    private static $allCategories = null;

    private const CACHE_TTL = 86400;

    /**
     * 将顶级分类ID和所有的子分类ID组合成一个数组
     *
     * @param array $topCategoryIds
     * @param array $subCategoryIds
     * @return array
     */
    public static function generateAllCategoryIds(array $topCategoryIds, array $subCategoryIds): array
    {
        $result = [];
        foreach ($topCategoryIds as $topCategoryId) {
            $result[]          = $topCategoryId;
            $allSubCategoryIds = $subCategoryIds[$topCategoryId] ?? [];
            if (empty($allSubCategoryIds)) {
                continue;
            }
            $result = array_merge($result, $allSubCategoryIds);
        }

        return array_unique($result);
    }

    /**
     * 获取某些分类ID的所有子分类ID
     *
     * @param array $categoryIds
     * @return array
     */
    public static function getAllSubCategoryIdsByCategoryIds(array $categoryIds): array
    {
        if (! $categoryIds) {
            return [];
        }
        $results = [];
        foreach ($categoryIds as $categoryId) {
            $subCategoryIds       = self::getAllSubCategoryIdsByCategoryId($categoryId);
            $results[$categoryId] = $subCategoryIds;
        }

        return $results;
    }

    /**
     * @param $parentId
     * @return array
     * @throws \Exception
     */
    public static function getCategoryList($parentId = 0): array
    {
        $cacheKey = self::cacheKey('tree', [
            (int) $parentId,
            locale(),
            (int) request('width', 300),
            (int) request('height', 300),
        ]);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($parentId) {
            return self::buildCategoryList((int) $parentId);
        });
    }

    /**
     * 获取某些分类下的子分类
     * @param $categoryIds
     * @return array
     */
    public static function getCategoriesByParentIds($categoryIds): array
    {
        if (! $categoryIds) {
            return [];
        }
        $result = [];
        foreach ($categoryIds as $categoryId) {
            $result = array_merge(self::getAllSubCategories($categoryId), $result);
        }

        return $result;
    }

    private static function getAllSubCategories($categoryId, &$result = [])
    {
        $categories = self::getAllCategories();
        foreach ($categories as $category) {
            if ((int) $category['parent_id'] !== (int) $categoryId) {
                continue;
            }

            $result[] = $category['id'];
            self::getAllSubCategories($category['id'], $result);
        }

        return $result;
    }

    /**
     * @param int $parentId
     * @return array
     * @throws \Exception
     */
    private static function buildCategoryList(int $parentId = 0): array
    {
        $categoryIds = self::getFlattenChildren($parentId);
        $categories  = self::getFlattenCategories($categoryIds);
        foreach ($categories as $index => $category) {
            $categoryId = $category['id'];
            $children   = self::buildCategoryList($categoryId);
            if ($children) {
                $categories[$index]['children'] = $children;
            }
        }

        return $categories;
    }

    /**
     * @return array
     * @throws \Exception
     */
    private static function getAllFlattenCategories(): array
    {
        $scope = self::scopeKey();
        if (isset(self::$categories[$scope])) {
            return self::$categories[$scope];
        }
        $width   = request('width', 300);
        $height  = request('height', 300);
        $builder = Category::query()
            ->with(['description'])
            ->select(['categories.id', 'categories.image', 'categories.parent_id', 'categories.active']);

        $categories = $builder->get();
        $visibleIds = self::visibleCategoryIds();
        $visibleSet = $visibleIds === null ? null : array_fill_keys($visibleIds, true);
        $result     = [];
        foreach ($categories as $category) {
            if ($visibleSet !== null && ! isset($visibleSet[(int) $category->id])) {
                continue;
            }

            $imagePath               = $category->image;
            $item['id']              = $category->id;
            $item['url']             = $category->url;
            $item['active']          = $category->active;
            $item['original_image']  = image_origin($imagePath);
            $item['image']           = image_resize($imagePath, $width, $height);
            $item['name']            = html_entity_decode($category->description->name ?? '');
            $result[$category['id']] = $item;
        }
        self::$categories[$scope] = $result;

        return $result;
    }

    /**
     * @param array $categoryIds
     * @return array
     * @throws \Exception
     */
    private static function getFlattenCategories(array $categoryIds = []): array
    {
        if (empty($categoryIds)) {
            return [];
        }
        $allCategories = self::getAllFlattenCategories();
        $result        = [];
        foreach ($categoryIds as $categoryId) {
            if (isset($allCategories[$categoryId])) {
                $result[] = $allCategories[$categoryId];
            }
        }

        return $result;
    }

    /**
     * @return array
     */
    private static function getAllFlattenChildren(): array
    {
        $scope = self::scopeKey();
        if (isset(self::$children[$scope])) {
            return self::$children[$scope];
        }
        $categories = self::catalogTable('categories')
            ->select(['id', 'parent_id'])
            ->orderBy('categories.position')
            ->orderBy('categories.parent_id')
            ->get();

        $parentById = [];
        foreach ($categories as $category) {
            $parentById[(int) $category->id] = (int) $category->parent_id;
        }

        $visibleIds = self::visibleCategoryIds();
        $visibleSet = $visibleIds === null ? null : array_fill_keys($visibleIds, true);
        $result     = [];
        foreach ($categories as $category) {
            $categoryId = (int) $category->id;
            if ($visibleSet !== null && ! isset($visibleSet[$categoryId])) {
                continue;
            }

            $parentId = self::nearestVisibleParent((int) $category->parent_id, $parentById, $visibleSet);
            $result[$parentId][] = $categoryId;
        }
        self::$children[$scope] = $result;

        return $result;
    }

    private static function getFlattenChildren($parentId = 0)
    {
        $allChildren = self::getAllFlattenChildren();

        return $allChildren[$parentId] ?? [];
    }

    /**
     * 获取某个分类ID的所有子分类ID
     *
     * @param int   $categoryId
     * @param array $subCategoryIds
     * @return array
     */
    private static function getAllSubCategoryIdsByCategoryId(int $categoryId, array &$subCategoryIds = []): array
    {
        $allCategories = self::getAllCategories();
        if (! $allCategories) {
            return $subCategoryIds;
        }
        foreach ($allCategories as $category) {
            if ($category['parent_id'] == $categoryId) {
                if (! in_array($category['id'], $subCategoryIds)) {
                    $subCategoryIds[] = $category['id'];
                }
                $subCategoryIds = self::getAllSubCategoryIdsByCategoryId($category['id'], $subCategoryIds);
            }
        }

        return $subCategoryIds;
    }

    /**
     * 获取所有分类， 只包含id, parent_id
     * @return array
     */
    private static function getAllCategories(): array
    {
        $scope = self::scopeKey();
        if (isset(self::$allCategories[$scope])) {
            return self::$allCategories[$scope];
        }
        $categories = self::catalogTable('categories')
            ->select(['id', 'parent_id'])
            ->get()
            ->all();
        $parentById = [];
        foreach ($categories as $category) {
            $parentById[(int) $category->id] = (int) $category->parent_id;
        }

        $visibleIds = self::visibleCategoryIds();
        $visibleSet = $visibleIds === null ? null : array_fill_keys($visibleIds, true);
        $allCategories = [];
        foreach ($categories as $category) {
            $categoryId = (int) $category->id;
            if ($visibleSet !== null && ! isset($visibleSet[$categoryId])) {
                continue;
            }

            $allCategories[] = [
                'id'        => $categoryId,
                'parent_id' => self::nearestVisibleParent((int) $category->parent_id, $parentById, $visibleSet),
            ];
        }
        self::$allCategories[$scope] = $allCategories;

        return $allCategories;
    }

    private static function cacheKey(string $name, array $parts = []): string
    {
        $parts[] = self::scopeKey();
        $parts[] = CategoryRepo::cacheVersion();

        return 'category.flatten.' . $name . '.' . md5(json_encode($parts));
    }

    /**
     * public 模式只展示有稳定反向路由的 Cloak 分类，避免生成 categories/0 死链接。
     * 路由表尚未迁移时返回 null，保持旧版本同 ID 兼容行为。
     *
     * @return array<int,int>|null
     */
    private static function visibleCategoryIds(): ?array
    {
        if (! app()->bound(StoreContext::class)) {
            return null;
        }

        $context = app(StoreContext::class);
        if (! $context->isActive() || ! $context->isPublic() || ! app()->bound(CatalogRouteService::class)) {
            return null;
        }

        return app(CatalogRouteService::class)->mappedPublicCategoryIds();
    }

    /**
     * 父分类被过滤时，把分类挂到最近的可见祖先；找不到祖先则提升为根分类。
     *
     * @param array<int,int> $parentById
     * @param array<int,bool>|null $visibleSet
     */
    private static function nearestVisibleParent(int $parentId, array $parentById, ?array $visibleSet): int
    {
        if ($visibleSet === null) {
            return $parentId;
        }

        $visited = [];
        while ($parentId > 0 && ! isset($visibleSet[$parentId])) {
            if (isset($visited[$parentId])) {
                return 0;
            }

            $visited[$parentId] = true;
            $parentId           = $parentById[$parentId] ?? 0;
        }

        return $parentId;
    }

    /**
     * 返回当前模式的分类表查询，避免展示模式误读主库。
     */
    private static function catalogTable(string $table)
    {
        if (app()->bound(CatalogResolver::class)) {
            return app(CatalogResolver::class)->table($table);
        }

        return DB::table($table);
    }

    /**
     * 为静态分类缓存生成包含商品库模式的隔离键。
     */
    private static function scopeKey(): string
    {
        $context = app(StoreContext::class);
        $mode    = $context->isActive() ? $context->mode() : StoreContext::REAL;
        $visibleIds = self::visibleCategoryIds();
        $routeScope = $visibleIds === null ? 'legacy' : sha1(json_encode($visibleIds));

        // 这些静态缓存还包含名称和图片尺寸，常驻进程必须隔离语言与请求尺寸。
        return implode('|', [$mode, locale(), (int) request('width', 300), (int) request('height', 300), $routeScope]);
    }
}
