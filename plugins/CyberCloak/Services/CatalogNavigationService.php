<?php

namespace Plugin\CyberCloak\Services;

use Beike\Models\Category;

class CatalogNavigationService
{
    /**
     * 注入稳定 URL 映射服务，避免导航直接暴露 Cloak 分类主键。
     */
    public function __construct(private readonly CatalogRouteService $routes)
    {
    }

    /**
     * public 模式将后台维护的真实分类菜单替换为去重后的 Cloak 一级分类。
     *
     * 后台菜单的分类值始终是主库 ID；Cloak 库只有一级分类时，保留原来的多级菜单会产生大量重复入口。
     * 非分类菜单由后台原始配置控制，继续按既有方式展示。
     *
     * @param array<int,array<string,mixed>> $menus
     * @return array<int,array<string,mixed>>
     */
    public function transform(array $menus): array
    {
        $context = app(StoreContext::class);
        if (! $context->isActive() || ! $context->isPublic()) {
            return $menus;
        }

        $menuSetting     = system_setting('base.menu_setting', []);
        $configuredMenus = is_array($menuSetting) ? ($menuSetting['menus'] ?? []) : [];
        if (! is_array($configuredMenus) || $configuredMenus === []) {
            return $menus;
        }

        $publicMenus = $this->publicCategoryMenus();
        if ($publicMenus === []) {
            return $menus;
        }

        $result   = [];
        $inserted = false;
        foreach ($menus as $index => $menu) {
            $type = $configuredMenus[$index]['link']['type'] ?? null;
            if ($type !== 'category') {
                $result[] = $menu;

                continue;
            }

            if (! $inserted) {
                array_push($result, ...$publicMenus);
                $inserted = true;
            }
        }

        return $result;
    }

    /**
     * 读取已映射的 Cloak 一级分类，并生成头部模板可直接渲染的菜单项。
     *
     * @return array<int,array<string,mixed>>
     */
    protected function publicCategoryMenus(): array
    {
        $query = Category::query()
            ->with('description')
            ->where('active', 1)
            ->where('parent_id', 0)
            ->orderBy('position')
            ->orderBy('id');

        // 路由表存在时只读取有反向 URL 的展示分类，避免未映射分类进入导航渲染。
        $mappedIds = $this->routes->mappedPublicCategoryIds();
        if ($mappedIds !== null) {
            $query->whereIn('id', $mappedIds);
        }

        return $this->buildPublicCategoryMenus($query->get());
    }

    /**
     * 将已查询的 Cloak 一级分类转换为前台菜单，链接始终保留真实分类的稳定 URL ID。
     *
     * @param iterable<int,Category> $categories
     * @return array<int,array<string,mixed>>
     */
    protected function buildPublicCategoryMenus(iterable $categories): array
    {
        $menus = [];

        foreach ($categories as $category) {
            $urlId = $this->routes->urlIdForCategory($category);
            $name  = (string) ($category->description?->name ?? '');
            if ($urlId <= 0 || $name === '') {
                continue;
            }

            $menus[] = [
                'name'       => $name,
                'link'       => shop_route('categories.show', ['category' => $urlId]),
                'new_window' => false,
                'badge'      => ['name' => ''],
                'isFull'     => false,
            ];
        }

        return $menus;
    }
}
