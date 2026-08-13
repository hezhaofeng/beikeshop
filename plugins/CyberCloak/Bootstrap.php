<?php

namespace Plugin\CyberCloak;

use Plugin\CyberCloak\Services\AccessKeyService;
use Plugin\CyberCloak\Services\CatalogContentMappingService;
use Plugin\CyberCloak\Services\CatalogImportService;
use Plugin\CyberCloak\Services\CatalogNavigationService;
use Plugin\CyberCloak\Services\CatalogResolver;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\CloudIpRangeIntelligence;
use Plugin\CyberCloak\Services\ContextTicketService;
use Plugin\CyberCloak\Services\HomeBannerImageMappingService;
use Plugin\CyberCloak\Services\HomeDesignMappingService;
use Plugin\CyberCloak\Services\IpAccessService;
use Plugin\CyberCloak\Services\MaxMindIpIntelligence;
use Plugin\CyberCloak\Services\SkuMappingService;
use Plugin\CyberCloak\Services\StoreContext;
use Plugin\CyberCloak\Services\TrafficBehaviorService;
use Plugin\CyberCloak\Services\TrafficFunnelService;
use Plugin\CyberCloak\Services\TrafficRiskAuditService;

class Bootstrap
{
    /**
     * 注册阶段一配置和请求级服务。
     */
    public function boot(): void
    {
        config()->set('cyber_cloak', require __DIR__ . '/Config/cyber_cloak.php');

        // 使用 scoped 保证 Octane 等常驻进程不会把上一个请求的上下文带到下一个请求。
        app()->scoped(StoreContext::class, fn () => new StoreContext);
        app()->singleton(AccessKeyService::class);
        app()->singleton(ContextTicketService::class);
        app()->singleton(IpAccessService::class);
        app()->singleton(CloudIpRangeIntelligence::class);
        app()->singleton(MaxMindIpIntelligence::class);
        app()->singleton(TrafficBehaviorService::class);
        app()->singleton(TrafficRiskAuditService::class);
        // 漏斗设置允许后台即时变更，请求级构造避免常驻进程持有旧配置。
        app()->scoped(TrafficFunnelService::class);
        app()->singleton(HomeDesignMappingService::class);
        app()->singleton(HomeBannerImageMappingService::class);
        // 解析器会注入请求级 StoreContext，常驻进程中必须按请求作用域重新构造。
        app()->scoped(CatalogResolver::class);
        app()->singleton(CatalogRouteService::class);
        app()->singleton(CatalogContentMappingService::class);
        app()->singleton(CatalogImportService::class);
        app()->singleton(CatalogNavigationService::class);
        app()->singleton(SkuMappingService::class);

        // 头部菜单配置保存的是真实分类；展示模式转换为去重后的 Cloak 一级分类。
        add_hook_filter('menu.content', fn (array $menus): array => app(CatalogNavigationService::class)->transform($menus));

        // CyberCloak 设置较多，使用专属分组表单避免通用插件页过长且难以定位策略。
        add_hook_blade('admin.plugin.form', function ($callback, $content, array $data = []): string {
            $plugin = $data['plugin'] ?? null;
            if (! $plugin || $plugin->code !== 'cyber_cloak') {
                return $content;
            }

            return view('CyberCloak::admin.config_form', $data)->render();
        }, 1);

        // 将映射管理面板挂载到通用插件编辑页，设置字段和映射操作保持同一入口。
        add_hook_blade('admin.plugin.form.after', function ($callback, $content, array $data = []): string {
            $plugin = $data['plugin'] ?? null;
            if (! $plugin || $plugin->code !== 'cyber_cloak') {
                return '';
            }

            return view('CyberCloak::admin.mapping')->render();
        });

        // 显式绑定只改造前台商品和分类路由，后台同名参数继续使用默认绑定。
        \Illuminate\Support\Facades\Route::bind('product', function ($value, $route) {
            if ($route->getName() === 'shop.products.show') {
                return app(CatalogRouteService::class)->bindProduct($value, $route);
            }

            return (new \Beike\Models\Product)->resolveRouteBinding($value);
        });
        \Illuminate\Support\Facades\Route::bind('category', function ($value, $route) {
            if ($route->getName() === 'shop.categories.show') {
                return app(CatalogRouteService::class)->bindCategory($value, $route);
            }

            return (new \Beike\Models\Category)->resolveRouteBinding($value);
        });

    }
}
