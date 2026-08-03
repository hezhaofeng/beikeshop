<?php

namespace Plugin\CyberCloak;

use Illuminate\Console\Scheduling\Schedule;
use Plugin\CyberCloak\Services\AccessKeyService;
use Plugin\CyberCloak\Services\CatalogCartItemService;
use Plugin\CyberCloak\Services\CatalogContentMappingService;
use Plugin\CyberCloak\Services\CatalogImportService;
use Plugin\CyberCloak\Services\CatalogNavigationService;
use Plugin\CyberCloak\Services\CatalogOrderService;
use Plugin\CyberCloak\Services\CatalogResolver;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\ContextTicketService;
use Plugin\CyberCloak\Services\HomeDesignMappingService;
use Plugin\CyberCloak\Services\IpAccessService;
use Plugin\CyberCloak\Services\IpProviderRegistry;
use Plugin\CyberCloak\Services\IpProviderSyncService;
use Plugin\CyberCloak\Services\IpRangeNormalizer;
use Plugin\CyberCloak\Services\MaxMindIpIntelligence;
use Plugin\CyberCloak\Services\SkuMappingService;
use Plugin\CyberCloak\Services\StoreContext;
use Plugin\CyberCloak\Services\TrafficFunnelService;

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
        app()->singleton(IpProviderRegistry::class);
        app()->singleton(IpRangeNormalizer::class);
        app()->singleton(IpProviderSyncService::class);
        app()->singleton(MaxMindIpIntelligence::class);
        // 漏斗设置允许后台即时变更，请求级构造避免常驻进程持有旧配置。
        app()->scoped(TrafficFunnelService::class);
        app()->singleton(HomeDesignMappingService::class);
        // 解析器会注入请求级 StoreContext，常驻进程中必须按请求作用域重新构造。
        app()->scoped(CatalogResolver::class);
        app()->singleton(CatalogRouteService::class);
        app()->singleton(CatalogContentMappingService::class);
        app()->singleton(CatalogImportService::class);
        app()->singleton(CatalogCartItemService::class);
        app()->singleton(CatalogNavigationService::class);
        app()->singleton(CatalogOrderService::class);
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

        $this->registerIpProviderSchedule();
    }

    /**
     * 注册供应商定时同步，调度进程每次启动时重新读取后台配置。
     */
    private function registerIpProviderSchedule(): void
    {
        if (! app()->runningInConsole() || ! app()->bound(Schedule::class)) {
            return;
        }

        $schedule = app(IpProviderSyncService::class)->configuration()['schedule'];
        $event    = app(Schedule::class)->command('cyber-cloak:sync-ip-provider');
        match ($schedule) {
            'every_15_minutes' => $event->everyFifteenMinutes(),
            'every_6_hours'    => $event->everySixHours(),
            'daily'            => $event->daily(),
            default            => $event->hourly(),
        };
        $event->withoutOverlapping();
    }
}
