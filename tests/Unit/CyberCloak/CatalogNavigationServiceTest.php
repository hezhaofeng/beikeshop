<?php

namespace Tests\Unit\CyberCloak;

use Beike\Models\Category;
use Beike\Models\CategoryDescription;
use Mockery;
use Plugin\CyberCloak\Services\CatalogNavigationService;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\StoreContext;
use Tests\TestCase;

class CatalogNavigationServiceTest extends TestCase
{
    /**
     * 清理 Mockery，避免导航服务的路由桩影响后续测试。
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Cloak 一级分类菜单应使用真实分类对应的稳定 URL ID，而非 Cloak 主键。
     */
    public function test_public_category_menu_uses_stable_real_url_id(): void
    {
        $routes = Mockery::mock(CatalogRouteService::class);
        $routes->shouldReceive('urlIdForCategory')
            ->once()
            ->with(Mockery::type(Category::class))
            ->andReturn(100019);
        $category = new Category(['active' => true]);
        $category->setRawAttributes(['id' => 1, 'parent_id' => 0], true);
        $category->setRelation('description', new CategoryDescription(['name' => 'Crimson']));
        $service = new class($routes) extends CatalogNavigationService
        {
            /**
             * 暴露菜单组装逻辑，保持测试不依赖展示库连接。
             *
             * @param iterable<int,Category> $categories
             * @return array<int,array<string,mixed>>
             */
            public function buildMenus(iterable $categories): array
            {
                return $this->buildPublicCategoryMenus($categories);
            }
        };

        $menus = $service->buildMenus([$category]);

        $this->assertSame('Crimson', $menus[0]['name']);
        $this->assertSame(shop_route('categories.show', ['category' => 100019]), $menus[0]['link']);
        $this->assertFalse($menus[0]['new_window']);
        $this->assertSame('', $menus[0]['badge']['name']);
    }

    /**
     * 没有稳定反向路由的 Cloak 分类不能进入前台导航，避免生成 categories/0。
     */
    public function test_public_category_menu_skips_unmapped_category(): void
    {
        $routes = Mockery::mock(CatalogRouteService::class);
        $routes->shouldReceive('urlIdForCategory')
            ->twice()
            ->with(Mockery::type(Category::class))
            ->andReturn(0, 100020);

        $unmapped = new Category;
        $unmapped->setRawAttributes(['id' => 5, 'parent_id' => 0], true);
        $unmapped->setRelation('description', new CategoryDescription(['name' => 'Unused']));
        $mapped = new Category;
        $mapped->setRawAttributes(['id' => 2, 'parent_id' => 0], true);
        $mapped->setRelation('description', new CategoryDescription(['name' => 'Old Gold']));

        $service = new class($routes) extends CatalogNavigationService
        {
            /**
             * 暴露菜单组装逻辑，验证未映射分类不会生成死链接。
             *
             * @param iterable<int,Category> $categories
             * @return array<int,array<string,mixed>>
             */
            public function buildMenus(iterable $categories): array
            {
                return $this->buildPublicCategoryMenus($categories);
            }
        };

        $menus = $service->buildMenus([$unmapped, $mapped]);

        $this->assertSame(['Old Gold'], array_column($menus, 'name'));
        $this->assertSame([shop_route('categories.show', ['category' => 100020])], array_column($menus, 'link'));
    }

    /**
     * 分类没有稳定路由时返回空链接，而不是把 0 拼进前台 URL。
     */
    public function test_unmapped_category_url_is_empty(): void
    {
        $routes = Mockery::mock(CatalogRouteService::class);
        $routes->shouldReceive('urlIdForCategory')
            ->once()
            ->with(Mockery::type(Category::class))
            ->andReturn(0);
        app()->instance(CatalogRouteService::class, $routes);

        $category = new Category;
        $category->setRawAttributes(['id' => 5, 'parent_id' => 0], true);

        $this->assertSame('', $category->url);
    }

    /**
     * public 模式应以 Cloak 一级分类替换全部真实分类菜单，并保留非分类菜单。
     */
    public function test_public_mode_replaces_real_category_menus_and_keeps_other_menus(): void
    {
        $context = new StoreContext;
        $context->activate(['mode' => StoreContext::PUBLIC]);
        app()->instance(StoreContext::class, $context);
        config()->set('bk.system.base.menu_setting', [
            'menus' => [
                ['link' => ['type' => 'category']],
                ['link' => ['type' => 'custom']],
                ['link' => ['type' => 'category']],
            ],
        ]);
        $service = new class(Mockery::mock(CatalogRouteService::class)) extends CatalogNavigationService
        {
            /**
             * 用固定 Cloak 分类验证替换顺序，避免测试依赖数据库数据。
             *
             * @return array<int,array<string,mixed>>
             */
            protected function publicCategoryMenus(): array
            {
                return [
                    ['name' => 'Crimson', 'link' => '/categories/100019', 'new_window' => false, 'badge' => ['name' => ''], 'isFull' => false],
                    ['name' => 'Old Gold', 'link' => '/categories/100020', 'new_window' => false, 'badge' => ['name' => ''], 'isFull' => false],
                ];
            }
        };

        $menus = $service->transform([
            ['name' => '真实分类 A', 'link' => '/categories/100019', 'children_group' => [['name' => '旧子菜单']]],
            ['name' => '促销', 'link' => '/sale'],
            ['name' => '真实分类 B', 'link' => '/categories/100020', 'children_group' => [['name' => '旧子菜单']]],
        ]);

        $this->assertSame(['Crimson', 'Old Gold', '促销'], array_column($menus, 'name'));
        $this->assertSame(['/categories/100019', '/categories/100020', '/sale'], array_column($menus, 'link'));
        $this->assertArrayNotHasKey('children_group', $menus[0]);
        $this->assertArrayNotHasKey('children_group', $menus[1]);

        $context->reset();
    }

    /**
     * 菜单设置缺失时应保留原始菜单，避免安装初期产生数组偏移警告。
     */
    public function test_missing_menu_setting_keeps_original_menus(): void
    {
        $context = new StoreContext;
        $context->activate(['mode' => StoreContext::PUBLIC]);
        app()->instance(StoreContext::class, $context);
        config()->set('bk.system.base.menu_setting', null);
        $menus = [['name' => '促销', 'link' => '/sale']];

        $result = (new CatalogNavigationService(Mockery::mock(CatalogRouteService::class)))->transform($menus);

        $this->assertSame($menus, $result);

        $context->reset();
    }
}
