<?php

namespace Tests\Unit\PaypalB\Core;

use PHPUnit\Framework\TestCase;
use Plugin\PaypalB\Services\PaypalBRefundService;

/**
 * PAYMENT.CAPTURE.REFUNDED / REVERSED 的 resource 是退款对象：resource.id 是退款号，
 * 原捕获号只出现在 rel=up 链接里。旧实现拿 resource.id 当捕获号匹配交易，退款 Webhook 永远对不上。
 */
class RefundWebhookParsingTest extends TestCase
{
    /**
     * PayPal 开发者文档中 PAYMENT.CAPTURE.REFUNDED 的示例事件（Webhook event names / Payments v2）。
     */
    private function refundedEvent(): array
    {
        return json_decode(<<<'JSON'
{
  "id": "WH-1GE84257G0350133W-6RW800890C634293G",
  "create_time": "2018-08-15T19:14:04.543Z",
  "resource_type": "refund",
  "event_type": "PAYMENT.CAPTURE.REFUNDED",
  "summary": "A $ 0.99 USD capture payment was refunded",
  "resource": {
    "seller_payable_breakdown": {
      "gross_amount": {"currency_code": "USD", "value": "0.99"},
      "paypal_fee": {"currency_code": "USD", "value": "0.02"},
      "net_amount": {"currency_code": "USD", "value": "0.97"},
      "total_refunded_amount": {"currency_code": "USD", "value": "1.98"}
    },
    "amount": {"currency_code": "USD", "value": "0.99"},
    "update_time": "2018-08-15T12:13:29-07:00",
    "create_time": "2018-08-15T12:13:29-07:00",
    "links": [
      {"href": "https://api.paypal.com/v2/payments/refunds/1Y107995YT783435V", "rel": "self", "method": "GET"},
      {"href": "https://api.paypal.com/v2/payments/captures/0JF852973C016714D", "rel": "up", "method": "GET"}
    ],
    "id": "1Y107995YT783435V",
    "status": "COMPLETED"
  },
  "links": [
    {"href": "https://api.paypal.com/v1/notifications/webhooks-events/WH-1GE84257G0350133W-6RW800890C634293G", "rel": "self", "method": "GET"},
    {"href": "https://api.paypal.com/v1/notifications/webhooks-events/WH-1GE84257G0350133W-6RW800890C634293G/resend", "rel": "resend", "method": "POST"}
  ],
  "event_version": "1.0",
  "resource_version": "2.0"
}
JSON, true);
    }

    /**
     * PayPal 文档中 PAYMENT.CAPTURE.REVERSED 的示例事件：同样是退款对象。
     */
    private function reversedEvent(): array
    {
        return json_decode(<<<'JSON'
{
  "id": "WH-6F207351SC284371F-0KX52201050121307",
  "create_time": "2018-08-15T21:30:35.780Z",
  "resource_type": "refund",
  "event_type": "PAYMENT.CAPTURE.REVERSED",
  "summary": "A $ 2.51 USD capture payment was reversed",
  "resource": {
    "seller_payable_breakdown": {
      "gross_amount": {"currency_code": "USD", "value": "2.51"},
      "paypal_fee": {"currency_code": "USD", "value": "0.00"},
      "net_amount": {"currency_code": "USD", "value": "2.51"},
      "total_refunded_amount": {"currency_code": "USD", "value": "2.51"}
    },
    "amount": {"currency_code": "USD", "value": "2.51"},
    "update_time": "2018-08-15T14:30:10-07:00",
    "create_time": "2018-08-15T14:30:10-07:00",
    "links": [
      {"href": "https://api.paypal.com/v2/payments/refunds/09E71677NS257044M", "rel": "self", "method": "GET"},
      {"href": "https://api.paypal.com/v2/payments/captures/4L335234718889942", "rel": "up", "method": "GET"}
    ],
    "id": "09E71677NS257044M",
    "status": "COMPLETED",
    "note_to_payer": "Payment reversed"
  },
  "event_version": "1.0",
  "resource_version": "2.0"
}
JSON, true);
    }

    public function test_refunded_event_resolves_capture_id_from_up_link(): void
    {
        $resource = $this->refundedEvent()['resource'];

        $candidates = PaypalBRefundService::webhookCaptureIdCandidates($resource);
        $this->assertSame('0JF852973C016714D', $candidates[0], 'rel=up 链接里的捕获号必须优先');
        $this->assertContains('1Y107995YT783435V', $candidates, '旧结构兼容：resource.id 仍作为最后的候选');

        $refund = PaypalBRefundService::webhookRefundData($resource, '0JF852973C016714D');
        $this->assertSame([
            'id'       => '1Y107995YT783435V',
            'amount'   => '0.99',
            'currency' => 'USD',
            'status'   => 'COMPLETED',
        ], $refund, 'resource 本身就是退款对象：退款号与金额直接取自 resource');
    }

    public function test_reversed_event_is_parsed_as_refund_shaped_resource(): void
    {
        $resource = $this->reversedEvent()['resource'];

        $this->assertSame('4L335234718889942', PaypalBRefundService::webhookCaptureIdCandidates($resource)[0]);
        $this->assertSame([
            'id'       => '09E71677NS257044M',
            'amount'   => '2.51',
            'currency' => 'USD',
            'status'   => 'COMPLETED',
        ], PaypalBRefundService::webhookRefundData($resource, '4L335234718889942', true));
    }

    public function test_legacy_capture_shaped_resources_remain_supported(): void
    {
        $capture = [
            'id'      => '8MC585209K746392H',
            'status'  => 'PARTIALLY_REFUNDED',
            'amount'  => ['currency_code' => 'USD', 'value' => '100.00'],
            'refunds' => [[
                'id'     => '5CF55312FY7417640',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'USD', 'value' => '-25.00'],
            ]],
            'links'   => [
                ['href' => 'https://api.paypal.com/v2/payments/captures/8MC585209K746392H', 'rel' => 'self', 'method' => 'GET'],
                ['href' => 'https://api.paypal.com/v2/checkout/orders/5O190127TN364715T', 'rel' => 'up', 'method' => 'GET'],
            ],
        ];

        $this->assertSame(['8MC585209K746392H'], PaypalBRefundService::webhookCaptureIdCandidates($capture), '订单链接不是捕获链接，不能被当成捕获号');
        $this->assertSame([
            'id'       => '5CF55312FY7417640',
            'amount'   => '25.00',
            'currency' => 'USD',
            'status'   => 'COMPLETED',
        ], PaypalBRefundService::webhookRefundData($capture, '8MC585209K746392H'), '负数金额按绝对值计入退款');

        // 捕获结构的退款事件缺少退款对象：没有可核对的退款号，只能进入对账。
        unset($capture['refunds']);
        $this->assertNull(PaypalBRefundService::webhookRefundData($capture, '8MC585209K746392H'));

        // 捕获结构的撤销事件：整笔撤销，没有独立退款号，金额取捕获金额。
        $this->assertSame([
            'id'       => null,
            'amount'   => '100.00',
            'currency' => 'USD',
            'status'   => '',
        ], PaypalBRefundService::webhookRefundData($capture, '8MC585209K746392H', true));
    }

    public function test_refund_without_amount_or_id_is_left_for_reconciliation(): void
    {
        $resource = $this->refundedEvent()['resource'];
        unset($resource['amount']);
        $this->assertNull(PaypalBRefundService::webhookRefundData($resource, '0JF852973C016714D'));

        $resource = $this->refundedEvent()['resource'];
        unset($resource['id']);
        $this->assertNull(PaypalBRefundService::webhookRefundData($resource, '0JF852973C016714D'));
    }

    public function test_pending_refund_status_is_reported_for_reconciliation(): void
    {
        $resource           = $this->refundedEvent()['resource'];
        $resource['status'] = 'PENDING';

        $this->assertSame('PENDING', PaypalBRefundService::webhookRefundData($resource, '0JF852973C016714D')['status']);
    }
}
