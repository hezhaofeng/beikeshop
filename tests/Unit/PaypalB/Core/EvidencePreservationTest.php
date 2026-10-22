<?php

namespace Tests\Unit\PaypalB\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Plugin\PaypalB\Controllers\PaypalBController;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBApiClient;
use Plugin\PaypalB\Services\PaypalBTransactionService;

/**
 * Webhook / 履约同步落库时不能丢失已有证据，验签与 HMAC 必须针对原始请求体。
 */
class EvidencePreservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // provider_payload、fulfillment_evidence 是 encrypted:array 字段；纯单元环境为模型单独提供加密器。
        Model::encryptUsing(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'));
    }

    protected function tearDown(): void
    {
        Model::encryptUsing(null);
        parent::tearDown();
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $service    = new PaypalBTransactionService([]);
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$arguments);
    }

    public function test_webhook_completion_keeps_capture_response_and_records_event_under_sub_key(): void
    {
        $captureResponse = [
            'id'             => '5O190127TN364715T',
            'status'         => 'COMPLETED',
            'payer'          => ['email_address' => 'buyer@example.test'],
            'purchase_units' => [[
                'shipping' => [
                    'name'    => ['full_name' => 'John Doe'],
                    'address' => ['address_line_1' => '1 Main St', 'admin_area_2' => 'San Jose', 'postal_code' => '95131', 'country_code' => 'US'],
                ],
                'payments' => ['captures' => [[
                    'id'                => '3C679366HH908993F',
                    'status'            => 'PENDING',
                    'status_details'    => ['reason' => 'PENDING_REVIEW'],
                    'seller_protection' => ['status' => 'ELIGIBLE'],
                    'amount'            => ['currency_code' => 'USD', 'value' => '100.00'],
                ]]],
            ]],
        ];
        $transaction = (new PaypalBTransaction)->forceFill(['provider_payload' => $captureResponse]);
        $event       = [
            'id'         => 'WH-58D329510W468432D-8HN650336L201105X',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource'   => [
                'id'                 => '3C679366HH908993F',
                'status'             => 'COMPLETED',
                'amount'             => ['currency_code' => 'USD', 'value' => '100.00'],
                'supplementary_data' => ['related_ids' => ['order_id' => '5O190127TN364715T']],
            ],
        ];

        $merged = $this->invoke('mergeWebhookPayload', $transaction, $event);

        $this->assertSame('1 Main St', data_get($merged, 'purchase_units.0.shipping.address.address_line_1'), '收货地址不能被 Webhook 事件覆盖');
        $this->assertSame('ELIGIBLE', data_get($merged, 'purchase_units.0.payments.captures.0.seller_protection.status'));
        $this->assertSame('buyer@example.test', data_get($merged, 'payer.email_address'));
        $this->assertSame($event, $merged['webhook_event']);
        // 捕获状态随事件更新，旧的待处理原因不再残留。
        $this->assertSame('COMPLETED', data_get($merged, 'purchase_units.0.payments.captures.0.status'));
        $this->assertNull(data_get($merged, 'purchase_units.0.payments.captures.0.status_details'));

        $stored = (new PaypalBTransaction)->forceFill(['provider_payload' => $merged]);
        $this->assertNotNull($stored->paypalShippingAddress(), 'PayPal 收货地址在完成后仍可读取');
        $this->assertSame('ELIGIBLE', $stored->sellerProtectionStatus());
    }

    public function test_webhook_without_prior_order_response_falls_back_to_event_body(): void
    {
        $transaction = (new PaypalBTransaction)->forceFill(['provider_payload' => ['error' => 'timeout']]);
        $event       = ['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAP-1']];

        $merged = $this->invoke('mergeWebhookPayload', $transaction, $event);

        $this->assertSame('WH-1', $merged['id']);
        $this->assertSame(['error' => 'timeout'], $merged['previous_payload']);
        $this->assertSame($event, $merged['webhook_event']);
    }

    public function test_fulfillment_sync_preserves_admin_delivery_evidence(): void
    {
        $delivery    = ['delivered_at' => '2026-09-20', 'signed_by' => 'J. Doe', 'proof_url' => 'https://b.example.test/pod.pdf', 'note' => '', 'recorded_at' => '2026-09-20T10:00:00+00:00'];
        $transaction = (new PaypalBTransaction)->forceFill(['fulfillment_evidence' => [
            'order_status'      => 'paid',
            'shipments'         => [],
            'synced_at'         => '2026-09-19T00:00:00+00:00',
            'delivery_evidence' => $delivery,
        ]]);
        $incoming = [
            'order_status' => 'shipped',
            'shipments'    => [['carrier_code' => 'UPS', 'carrier_name' => 'UPS', 'tracking_no' => '1Z999', 'shipped_at' => '', 'updated_at' => '']],
            'synced_at'    => '2026-09-21T00:00:00+00:00',
        ];

        $merged = $this->invoke('mergeDeliveryEvidence', $transaction, $incoming);

        $this->assertSame('shipped', $merged['order_status']);
        $this->assertSame('1Z999', $merged['shipments'][0]['tracking_no']);
        $this->assertSame($delivery, $merged['delivery_evidence'], 'A 站同步不能抹掉 B 站后台录入的签收证据');
    }

    public function test_webhook_verification_body_embeds_the_raw_event_verbatim(): void
    {
        // 原文里的 \/ 转义、\u 转义和空白都必须原样提交给 PayPal，重新编码数组会改变这些字节。
        $raw    = '{"id":"WH-9","event_type":"PAYMENT.CAPTURE.COMPLETED","summary":"Café  paid ","resource":{"href":"https:\/\/api.paypal.com\/v2","value":"10.50"}}';
        $fields = [
            'auth_algo'         => 'SHA256withRSA',
            'cert_url'          => 'https://api.paypal.com/v1/notifications/certs/CERT-1',
            'transmission_id'   => 'T-1',
            'transmission_sig'  => 'SIG',
            'transmission_time' => '2026-09-25T00:00:00Z',
            'webhook_id'        => 'WEBHOOK-1',
        ];

        $body = PaypalBApiClient::verificationBody($fields, json_decode($raw, true), $raw);

        $this->assertStringEndsWith(',"webhook_event":' . $raw . '}', $body);
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('WEBHOOK-1', $decoded['webhook_id']);
        $this->assertSame('https://api.paypal.com/v1/notifications/certs/CERT-1', $decoded['cert_url']);
        $this->assertSame(json_decode($raw, true), $decoded['webhook_event']);

        // 没有原文（或原文不是 JSON 对象）时退回编码数组，请求体仍是合法 JSON。
        $fallback = json_decode(PaypalBApiClient::verificationBody($fields, ['id' => 'WH-10'], 'not json'), true);
        $this->assertSame(['id' => 'WH-10'], $fallback['webhook_event']);
    }

    public function test_bridge_payload_is_parsed_from_the_raw_body_not_the_trimmed_input(): void
    {
        // TrimStrings 会把 $request->json() 里的 "  Alice  " 改成 "Alice"，HMAC 就对不上 A 站签名的原值。
        $raw     = '{"reference":"REF-1","buyer":{"name":"  Alice  ","comment":" note "},"amount":"10.00"}';
        $request = Request::create('/api/paypal-b/sessions', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw);
        $request->json()->set('buyer', ['name' => 'Alice', 'comment' => 'note']);

        $controller = new PaypalBController;
        $method     = new \ReflectionMethod($controller, 'jsonPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($controller, $request);

        $this->assertSame('  Alice  ', $payload['buyer']['name']);
        $this->assertSame(' note ', $payload['buyer']['comment']);
        $this->assertSame('REF-1', $payload['reference']);

        $form = Request::create('/api/paypal-b/sessions', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'reference=REF-2&amount=+5.00+');
        $this->assertSame(['reference' => 'REF-2', 'amount' => ' 5.00 '], $method->invoke($controller, $form), '兼容旧的表单编码客户端，同样不做裁剪');
    }
}
