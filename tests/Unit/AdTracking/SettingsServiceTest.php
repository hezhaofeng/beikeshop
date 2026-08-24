<?php

namespace Tests\Unit\AdTracking;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Plugin\AdTracking\Services\SettingsService;
use Plugin\AdTracking\Services\StatsService;

class SettingsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->instance('config', new Repository([
            'bk' => [
                'plugin' => [
                    'ad_tracking' => [],
                ],
            ],
        ]));

        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * 兼容旧版仅填写 Pixel/Token 的配置，新增平台开关后仍默认视为已启用。
     */
    public function test_legacy_platform_credentials_enable_platform_by_default(): void
    {
        config()->set('bk.plugin.ad_tracking', [
            'facebook_pixel_id' => 'fb-123',
            'enabled_events'    => ['PageView', 'Purchase'],
        ]);

        $settings = SettingsService::all();

        $this->assertTrue($settings['facebook_enabled']);
        $this->assertTrue(SettingsService::platformEnabled('facebook'));
        $this->assertTrue(SettingsService::eventEnabled('Purchase'));
        $this->assertFalse(SettingsService::eventEnabled('Search'));
    }

    /**
     * 非法和空事件会在配置层被过滤，只保留插件支持的标准事件。
     */
    public function test_enabled_events_are_normalized(): void
    {
        config()->set('bk.plugin.ad_tracking', [
            'enabled_events' => ['', 'Purchase', 'UnknownEvent', 'Search', 'Purchase'],
        ]);

        $this->assertSame(['Purchase', 'Search'], SettingsService::enabledEvents());
    }

    /**
     * 订单状态回传默认开启，但可独立关闭，不影响六个标准事件的历史配置。
     */
    public function test_order_status_events_can_be_disabled_independently(): void
    {
        $this->assertTrue(SettingsService::orderStatusEventsEnabled());

        config()->set('bk.plugin.ad_tracking', ['order_status_events' => false]);

        $this->assertFalse(SettingsService::orderStatusEventsEnabled());

        config()->set('bk.plugin.ad_tracking', ['order_status_events' => '0']);

        $this->assertFalse(SettingsService::orderStatusEventsEnabled());
    }

    /**
     * Facebook 多 Pixel ID 支持多行或逗号输入，并继续向旧单 ID 字段回填主 ID。
     */
    public function test_facebook_pixel_ids_are_normalized_and_exposed_to_public_config(): void
    {
        config()->set('bk.plugin.ad_tracking', [
            'facebook_pixel_ids' => "1625473505149626\n1625473505149626, 987654321000000",
        ]);

        $settings = SettingsService::all();

        $this->assertTrue($settings['facebook_enabled']);
        $this->assertSame('1625473505149626', $settings['facebook_pixel_id']);
        $this->assertSame('1625473505149626' . PHP_EOL . '987654321000000', $settings['facebook_pixel_ids']);
        $this->assertSame(['1625473505149626', '987654321000000'], SettingsService::facebookPixelIds($settings));
    }

    /**
     * Meta 分发模式决定浏览器和服务端分别使用哪些 Pixel。
     */
    public function test_facebook_dispatch_mode_controls_browser_and_server_pixel_targets(): void
    {
        $base = [
            'facebook_pixel_ids' => "1625473505149626\n987654321000000",
        ];

        $failover  = array_merge($base, ['facebook_dispatch_mode' => 'failover']);
        $broadcast = array_merge($base, ['facebook_dispatch_mode' => 'broadcast']);
        $single    = array_merge($base, ['facebook_dispatch_mode' => 'single']);

        $this->assertSame('failover', SettingsService::facebookDispatchMode($base));
        $this->assertSame(['1625473505149626'], SettingsService::facebookBrowserPixelIds($failover));
        $this->assertSame(['1625473505149626', '987654321000000'], SettingsService::facebookServerPixelIds($failover));
        $this->assertSame(['1625473505149626', '987654321000000'], SettingsService::facebookBrowserPixelIds($broadcast));
        $this->assertSame(['1625473505149626', '987654321000000'], SettingsService::facebookServerPixelIds($broadcast));
        $this->assertSame(['1625473505149626'], SettingsService::facebookBrowserPixelIds($single));
        $this->assertSame(['1625473505149626'], SettingsService::facebookServerPixelIds($single));
    }

    /**
     * 缺少事件日志表或数据库连接时，统计面板应返回可渲染的空状态。
     */
    public function test_stats_dashboard_returns_safe_empty_payload_without_runtime_tables(): void
    {
        $stats = StatsService::dashboard();

        $this->assertArrayHasKey('total_events', $stats);
        $this->assertArrayHasKey('recent_events', $stats);
    }

    /**
     * 后台 Blade 页面至少包含导航和面板关键标记，避免误删核心结构。
     */
    public function test_admin_config_view_contains_navigation_and_panel_markers(): void
    {
        $root  = dirname(__DIR__, 3);
        $blade = file_get_contents($root . '/plugins/AdTracking/Views/admin/config.blade.php');

        $this->assertIsString($blade);
        $this->assertStringContainsString('data-ad-tracking-nav="{{ $item[\'id\'] }}"', $blade);
        $this->assertStringContainsString('data-ad-tracking-panel="{{ $panel[\'code\'] }}"', $blade);
        $this->assertStringContainsString('data-ad-tracking-panel="overview"', $blade);
        $this->assertStringContainsString('id="ad-tracking-config-form"', $blade);
        $this->assertStringContainsString('facebook_dispatch_mode', $blade);
        $this->assertStringContainsString('facebook_pixel_ids', $blade);
        $this->assertStringContainsString('x-admin-form-select', $blade);
        $this->assertStringContainsString('x-admin-form-textarea', $blade);
    }
}
