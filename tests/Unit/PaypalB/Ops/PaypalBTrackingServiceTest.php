<?php

namespace Tests\Unit\PaypalB\Ops;

use Plugin\PaypalB\Models\PaypalBAccount;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBApiClient;
use Plugin\PaypalB\Services\PaypalBApiException;
use Plugin\PaypalB\Services\PaypalBTrackingService;

/**
 * 运单上报的逐运单处理与“是否还有待处理差异”的判定：永久错误不再无限重试、超长运单号有记录、
 * 换承运商被 PayPal 拒绝重新登记时给出明确原因。
 */
class PaypalBTrackingServiceTest extends OpsTestCase
{
    public function test_oversized_tracking_numbers_are_skipped_with_a_recorded_reason(): void
    {
        $long        = str_repeat('A', 70);
        $transaction = $this->transaction([
            ['tracking_no' => 'OK-1', 'carrier_code' => 'UPS', 'carrier_name' => 'UPS'],
            ['tracking_no' => $long, 'carrier_code' => 'UPS', 'carrier_name' => 'UPS'],
        ]);
        $client  = new FakeTrackingClient;
        $service = $this->service($client);

        $this->assertSame(['OK-1'], array_keys($service->currentShipments($transaction)));
        $this->assertSame([$long], array_keys($service->oversizedTrackingNumbers($transaction)));
        $this->assertTrue($service->hasPendingChanges($transaction));

        $result = $this->report($service, $transaction);

        $this->assertSame([['add', 'OK-1', 'UPS']], $client->calls);
        $this->assertSame('SHIPPED', $result['reported']['OK-1']['status']);
        $this->assertSame('SKIPPED', $result['reported'][$long]['status']);
        $this->assertTrue($result['reported'][$long]['permanent']);
        $this->assertStringContainsString('64', $result['reported'][$long]['error']);
        $this->assertFalse($result['retryable']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringStartsWith($long . '：', $result['errors'][0]);
        $this->assertContains('PaypalB 运单号过长，跳过上报 PayPal。', $this->loggedMessages('warning'));

        // 结果落库后不再有待处理差异，补投命令不会反复捞起。
        $transaction->tracking_payload = $result['reported'];
        $this->assertFalse($service->hasPendingChanges($transaction));
    }

    public function test_permanent_rejection_is_not_retried_until_the_evidence_changes(): void
    {
        $transaction = $this->transaction([['tracking_no' => 'NO-1', 'carrier_code' => 'UPS', 'carrier_name' => 'UPS']]);
        $client      = new FakeTrackingClient;
        $client->addFailures['NO-1'] = new PaypalBApiException('PayPal 登记运单失败。', 422, 'UNPROCESSABLE_ENTITY', ['INVALID_TRACKING_NUMBER']);
        $service = $this->service($client);

        $result = $this->report($service, $transaction);

        $this->assertSame('FAILED', $result['reported']['NO-1']['status']);
        $this->assertTrue($result['reported']['NO-1']['permanent']);
        $this->assertFalse($result['retryable']);
        $this->assertNotSame([], $result['errors']);

        $transaction->tracking_payload = $result['reported'];
        $this->assertFalse($service->hasPendingChanges($transaction));

        // 未强制时不会再调用 PayPal；后台“重新上报”（force）会重试。
        $client->calls = [];
        $again         = $this->report($service, $transaction);
        $this->assertSame([], $client->calls);
        $this->assertFalse($again['retryable']);
        $this->assertNotSame([], $again['errors']);
        $this->report($service, $transaction, true);
        $this->assertSame([['add', 'NO-1', 'UPS']], $client->calls);

        // A 站改了承运商：出现新差异，重新进入自动上报。
        $transaction->fulfillment_evidence = ['shipments' => [['tracking_no' => 'NO-1', 'carrier_code' => 'DHL', 'carrier_name' => 'DHL']]];
        $this->assertTrue($service->hasPendingChanges($transaction));
    }

    public function test_retryable_failure_stays_pending_but_is_not_new_for_abandoned_sessions(): void
    {
        $transaction = $this->transaction([['tracking_no' => 'NO-2', 'carrier_code' => 'UPS', 'carrier_name' => 'UPS']]);
        $client      = new FakeTrackingClient;
        $client->addFailures['NO-2'] = new PaypalBApiException('PayPal 登记运单失败。', 503, 'SERVICE_UNAVAILABLE', [], true);
        $service = $this->service($client);

        $result = $this->report($service, $transaction);

        $this->assertTrue($result['retryable']);
        $this->assertFalse($result['reported']['NO-2']['permanent']);
        $transaction->tracking_payload = $result['reported'];
        $this->assertTrue($service->hasPendingChanges($transaction));
        // 已转人工的会话：A 站重复推送同样的运单不算新差异。
        $this->assertFalse($service->hasPendingChanges($transaction, true));
    }

    public function test_carrier_change_rejected_by_paypal_becomes_a_clear_permanent_error(): void
    {
        $transaction = $this->transaction(
            [['tracking_no' => 'NO-3', 'carrier_code' => 'DHL', 'carrier_name' => 'DHL']],
            ['NO-3' => ['tracker_id' => 'CAP-1-NO-3', 'carrier' => 'UPS', 'carrier_name_other' => null, 'status' => 'SHIPPED']]
        );
        $client = new FakeTrackingClient;
        $client->addFailures['NO-3'] = new PaypalBApiException('PayPal 登记运单失败。', 422, 'UNPROCESSABLE_ENTITY', ['TRACKER_ALREADY_EXISTS']);
        $service = $this->service($client);

        $this->assertTrue($service->hasPendingChanges($transaction));
        $result = $this->report($service, $transaction);

        $this->assertSame([['cancel', 'CAP-1-NO-3'], ['add', 'NO-3', 'DHL']], $client->calls);
        $entry = $result['reported']['NO-3'];
        $this->assertSame('FAILED', $entry['status']);
        $this->assertSame('CAP-1-NO-3', $entry['tracker_id']);
        $this->assertTrue($entry['permanent']);
        $this->assertStringContainsString('PayPal 后台手工更新', $entry['error']);
        $this->assertFalse($result['retryable']);

        $transaction->tracking_payload = $result['reported'];
        $this->assertFalse($service->hasPendingChanges($transaction));
    }

    public function test_removed_tracking_number_is_cancelled_and_counts_as_synced(): void
    {
        $transaction = $this->transaction(
            [],
            ['GONE-1' => ['tracker_id' => 'CAP-1-GONE-1', 'carrier' => 'UPS', 'carrier_name_other' => null, 'status' => 'SHIPPED']]
        );
        $client  = new FakeTrackingClient;
        $service = $this->service($client);

        $this->assertTrue($service->hasPendingChanges($transaction));
        $result = $this->report($service, $transaction);

        $this->assertSame([['cancel', 'CAP-1-GONE-1']], $client->calls);
        $this->assertSame('CANCELLED', $result['reported']['GONE-1']['status']);
        $this->assertSame([], $result['errors']);
        $transaction->tracking_payload = $result['reported'];
        $this->assertFalse($service->hasPendingChanges($transaction));
    }

    public function test_non_api_account_is_a_permanent_error(): void
    {
        $transaction = $this->transaction([['tracking_no' => 'NO-4', 'carrier_code' => 'UPS', 'carrier_name' => 'UPS']]);
        $transaction->setRelation('account', (new PaypalBAccount)->forceFill(['account_type' => 'manual']));
        $client = new FakeTrackingClient;

        $result = $this->report($this->service($client), $transaction);

        $this->assertSame([], $client->calls);
        $this->assertFalse($result['retryable']);
        $this->assertStringContainsString('不是自动 API 账号', $result['errors'][0]);
    }

    /**
     * @param array<int, array<string, string>> $shipments
     * @param array<string, array<string, mixed>> $reported
     */
    private function transaction(array $shipments, array $reported = []): PaypalBTransaction
    {
        $transaction = (new PaypalBTransaction)->forceFill([
            'transaction_id'       => 'TX-1',
            'order_number'         => 'A-1001',
            'source'               => 'bridge',
            'status'               => 'completed',
            'paypal_order_id'      => 'ORDER-1',
            'paypal_capture_id'    => 'CAP-1',
            'fulfillment_evidence' => ['shipments' => $shipments],
            'tracking_payload'     => $reported,
        ]);
        $transaction->setRelation('account', (new PaypalBAccount)->forceFill(['account_type' => PaypalBAccount::TYPE_BUSINESS_API]));

        return $transaction;
    }

    private function service(PaypalBApiClient $client): PaypalBTrackingService
    {
        return new class(['tracking_report_enabled' => true], $client) extends PaypalBTrackingService
        {
            public function __construct(array $configuration, private readonly PaypalBApiClient $fake)
            {
                parent::__construct($configuration);
            }

            protected function apiClient(PaypalBAccount $account): PaypalBApiClient
            {
                return $this->fake;
            }
        };
    }

    /**
     * @return array{reported: array, errors: array<int, string>, retryable: bool}
     */
    private function report(PaypalBTrackingService $service, PaypalBTransaction $transaction, bool $force = false): array
    {
        return (new \ReflectionMethod(PaypalBTrackingService::class, 'reportShipments'))->invoke($service, $transaction, $force);
    }
}

class FakeTrackingClient extends PaypalBApiClient
{
    /** @var array<int, array<int, string>> */
    public array $calls = [];

    /** @var array<string, \Throwable> 运单号 => 登记时抛出的异常 */
    public array $addFailures = [];

    public function __construct()
    {
        parent::__construct(new PaypalBAccount, true, 5);
    }

    public function addTracker(
        string $paypalOrderId,
        string $captureId,
        string $trackingNumber,
        string $carrier,
        ?string $carrierNameOther,
        bool $notifyPayer = false
    ): string {
        $this->calls[] = ['add', $trackingNumber, $carrier];
        if (isset($this->addFailures[$trackingNumber])) {
            throw $this->addFailures[$trackingNumber];
        }

        return $captureId . '-' . $trackingNumber;
    }

    public function cancelTracker(string $paypalOrderId, string $trackerId): void
    {
        $this->calls[] = ['cancel', $trackerId];
    }
}
