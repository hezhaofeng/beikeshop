<?php

namespace Tests\Unit\AdTracking;

use Beike\Libraries\Registry;
use Beike\Models\Order;
use PHPUnit\Framework\TestCase;
use Plugin\AdTracking\Services\AttributionService;
use Plugin\AdTracking\Services\ConversionService;
use Plugin\AdTracking\Services\TrackingEventService;

class TrackingEventServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Registry::set('currency', 'CNY', true);
    }

    /**
     * 明确的 UTM source 优先于平台 Click ID。
     */
    public function test_utm_source_has_priority(): void
    {
        $this->assertSame('newsletter', AttributionService::inferSource([
            'utm_source' => 'newsletter',
            'fbclid'     => 'fb-123',
        ]));
    }

    /**
     * Click ID 展示值按 Google、Facebook、TikTok 等平台顺序稳定选择。
     */
    public function test_click_id_priority_is_stable(): void
    {
        $this->assertSame('google-123', AttributionService::clickId([
            'fbclid' => 'facebook-123',
            'gclid'  => 'google-123',
        ]));
    }

    /**
     * 标准事件名称映射到 GA4 推荐事件名。
     */
    public function test_ga4_event_names_are_mapped(): void
    {
        $this->assertSame('view_item', ConversionService::ga4EventName('ViewContent'));
        $this->assertSame('begin_checkout', ConversionService::ga4EventName('InitiateCheckout'));
        $this->assertSame('purchase', ConversionService::ga4EventName('Purchase'));
        $this->assertSame('order_status', ConversionService::ga4EventName('OrderStatus'));
    }

    /**
     * 状态事件保留原始状态，并把成交、取消、退款按不同语义提供给下游平台。
     */
    public function test_order_status_metadata_keeps_success_and_cancellation_separate(): void
    {
        $this->assertSame([
            'order_status' => 'created',
            'order_result' => 'pending',
        ], ConversionService::orderStatusData('created'));
        $this->assertSame([
            'order_status' => 'paid',
            'order_result' => 'success',
        ], ConversionService::orderStatusData('paid'));
        $this->assertSame([
            'order_status' => 'cancelled',
            'order_result' => 'cancelled',
        ], ConversionService::orderStatusData('cancelled'));
        $this->assertSame([
            'order_status' => 'refunding',
            'order_result' => 'refunding',
        ], ConversionService::orderStatusData('refunding'));
    }

    /**
     * 事件包装器保留事件名并过滤 null 参数，避免下发无效字段。
     */
    public function test_event_wrapper_filters_null_values(): void
    {
        $event = TrackingEventService::event('Search', [
            'search_string' => 'shoe',
            'value'         => null,
        ]);

        $this->assertSame('Search', $event['name']);
        $this->assertSame(['search_string' => 'shoe'], $event['params']);
    }

    /**
     * 商品浏览事件同时携带商品、SKU、品牌、库存和变体信息。
     */
    public function test_product_event_contains_item_context(): void
    {
        $event = TrackingEventService::product([
            'id'            => 10,
            'name'          => '运动鞋',
            'brand_name'    => '品牌 A',
            'category_name' => '鞋类',
            'skus'          => [[
                'id'       => 99,
                'sku'      => 'SHOE-RED-42',
                'price'    => 129.5,
                'quantity' => 3,
            ]],
        ]);

        $this->assertSame('ViewContent', $event['name']);
        $this->assertSame('SHOE-RED-42', $event['params']['item_id']);
        $this->assertSame('品牌 A', $event['params']['item_brand']);
        $this->assertSame('in_stock', $event['params']['availability']);
        $this->assertSame('鞋类', $event['params']['items'][0]['item_category']);
    }

    /**
     * 结账事件保留商品 ID、SKU、行金额和总件数，便于分商品分析漏斗。
     */
    public function test_checkout_event_contains_line_totals(): void
    {
        $event = TrackingEventService::checkout([
            'carts' => [
                'carts' => [[
                    'product_id'  => 10,
                    'product_sku' => 'SHOE-RED-42',
                    'name'        => '运动鞋',
                    'price'       => 129.5,
                    'quantity'    => 2,
                    'subtotal'    => 259,
                ]],
            ],
            'totals' => [['amount' => 259]],
        ]);

        $this->assertSame(2, $event['params']['num_items']);
        $this->assertSame(259.0, $event['params']['items'][0]['line_total']);
        $this->assertSame(['10'], $event['params']['product_ids']);
    }

    /**
     * Purchase 事件的每个订单商品都包含变体和行金额，且总件数与明细一致。
     */
    public function test_purchase_data_contains_line_totals_and_item_count(): void
    {
        $order = new class extends Order
        {
            public function __construct()
            {
            }
        };
        $order->number        = '100002';
        $order->currency_code = 'CNY';
        $order->total         = 259;
        $order->setRelation('orderProducts', collect([(object) [
            'product_id'  => 10,
            'product_sku' => 'SHOE-RED-42',
            'name'        => '运动鞋',
            'price'       => 129.5,
            'quantity'    => 2,
        ]]));

        $data = ConversionService::purchaseData($order);

        $this->assertSame(2, $data['num_items']);
        $this->assertSame(259.0, $data['items'][0]['line_total']);
        $this->assertSame('SHOE-RED-42', $data['items'][0]['item_variant']);
    }

    /**
     * 多 Pixel 服务端回传使用独立事件 ID，避免同一订单不同 Pixel 互相幂等。
     */
    public function test_event_id_supports_pixel_context(): void
    {
        $order = new class extends Order
        {
            public function __construct()
            {
            }
        };
        $order->number = '100001';

        $first  = ConversionService::eventId($order, 'facebook', 'Purchase', '1625473505149626');
        $second = ConversionService::eventId($order, 'facebook', 'Purchase', '987654321000000');

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('1625473505149626', $first);
        $this->assertStringContainsString('987654321000000', $second);

        $unpaid = ConversionService::eventId($order, 'ga4', 'OrderStatus', 'unpaid');
        $paid   = ConversionService::eventId($order, 'ga4', 'OrderStatus', 'paid');
        $this->assertNotSame($unpaid, $paid);
    }
}
