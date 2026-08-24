<?php

namespace Plugin\AdTracking\Services;

use Beike\Models\Order;
use Illuminate\Http\Request;

class AttributionService
{
    private const SESSION_KEY = 'ad_tracking.attribution';

    private const QUERY_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'gclid',
        'dclid',
        'gbraid',
        'wbraid',
        'fbclid',
        'ttclid',
        'twclid',
        'msclkid',
        'epik',
        'ref',
    ];

    private const COOKIE_KEYS = [
        '_fbp' => 'fbp',
        '_fbc' => 'fbc',
        '_ga'  => 'ga_cookie',
        'gclid' => 'gclid',
        'fbclid' => 'fbclid',
        'ttclid' => 'ttclid',
        'twclid' => 'twclid',
    ];

    /**
     * 捕获 UTM、平台 Click ID、落地页和首个来源，后续订单复用同一份会话归因。
     */
    public static function capture(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $query = [];
        foreach (self::QUERY_KEYS as $key) {
            $value = trim((string) $request->query($key, ''));
            if ($value !== '') {
                $query[$key] = mb_substr($value, 0, 500);
            }
        }

        $cookies = [];
        foreach (self::COOKIE_KEYS as $cookie => $target) {
            $value = trim((string) $request->cookie($cookie, ''));
            if ($value !== '') {
                $cookies[$target] = mb_substr($value, 0, 500);
            }
        }

        $existing = self::current();
        if ($query === [] && ($cookies === [] || ! empty($existing['last_touch']))) {
            return;
        }

        $touch    = array_merge($query, $cookies);
        $touch['landing_url'] = mb_substr($request->fullUrl(), 0, 2000);
        $touch['referrer']    = mb_substr((string) $request->headers->get('referer', ''), 0, 2000);
        $touch['captured_at'] = now()->toDateTimeString();
        $touch['source']      = self::inferSource($touch, $touch['referrer']);

        if (empty($existing['first_touch'])) {
            $existing['first_touch'] = $touch;
        }
        $existing['last_touch'] = $touch;

        session()->put(self::SESSION_KEY, $existing);
    }

    /**
     * 返回当前会话的首触和末触归因数据。
     */
    public static function current(): array
    {
        if (! function_exists('session')) {
            return [];
        }

        $value = session()->get(self::SESSION_KEY, []);

        return is_array($value) ? $value : [];
    }

    /**
     * 将会话归因映射为订单扩展字段，并保留原始数据供服务端回传使用。
     */
    public static function orderAttributes(): array
    {
        $current = self::current();
        $touch   = is_array($current['last_touch'] ?? null) ? $current['last_touch'] : [];
        $first   = is_array($current['first_touch'] ?? null) ? $current['first_touch'] : [];
        $data    = [
            'first_touch' => $first,
            'last_touch'  => $touch,
            'cookies'     => [
                'fbp'        => $touch['fbp'] ?? $first['fbp'] ?? '',
                'fbc'        => $touch['fbc'] ?? $first['fbc'] ?? '',
                'ga_cookie'  => $touch['ga_cookie'] ?? $first['ga_cookie'] ?? '',
            ],
            'consent'     => self::hasConsent(),
        ];

        return [
            'ad_tracking_source'      => (string) ($touch['source'] ?? $first['source'] ?? ''),
            'ad_tracking_medium'      => (string) ($touch['utm_medium'] ?? $first['utm_medium'] ?? ''),
            'ad_tracking_campaign'    => (string) ($touch['utm_campaign'] ?? $first['utm_campaign'] ?? ''),
            'ad_tracking_content'     => (string) ($touch['utm_content'] ?? $first['utm_content'] ?? ''),
            'ad_tracking_term'        => (string) ($touch['utm_term'] ?? $first['utm_term'] ?? ''),
            'ad_tracking_click_id'    => self::clickId($touch ?: $first),
            'ad_tracking_landing_url' => (string) ($first['landing_url'] ?? $touch['landing_url'] ?? ''),
            'ad_tracking_referrer'    => (string) ($first['referrer'] ?? $touch['referrer'] ?? ''),
            'ad_tracking_data'        => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * 将归因字段写入订单，不改变核心订单创建事务和状态机。
     */
    public static function applyToOrder(Order $order): void
    {
        $attributes = self::orderAttributes();
        foreach ($attributes as $name => $value) {
            $order->{$name} = $value;
        }
        $order->saveQuietly();
    }

    /**
     * 根据配置和前端同意状态判断是否可以发送服务端广告事件。
     */
    public static function hasConsent(): bool
    {
        if (SettingsService::get('consent_mode', 'opt_in') === 'always') {
            return true;
        }

        $sessionValue = function_exists('session') ? session()->get('ad_tracking.consent') : null;
        $cookieValue  = function_exists('request') ? request()->cookie('beike_ad_consent') : null;

        return in_array($sessionValue ?: $cookieValue, ['granted', '1', 1, true], true);
    }

    /**
     * 在支付回调没有浏览器 Session 时读取订单创建时保存的同意状态。
     */
    public static function orderHasConsent(Order $order): bool
    {
        $data = is_array($order->ad_tracking_data)
            ? $order->ad_tracking_data
            : json_decode((string) $order->ad_tracking_data, true);

        return is_array($data) && in_array($data['consent'] ?? false, [true, 1, '1', 'granted'], true);
    }

    /**
     * 从参数名称推断订单来源，优先使用明确的 UTM source。
     */
    public static function inferSource(array $data, string $referrer = ''): string
    {
        if (! empty($data['utm_source'])) {
            return strtolower((string) $data['utm_source']);
        }
        if (! empty($data['fbclid'])) {
            return 'facebook';
        }
        if (! empty($data['gclid']) || ! empty($data['gbraid']) || ! empty($data['wbraid'])) {
            return 'google';
        }
        if (! empty($data['ttclid'])) {
            return 'tiktok';
        }
        if (! empty($data['twclid'])) {
            return 'twitter';
        }
        if (! empty($data['epik'])) {
            return 'pinterest';
        }
        if ($referrer !== '') {
            $host = parse_url($referrer, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        return 'direct';
    }

    /**
     * 从不同平台的 Click ID 中挑选一个展示用值。
     */
    public static function clickId(array $data): string
    {
        foreach (['gclid', 'fbclid', 'ttclid', 'twclid', 'msclkid', 'epik', 'gbraid', 'wbraid'] as $key) {
            if (! empty($data[$key])) {
                return (string) $data[$key];
            }
        }

        return '';
    }
}
