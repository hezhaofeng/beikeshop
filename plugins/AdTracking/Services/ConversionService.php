<?php

namespace Plugin\AdTracking\Services;

use Beike\Models\Order;
use Beike\Services\StateMachineService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugin\AdTracking\Models\AdTrackingEvent;
use Throwable;

class ConversionService
{
    public const ORDER_STATUS_EVENT = 'OrderStatus';

    /**
     * 发送 Purchase 服务端事件，平台事件通过 event_id 做幂等，重复状态回调不会重复计数。
     */
    public static function sendPurchase(Order $order): array
    {
        if (! SettingsService::eventEnabled('Purchase')) {
            Log::info('广告追踪跳过服务端 Purchase：Purchase 事件已关闭', ['order' => $order->number]);

            return [];
        }

        if (! AttributionService::hasConsent() && ! AttributionService::orderHasConsent($order)) {
            Log::info('广告追踪跳过服务端 Purchase：用户未授予广告同意', ['order' => $order->number]);

            return [];
        }

        $order->loadMissing('orderProducts');
        $results      = [];
        $purchaseData = self::purchaseData($order);

        $facebook = self::sendFacebookEvent($order, 'Purchase', $purchaseData);
        if ($facebook !== []) {
            $results['facebook'] = $facebook;
        }

        if (
            SettingsService::serverEnabled('tiktok')
            && SettingsService::get('tiktok_pixel_id')
            && SettingsService::get('tiktok_access_token')
        ) {
            $results['tiktok'] = self::sendOnce($order, 'tiktok', 'Purchase', function (string $eventId) use ($order, $purchaseData): Response {
                $settings = SettingsService::all();

                return Http::timeout(4)
                    ->withHeaders(['Access-Token' => $settings['tiktok_access_token']])
                    ->asJson()
                    ->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', [
                        'event_source'    => 'web',
                        'event_source_id' => $settings['tiktok_pixel_id'],
                        'data'            => [[
                            'event'      => 'CompletePayment',
                            'event_time' => now()->timestamp,
                            'event_id'   => $eventId,
                            'user'       => [
                                'email' => self::hash($order->email),
                                'phone' => self::hash($order->telephone),
                            ],
                            'properties' => $purchaseData,
                            'page'       => ['url' => self::sourceUrl($order)],
                        ]],
                    ]);
            });
        }

        if (
            SettingsService::serverEnabled('ga4')
            && SettingsService::get('ga4_measurement_id')
            && SettingsService::get('ga4_api_secret')
        ) {
            $results['ga4'] = self::sendOnce($order, 'ga4', 'Purchase', function (string $eventId) use ($order, $purchaseData): Response {
                $settings = SettingsService::all();
                $tracking = is_array($order->ad_tracking_data)
                    ? $order->ad_tracking_data
                    : json_decode((string) $order->ad_tracking_data, true);
                $clientId = is_array($tracking) ? data_get($tracking, 'cookies.ga_cookie', '') : '';
                $clientId = $clientId ?: $order->id . '.' . $order->id;

                return Http::timeout(4)
                    ->asJson()
                    ->post('https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode((string) $settings['ga4_measurement_id']) . '&api_secret=' . rawurlencode((string) $settings['ga4_api_secret']), [
                        'client_id' => $clientId,
                        'events'    => [[
                            'name'   => 'purchase',
                            'params' => array_merge($purchaseData, ['event_id' => $eventId]),
                        ]],
                    ]);
            });
        }

        foreach (SettingsService::postbackEvents() as $eventName) {
            if (strcasecmp($eventName, 'Purchase') !== 0) {
                continue;
            }
            if (SettingsService::serverEnabled('postback') && SettingsService::get('postback_url')) {
                $results['postback'] = self::sendOnce($order, 'postback', $eventName, function () use ($order, $eventName): Response {
                    return self::sendPostback($order, $eventName);
                });
            }
        }

        return $results;
    }

    /**
     * 回传订单每次状态迁移。状态事件独立于 Purchase，取消、退款等不会被平台统计为成交。
     */
    public static function sendOrderStatus(Order $order, string $status): array
    {
        if (! SettingsService::orderStatusEventsEnabled()) {
            Log::info('广告追踪跳过订单状态事件：订单状态上报已关闭', ['order' => $order->number, 'status' => $status]);

            return [];
        }

        if (! in_array($status, StateMachineService::ORDER_STATUS, true)) {
            Log::warning('广告追踪跳过订单状态事件：未知订单状态', ['order' => $order->number, 'status' => $status]);

            return [];
        }

        if (! AttributionService::hasConsent() && ! AttributionService::orderHasConsent($order)) {
            Log::info('广告追踪跳过订单状态事件：用户未授予广告同意', ['order' => $order->number, 'status' => $status]);

            return [];
        }

        $order->loadMissing('orderProducts');
        $statusData     = array_merge(self::purchaseData($order), self::orderStatusData($status));
        $requestContext = [
            'order_status' => $statusData['order_status'],
            'order_result' => $statusData['order_result'],
        ];
        $results = [];

        $facebook = self::sendFacebookEvent($order, self::ORDER_STATUS_EVENT, $statusData, $status);
        if ($facebook !== []) {
            $results['facebook'] = $facebook;
        }

        if (
            SettingsService::serverEnabled('ga4')
            && SettingsService::get('ga4_measurement_id')
            && SettingsService::get('ga4_api_secret')
        ) {
            $results['ga4'] = self::sendOnce(
                $order,
                'ga4',
                self::ORDER_STATUS_EVENT,
                function (string $eventId) use ($order, $statusData): Response {
                    $settings = SettingsService::all();
                    $tracking = is_array($order->ad_tracking_data)
                        ? $order->ad_tracking_data
                        : json_decode((string) $order->ad_tracking_data, true);
                    $clientId = is_array($tracking) ? data_get($tracking, 'cookies.ga_cookie', '') : '';
                    $clientId = $clientId ?: $order->id . '.' . $order->id;

                    return Http::timeout(4)
                        ->asJson()
                        ->post('https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode((string) $settings['ga4_measurement_id']) . '&api_secret=' . rawurlencode((string) $settings['ga4_api_secret']), [
                            'client_id' => $clientId,
                            'events'    => [[
                                'name'   => self::ga4EventName(self::ORDER_STATUS_EVENT),
                                'params' => array_merge($statusData, ['event_id' => $eventId]),
                            ]],
                        ]);
                },
                $status,
                $requestContext
            );
        }

        foreach (SettingsService::postbackEvents() as $eventName) {
            if (strcasecmp($eventName, self::ORDER_STATUS_EVENT) !== 0) {
                continue;
            }
            if (SettingsService::serverEnabled('postback') && SettingsService::get('postback_url')) {
                $results['postback'] = self::sendOnce(
                    $order,
                    'postback',
                    self::ORDER_STATUS_EVENT,
                    fn (string $eventId): Response => self::sendPostback($order, self::ORDER_STATUS_EVENT, $eventId, $statusData),
                    $status,
                    $requestContext
                );
            }
        }

        return $results;
    }

    /**
     * 将各平台事件名统一为 GA4 推荐名称，供前端和服务端保持一致。
     */
    public static function ga4EventName(string $eventName): string
    {
        return [
            'PageView'               => 'page_view',
            'ViewContent'            => 'view_item',
            'AddToCart'              => 'add_to_cart',
            'InitiateCheckout'       => 'begin_checkout',
            'Purchase'               => 'purchase',
            'Search'                 => 'search',
            self::ORDER_STATUS_EVENT => 'order_status',
        ][$eventName] ?? strtolower($eventName);
    }

    /**
     * 将核心状态转换成下游可聚合的结果，同时保留原始状态，避免取消和退款被归为失败或成交。
     */
    public static function orderStatusData(string $status): array
    {
        $status = trim($status);

        return [
            'order_status' => $status,
            'order_result' => match ($status) {
                StateMachineService::CREATED,
                StateMachineService::UNPAID => 'pending',
                StateMachineService::PAID,
                StateMachineService::SHIPPED,
                StateMachineService::COMPLETED => 'success',
                StateMachineService::CANCELLED => 'cancelled',
                StateMachineService::REFUNDING => 'refunding',
                default                        => 'unknown',
            },
        ];
    }

    /**
     * 生成标准电商购买参数。
     */
    public static function purchaseData(Order $order): array
    {
        $items = $order->orderProducts->map(function ($item): array {
            $price    = (float) $item->price;
            $quantity = (int) $item->quantity;

            return [
                'id'           => (string) $item->product_id,
                'item_id'      => (string) $item->product_sku,
                'item_name'    => (string) $item->name,
                'name'         => (string) $item->name,
                'item_variant' => (string) $item->product_sku,
                'price'        => $price,
                'quantity'     => $quantity,
                'line_total'   => $price * $quantity,
            ];
        })->values()->all();

        return [
            'currency'         => (string) $order->currency_code,
            'value'            => (float) $order->total,
            'order_id'         => (string) $order->number,
            'transaction_id'   => (string) $order->number,
            'content_type'     => 'product',
            'content_ids'      => array_column($items, 'id'),
            'num_items'        => array_sum(array_column($items, 'quantity')),
            'num_unique_items' => count($items),
            'contents'         => $items,
            'items'            => $items,
        ];
    }

    /**
     * 发送一次可重试的服务端事件并记录请求、响应和失败原因。
     */
    private static function sendOnce(
        Order $order,
        string $platform,
        string $eventName,
        callable $sender,
        string $contextId = null,
        array $requestContext = []
    ): array {
        $eventId = self::eventId($order, $platform, $eventName, $contextId);
        $event   = AdTrackingEvent::query()->firstOrNew(['event_id' => $eventId]);
        if ($event->exists && $event->status === 'success') {
            return ['status' => 'success', 'event_id' => $eventId, 'deduplicated' => true];
        }

        $event->fill([
            'order_id'   => $order->id,
            'event_name' => $eventName,
            'platform'   => $platform,
            'status'     => 'sending',
            'request'    => self::safeRequest($order, $requestContext),
            'error'      => null,
        ])->save();

        try {
            $response = $sender($eventId);
            $event->fill([
                'status'      => $response->successful() ? 'success' : 'failed',
                'http_status' => $response->status(),
                'response'    => mb_substr($response->body(), 0, 10000),
                'sent_at'     => $response->successful() ? now() : null,
                'error'       => $response->successful() ? null : '平台返回 HTTP ' . $response->status(),
            ])->save();

            return [
                'status'      => $event->status,
                'event_id'    => $eventId,
                'http_status' => $response->status(),
            ];
        } catch (Throwable $exception) {
            $event->fill([
                'status' => 'failed',
                'error'  => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();
            Log::warning('广告追踪服务端事件发送失败', [
                'platform' => $platform,
                'order'    => $order->number,
                'error'    => $exception->getMessage(),
            ]);

            return ['status' => 'failed', 'event_id' => $eventId, 'error' => $exception->getMessage()];
        }
    }

    /**
     * 发送联盟营销平台使用的自定义 Postback。
     */
    private static function sendPostback(Order $order, string $eventName, string $eventId = null, array $extra = []): Response
    {
        $payload = array_merge([
            'event'       => $eventName,
            'event_id'    => $eventId ?: self::eventId($order, 'postback', $eventName),
            'order_id'    => $order->number,
            'value'       => (float) $order->total,
            'currency'    => $order->currency_code,
            'source'      => $order->ad_tracking_source,
            'campaign'    => $order->ad_tracking_campaign,
            'click_id'    => $order->ad_tracking_click_id,
            'landing_url' => $order->ad_tracking_landing_url,
        ], $extra);

        $method   = strtoupper((string) SettingsService::get('postback_method', 'POST'));
        $url      = (string) SettingsService::get('postback_url');
        $client   = Http::timeout(4)->withHeaders(SettingsService::postbackHeaders());
        $response = $method === 'GET'
            ? $client->get($url, $payload)
            : $client->asJson()->post($url, $payload);

        return $response;
    }

    /**
     * 以相同的分发、日志和主备策略发送 Meta CAPI 事件；状态值参与幂等 ID，确保每个状态只上报一次。
     */
    private static function sendFacebookEvent(
        Order $order,
        string $eventName,
        array $customData,
        string $eventContext = null
    ): array {
        $facebookPixelIds     = SettingsService::facebookServerPixelIds();
        $facebookDispatchMode = SettingsService::facebookDispatchMode();
        if (
            ! SettingsService::serverEnabled('facebook')
            || $facebookPixelIds === []
            || ! SettingsService::get('facebook_access_token')
        ) {
            return [];
        }

        if (
            $facebookDispatchMode === 'failover'
            && $eventContext      === null
            && ($successEvent = self::successfulPlatformEvent($order, 'facebook', $eventName))
        ) {
            return self::deduplicatedResultFromEvent($successEvent);
        }

        $facebookResults = [];
        foreach ($facebookPixelIds as $index => $pixelId) {
            $pixelContext              = $eventContext === null ? $pixelId : $pixelId . '_' . $eventContext;
            $facebookResults[$pixelId] = self::sendOnce(
                $order,
                'facebook',
                $eventName,
                function (string $eventId) use ($order, $pixelId, $eventName, $customData): Response {
                    $settings = SettingsService::all();

                    return Http::timeout(4)
                        ->asJson()
                        ->post('https://graph.facebook.com/v19.0/' . rawurlencode($pixelId) . '/events', [
                            'access_token' => $settings['facebook_access_token'],
                            'data'         => [[
                                'event_name'       => $eventName,
                                'event_time'       => now()->timestamp,
                                'event_id'         => $eventId,
                                'action_source'    => 'website',
                                'event_source_url' => self::sourceUrl($order),
                                'user_data'        => self::facebookUserData($order),
                                'custom_data'      => $customData,
                            ]],
                        ]);
                },
                $pixelContext,
                [
                    'pixel_id'       => $pixelId,
                    'dispatch_mode'  => $facebookDispatchMode,
                    'dispatch_index' => $index + 1,
                    'order_status'   => $customData['order_status'] ?? null,
                    'order_result'   => $customData['order_result'] ?? null,
                ]
            );
            if (
                $facebookDispatchMode === 'failover'
                && (
                    ($facebookResults[$pixelId]['status'] ?? null) === 'success'
                    || ($facebookResults[$pixelId]['deduplicated'] ?? false)
                )
            ) {
                break;
            }
        }

        return count($facebookResults) === 1 ? reset($facebookResults) : $facebookResults;
    }

    /**
     * 主备模式下，如已有任一 Pixel 成功回传，则后续重复状态回调不再切换其他 Pixel。
     */
    private static function successfulPlatformEvent(Order $order, string $platform, string $eventName): ?AdTrackingEvent
    {
        return AdTrackingEvent::query()
            ->where('order_id', $order->id)
            ->where('platform', $platform)
            ->where('event_name', $eventName)
            ->where('status', 'success')
            ->latest('id')
            ->first();
    }

    /**
     * 把已成功的事件日志转换成统一的幂等返回结构。
     */
    private static function deduplicatedResultFromEvent(AdTrackingEvent $event): array
    {
        $request = is_array($event->request) ? $event->request : [];

        return array_filter([
            'status'        => 'success',
            'event_id'      => $event->event_id,
            'http_status'   => $event->http_status,
            'deduplicated'  => true,
            'pixel_id'      => $request['pixel_id']      ?? null,
            'dispatch_mode' => $request['dispatch_mode'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * 生成跨平台稳定事件 ID。
     */
    public static function eventId(Order $order, string $platform, string $eventName, string $contextId = null): string
    {
        $eventId = 'beike_' . strtolower($platform) . '_' . $order->number . '_' . strtolower($eventName);
        if ($contextId === null || $contextId === '') {
            return $eventId;
        }

        return $eventId . '_' . preg_replace('/[^a-z0-9]+/i', '', strtolower($contextId));
    }

    /**
     * 生成 Facebook 所需的标准化用户字段，邮箱和电话只发送 SHA-256。
     */
    private static function facebookUserData(Order $order): array
    {
        $data = [
            'em'                => self::hash($order->email),
            'ph'                => self::hash($order->telephone),
            'client_ip_address' => $order->ip,
            'client_user_agent' => $order->user_agent,
        ];

        $tracking = is_array($order->ad_tracking_data) ? $order->ad_tracking_data : json_decode((string) $order->ad_tracking_data, true);
        $cookies  = is_array($tracking) ? ($tracking['cookies'] ?? []) : [];
        if (! empty($cookies['fbp'])) {
            $data['fbp'] = $cookies['fbp'];
        }
        if (! empty($cookies['fbc'])) {
            $data['fbc'] = $cookies['fbc'];
        }

        return array_filter($data, static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * 获取订单归因落地页，异步状态回调没有原始浏览器 URL 时回退到站点地址。
     */
    private static function sourceUrl(Order $order): string
    {
        return (string) ($order->ad_tracking_landing_url ?: config('app.url'));
    }

    /**
     * 事件日志只保留广告归因和订单金额，不把完整地址写入日志。
     */
    private static function safeRequest(Order $order, array $extra = []): array
    {
        return array_merge([
            'order_id' => $order->number,
            'value'    => (float) $order->total,
            'currency' => $order->currency_code,
            'source'   => $order->ad_tracking_source,
            'campaign' => $order->ad_tracking_campaign,
        ], array_filter($extra, static fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * 标准化敏感字段后进行 SHA-256 哈希。
     */
    private static function hash(?string $value): string
    {
        $value = preg_replace('/\s+/', '', mb_strtolower(trim((string) $value)));

        return $value === '' ? '' : hash('sha256', $value);
    }
}
