<?php

namespace Plugin\AdTracking\Services;

class SettingsService
{
    public const CODE = 'ad_tracking';

    public const FACEBOOK_DISPATCH_MODES = ['single', 'broadcast', 'failover'];

    /**
     * 插件支持的标准电商事件，顺序也用于后台配置页展示。
     */
    public const EVENTS = [
        'PageView',
        'ViewContent',
        'AddToCart',
        'InitiateCheckout',
        'Purchase',
        'Search',
    ];

    /**
     * 平台元数据集中管理，后台面板和统计服务共用同一份定义。
     */
    public static function platforms(): array
    {
        return [
            'facebook' => [
                'name'        => 'Facebook / Meta',
                'icon'        => 'bi-facebook',
                'description' => 'Pixel 浏览器事件和 Conversion API 服务端回传。',
                'browser'     => true,
                'server'      => true,
            ],
            'google_ads' => [
                'name'        => 'Google Ads',
                'icon'        => 'bi-google',
                'description' => 'Google Ads 转化追踪和 Google Tag Manager 集成。',
                'browser'     => true,
                'server'      => false,
            ],
            'ga4' => [
                'name'        => 'Google Analytics 4',
                'icon'        => 'bi-bar-chart-line',
                'description' => 'GA4 浏览器事件和 Measurement Protocol 服务端回传。',
                'browser'     => true,
                'server'      => true,
            ],
            'tiktok' => [
                'name'        => 'TikTok',
                'icon'        => 'bi-play-btn',
                'description' => 'TikTok Pixel 浏览器事件和 Events API 服务端回传。',
                'browser'     => true,
                'server'      => true,
            ],
            'twitter' => [
                'name'        => 'Twitter / X',
                'icon'        => 'bi-twitter-x',
                'description' => 'X Pixel 广告转化追踪。',
                'browser'     => true,
                'server'      => false,
            ],
            'pinterest' => [
                'name'        => 'Pinterest',
                'icon'        => 'bi-pinterest',
                'description' => 'Pinterest Tag 广告事件追踪。',
                'browser'     => true,
                'server'      => false,
            ],
        ];
    }

    /**
     * 返回插件配置，并为首次启用提供稳定默认值。
     */
    public static function all(): array
    {
        $stored = plugin_setting(self::CODE, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        $settings = array_merge([
            'status'                 => 0,
            'consent_mode'           => 'opt_in',
            'enabled_events'         => self::EVENTS,
            'order_status_events'    => true,
            'postback_method'        => 'POST',
            'postback_events'        => 'Purchase',
            'postback_headers'       => '',
            'facebook_pixel_id'      => '',
            'facebook_pixel_ids'     => '',
            'facebook_dispatch_mode' => 'failover',
            'facebook_access_token'  => '',
            'facebook_enabled'       => false,
            'facebook_browser'       => true,
            'facebook_server'        => true,
            'google_ads_id'          => '',
            'google_ads_label'       => '',
            'google_tag_manager_id'  => '',
            'google_ads_enabled'     => false,
            'google_ads_browser'     => true,
            'ga4_measurement_id'     => '',
            'ga4_api_secret'         => '',
            'ga4_enabled'            => false,
            'ga4_browser'            => true,
            'ga4_server'             => true,
            'tiktok_pixel_id'        => '',
            'tiktok_access_token'    => '',
            'tiktok_enabled'         => false,
            'tiktok_browser'         => true,
            'tiktok_server'          => true,
            'twitter_pixel_id'       => '',
            'twitter_enabled'        => false,
            'twitter_browser'        => true,
            'pinterest_tag_id'       => '',
            'pinterest_enabled'      => false,
            'pinterest_browser'      => true,
            'postback_url'           => '',
            'postback_enabled'       => false,
            'postback_server'        => true,
        ], $stored);

        // 兼容旧版本：没有显式平台开关时，根据已有凭据推断启用状态。
        foreach (self::platforms() as $platform => $definition) {
            $enabledKey = "{$platform}_enabled";
            if (! array_key_exists($enabledKey, $stored)) {
                $settings[$enabledKey] = self::legacyPlatformConfigured($platform, $settings);
            }
        }

        $settings['enabled_events']         = self::normalizeEvents($settings['enabled_events']);
        $facebookPixelIds                   = self::facebookPixelIds($settings);
        $settings['facebook_dispatch_mode'] = self::facebookDispatchMode($settings);
        $settings['facebook_pixel_id']      = $facebookPixelIds[0] ?? trim((string) $settings['facebook_pixel_id']);
        $settings['facebook_pixel_ids']     = implode(PHP_EOL, $facebookPixelIds);

        return $settings;
    }

    /**
     * 获取单项配置，避免业务代码直接依赖 settings 表结构。
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all()[$key] ?? $default;
    }

    /**
     * 生成可安全下发到浏览器的公开配置，服务端密钥不会进入页面。
     */
    public static function publicConfig(): array
    {
        $settings         = static::all();
        $facebookPixelIds = static::facebookPixelIds($settings);

        return [
            'consent_mode'               => (string) $settings['consent_mode'],
            'enabled_events'             => static::enabledEvents(),
            'facebook_dispatch_mode'     => static::facebookDispatchMode($settings),
            'facebook_pixel_id'          => $facebookPixelIds[0] ?? '',
            'facebook_pixel_ids'         => $facebookPixelIds,
            'facebook_browser_pixel_ids' => static::facebookBrowserPixelIds($settings),
            'google_ads_id'              => trim((string) $settings['google_ads_id']),
            'google_ads_label'           => trim((string) $settings['google_ads_label']),
            'google_tag_manager_id'      => trim((string) $settings['google_tag_manager_id']),
            'ga4_measurement_id'         => trim((string) $settings['ga4_measurement_id']),
            'tiktok_pixel_id'            => trim((string) $settings['tiktok_pixel_id']),
            'twitter_pixel_id'           => trim((string) $settings['twitter_pixel_id']),
            'pinterest_tag_id'           => trim((string) $settings['pinterest_tag_id']),
            'platforms'                  => static::publicPlatforms($settings),
            'currency'                   => function_exists('current_currency_code') ? current_currency_code() : '',
            'consent_endpoint'           => route('shop.ad_tracking.consent'),
        ];
    }

    /**
     * 返回标准事件开关，并过滤旧配置中的空值和未知值。
     */
    public static function enabledEvents(): array
    {
        return self::normalizeEvents(static::get('enabled_events', self::EVENTS));
    }

    /**
     * 判断事件是否启用，供浏览器事件和服务端事件共用。
     */
    public static function eventEnabled(string $eventName): bool
    {
        return in_array($eventName, static::enabledEvents(), true);
    }

    /**
     * 判断是否发送服务端订单状态事件。该开关独立于六个浏览器标准事件，避免改变既有像素语义。
     */
    public static function orderStatusEventsEnabled(): bool
    {
        return filter_var(static::get('order_status_events', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 判断平台总开关。
     */
    public static function platformEnabled(string $platform): bool
    {
        return (bool) static::get("{$platform}_enabled", false);
    }

    /**
     * 判断平台浏览器通道是否启用。
     */
    public static function browserEnabled(string $platform): bool
    {
        return static::platformEnabled($platform) && (bool) static::get("{$platform}_browser", false);
    }

    /**
     * 判断平台服务端通道是否启用。
     */
    public static function serverEnabled(string $platform): bool
    {
        return static::platformEnabled($platform) && (bool) static::get("{$platform}_server", false);
    }

    /**
     * 返回后台统计和前端脚本需要的已配置平台数量。
     */
    public static function enabledPlatformCount(): int
    {
        return collect(self::platforms())
            ->filter(fn (array $definition, string $platform): bool => static::platformEnabled($platform))
            ->count();
    }

    /**
     * 返回 Facebook Pixel ID 列表，兼容旧版单 ID 和新版多 ID 文本配置。
     */
    public static function facebookPixelIds(array $settings = null): array
    {
        $settings ??= static::all();

        $multiValue = $settings['facebook_pixel_ids'] ?? '';
        if (is_array($multiValue)) {
            return self::normalizeIdentifierList($multiValue);
        }

        $legacyValue = $settings['facebook_pixel_id'] ?? '';
        $source      = trim((string) $multiValue) !== '' ? $multiValue : $legacyValue;

        return self::normalizeIdentifierList($source);
    }

    /**
     * 返回 Facebook Pixel 分发模式，统一兼容旧配置和非法值。
     */
    public static function facebookDispatchMode(array $settings = null): string
    {
        $settings ??= static::all();
        $mode = trim((string) ($settings['facebook_dispatch_mode'] ?? 'failover'));

        return in_array($mode, self::FACEBOOK_DISPATCH_MODES, true) ? $mode : 'failover';
    }

    /**
     * 浏览器端实际初始化的 Pixel 列表。
     */
    public static function facebookBrowserPixelIds(array $settings = null): array
    {
        $settings ??= static::all();
        $pixelIds = self::facebookPixelIds($settings);

        return self::facebookDispatchMode($settings) === 'broadcast'
            ? $pixelIds
            : array_slice($pixelIds, 0, 1);
    }

    /**
     * 服务端实际参与发送的 Pixel 列表；主备模式会按顺序尝试。
     */
    public static function facebookServerPixelIds(array $settings = null): array
    {
        $settings ??= static::all();
        $pixelIds = self::facebookPixelIds($settings);

        return self::facebookDispatchMode($settings) === 'single'
            ? array_slice($pixelIds, 0, 1)
            : $pixelIds;
    }

    /**
     * 将公开配置裁剪为不包含服务端密钥的平台配置。
     */
    private static function publicPlatforms(array $settings): array
    {
        $facebookPixelIds = self::facebookPixelIds($settings);

        return [
            'facebook' => [
                'enabled'           => (bool) $settings['facebook_enabled'],
                'browser_enabled'   => (bool) $settings['facebook_browser'],
                'dispatch_mode'     => self::facebookDispatchMode($settings),
                'pixel_id'          => $facebookPixelIds[0] ?? '',
                'pixel_ids'         => $facebookPixelIds,
                'browser_pixel_ids' => self::facebookBrowserPixelIds($settings),
            ],
            'google_ads' => [
                'enabled'          => (bool) $settings['google_ads_enabled'],
                'browser_enabled'  => (bool) $settings['google_ads_browser'],
                'conversion_id'    => trim((string) $settings['google_ads_id']),
                'conversion_label' => trim((string) $settings['google_ads_label']),
                'gtm_id'           => trim((string) $settings['google_tag_manager_id']),
            ],
            'ga4' => [
                'enabled'         => (bool) $settings['ga4_enabled'],
                'browser_enabled' => (bool) $settings['ga4_browser'],
                'measurement_id'  => trim((string) $settings['ga4_measurement_id']),
            ],
            'tiktok' => [
                'enabled'         => (bool) $settings['tiktok_enabled'],
                'browser_enabled' => (bool) $settings['tiktok_browser'],
                'pixel_id'        => trim((string) $settings['tiktok_pixel_id']),
            ],
            'twitter' => [
                'enabled'         => (bool) $settings['twitter_enabled'],
                'browser_enabled' => (bool) $settings['twitter_browser'],
                'pixel_id'        => trim((string) $settings['twitter_pixel_id']),
            ],
            'pinterest' => [
                'enabled'         => (bool) $settings['pinterest_enabled'],
                'browser_enabled' => (bool) $settings['pinterest_browser'],
                'tag_id'          => trim((string) $settings['pinterest_tag_id']),
            ],
        ];
    }

    /**
     * 将设置值规范化为受支持的标准事件列表。
     */
    private static function normalizeEvents(mixed $events): array
    {
        if (! is_array($events)) {
            $events = preg_split('/[\s,;]+/', (string) $events, -1, PREG_SPLIT_NO_EMPTY);
        }

        $events = array_values(array_unique(array_filter(array_map('trim', $events ?: []))));

        return array_values(array_intersect(self::EVENTS, $events));
    }

    /**
     * 根据旧版凭据推断平台是否已配置。
     */
    private static function legacyPlatformConfigured(string $platform, array $settings): bool
    {
        return match ($platform) {
            'facebook'   => self::facebookPixelIds($settings) !== [] || $settings['facebook_access_token'] !== '',
            'google_ads' => $settings['google_ads_id']        !== '' || $settings['google_tag_manager_id'] !== '',
            'ga4'        => $settings['ga4_measurement_id']   !== '' || $settings['ga4_api_secret'] !== '',
            'tiktok'     => $settings['tiktok_pixel_id']      !== '' || $settings['tiktok_access_token'] !== '',
            'twitter'    => $settings['twitter_pixel_id']     !== '',
            'pinterest'  => $settings['pinterest_tag_id']     !== '',
            default      => false,
        };
    }

    /**
     * 将多值 ID 输入统一解析为去重后的字符串数组。
     */
    private static function normalizeIdentifierList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($item): string => trim((string) $item),
            $items ?: []
        ))));
    }

    /**
     * 解析自定义回传需要监听的事件名称。
     */
    public static function postbackEvents(): array
    {
        $events = preg_split('/[\s,;]+/', (string) static::get('postback_events', 'Purchase'), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map('trim', $events ?: ['Purchase'])));
    }

    /**
     * 解析后台输入的 JSON 请求头，非法内容按空数组处理。
     */
    public static function postbackHeaders(): array
    {
        $value = json_decode((string) static::get('postback_headers', ''), true);

        return is_array($value) ? array_filter($value, 'is_scalar') : [];
    }
}
