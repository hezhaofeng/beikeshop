<?php

namespace Plugin\CyberCloakSimple;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Plugin\CyberCloakSimple\Services\AccessKeyService;
use Plugin\CyberCloakSimple\Services\AccessResolver;
use Plugin\CyberCloakSimple\Services\ContextTicketService;
use Plugin\CyberCloakSimple\Services\CountryResolver;
use Plugin\CyberCloakSimple\Services\MappingService;
use Plugin\CyberCloakSimple\Services\SettingService;
use Plugin\CyberCloakSimple\Services\StoreContext;

/**
 * CyberCloak Simple 插件启动入口。
 */
class Bootstrap
{
    /**
     * 注册配置、请求级服务、商品/分类路由和首页映射 Hook。
     */
    public function boot(): void
    {
        config()->set('cyber_cloak_simple', require __DIR__ . '/Config/cyber_cloak_simple.php');

        // 完整双库插件和轻量单库插件不能同时接管同一条商品路由。
        if ($this->fullPluginIsActive()) {
            logger()->warning('CyberCloak Simple 未启用：检测到 CyberCloak 已启用。');

            return;
        }

        app()->scoped(StoreContext::class, static fn (): StoreContext => new StoreContext);
        app()->singleton(SettingService::class);
        app()->singleton(AccessKeyService::class);
        app()->singleton(ContextTicketService::class);
        app()->scoped(CountryResolver::class);
        app()->scoped(AccessResolver::class);
        app()->singleton(MappingService::class);

        // DesignService 在商品列表生成前调用该 Hook，首页商品模块因此自动使用展示 ID。
        add_hook_filter('service.design.module.product_ids', function (array $ids): array {
            return app(MappingService::class)->mapHomepageProductIds($ids);
        });

        // Url::link 的通用 Hook 覆盖首页 Banner、菜单及图标中的商品/分类链接。
        add_hook_filter('url.link', function (array $link): array {
            return app(MappingService::class)->mapUrl($link);
        });

        // 商品和分类模型自身生成的链接走同一套源 ID 规则。
        add_hook_filter('model.product.url', function (array $data): array {
            return app(MappingService::class)->mapModelUrl('product', $data);
        });
        add_hook_filter('model.category.url', function (array $data): array {
            return app(MappingService::class)->mapModelUrl('category', $data);
        });

        // 将简单版映射的一键处理按钮挂到插件编辑页，首页仍直接读取两类统一映射。
        add_hook_blade('admin.plugin.form.after', function ($callback, $content, array $data = []): string {
            $plugin = $data['plugin'] ?? null;
            if (! $plugin || $plugin->code !== 'cyber_cloak_simple') {
                return '';
            }

            return view('CyberCloakSimple::admin.mapping')->render();
        });

        RouteFacade::bind('product', function (mixed $value, Route $route): mixed {
            if ($route->getName() === 'shop.products.show') {
                return app(MappingService::class)->bindProduct($value, $route);
            }

            return (new \Beike\Models\Product)->resolveRouteBinding($value);
        });
        RouteFacade::bind('category', function (mixed $value, Route $route): mixed {
            if ($route->getName() === 'shop.categories.show') {
                return app(MappingService::class)->bindCategory($value, $route);
            }

            return (new \Beike\Models\Category)->resolveRouteBinding($value);
        });
    }

    /**
     * 检测完整 CyberCloak 是否已启用，避免两个插件互相覆盖上下文。
     */
    private function fullPluginIsActive(): bool
    {
        try {
            return app()->bound('plugin') && app('plugin')->checkActive('cyber_cloak');
        } catch (\Throwable) {
            return false;
        }
    }
}
