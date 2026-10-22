<?php

namespace Tests\Unit\PaypalB\Core;

use PHPUnit\Framework\TestCase;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBApiException;
use Plugin\PaypalB\Services\PaypalBTransactionService;

/**
 * 捕获阶段的拒绝、结果未知与作废规则：
 * - 捕获被拒只结束本次 PayPal Order，不能像创建阶段的账号 / 合规拒绝那样锁死订单；
 * - 捕获结果未知（超时、5xx、2xx 缺明细）的会话可能已扣款，不能被新会话作废、不能被取消。
 */
class CaptureStateRulesTest extends TestCase
{
    private function transaction(array $attributes): PaypalBTransaction
    {
        return (new PaypalBTransaction)->forceFill($attributes + [
            'source'            => PaypalBTransaction::SOURCE_BRIDGE,
            'paypal_capture_id' => null,
        ]);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $service    = new PaypalBTransactionService([]);
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$arguments);
    }

    public function test_capture_declines_do_not_lock_the_order_but_creation_rejections_do(): void
    {
        $captureDeclined = $this->transaction([
            'status'                   => 'failed',
            'failure_retryable'        => false,
            'failure_class'            => PaypalBTransaction::FAILURE_CAPTURE_DECLINED,
            'payment_exception_status' => 'capture_denied',
        ]);
        $captureRejectedByApi = $this->transaction([
            'status'                   => 'failed',
            'failure_retryable'        => false,
            'failure_class'            => PaypalBTransaction::FAILURE_CAPTURE_DECLINED,
            'payment_exception_status' => 'capture_rejected',
        ]);
        // 修复前落库的捕获拒绝仍是 provider_rejected，按 payment_exception_status 识别为捕获阶段。
        $legacyCaptureDenied = $this->transaction([
            'status'                   => 'failed',
            'failure_retryable'        => false,
            'failure_class'            => 'provider_rejected',
            'payment_exception_status' => 'capture_denied',
        ]);
        $createRejected = $this->transaction([
            'status'                   => 'failed',
            'failure_retryable'        => false,
            'failure_class'            => 'provider_rejected',
            'payment_exception_status' => 'create_rejected',
        ]);
        $configuration = $this->transaction([
            'status'            => 'failed',
            'failure_retryable' => false,
            'failure_class'     => 'configuration',
        ]);

        $this->assertFalse($this->invoke('isBlockingRejection', $captureDeclined));
        $this->assertFalse($this->invoke('isBlockingRejection', $captureRejectedByApi));
        $this->assertFalse($this->invoke('isBlockingRejection', $legacyCaptureDenied));
        $this->assertFalse($this->invoke('isBlockingRejection', $configuration));
        $this->assertTrue($this->invoke('isBlockingRejection', $createRejected), '创建阶段被 PayPal 拒绝仍然不能换账号重试');
    }

    public function test_ambiguous_capture_failures_are_not_treated_as_declines(): void
    {
        $this->assertTrue($this->invoke('isAmbiguousCaptureFailure', PaypalBApiException::connection('timeout', new \RuntimeException('curl 28'))));
        $this->assertTrue($this->invoke('isAmbiguousCaptureFailure', new PaypalBApiException('PayPal 503', 503, 'SERVICE_UNAVAILABLE', [], true)));
        $this->assertTrue($this->invoke('isAmbiguousCaptureFailure', new PaypalBApiException('PayPal 429', 429, 'RATE_LIMIT_REACHED', [], true)));
        $this->assertTrue($this->invoke('isAmbiguousCaptureFailure', PaypalBApiException::malformedResponse('bad json', 200)));
        // 2xx 却缺少收款明细：captureData() 抛出的是普通 RuntimeException，扣款可能已经发生。
        $this->assertTrue($this->invoke('isAmbiguousCaptureFailure', new \RuntimeException('PayPal 捕获响应缺少收款明细。')));
        $this->assertTrue($this->invoke('isAmbiguousCaptureFailure', new PaypalBApiException('already', 422, 'UNPROCESSABLE_ENTITY', ['ORDER_ALREADY_CAPTURED'])));

        $this->assertFalse($this->invoke('isAmbiguousCaptureFailure', new PaypalBApiException('declined', 422, 'UNPROCESSABLE_ENTITY', ['INSTRUMENT_DECLINED'])));
        $this->assertFalse($this->invoke('isAmbiguousCaptureFailure', PaypalBApiException::configuration('no credentials')));
    }

    public function test_unknown_capture_marker_blocks_supersede_and_is_reported_by_model_helpers(): void
    {
        $plainPending = $this->transaction(['status' => 'pending']);
        $unknown      = $this->transaction([
            'status'                   => 'pending',
            'payment_exception_status' => PaypalBTransaction::CAPTURE_UNKNOWN,
        ]);
        $mismatch = $this->transaction([
            'status'                   => 'pending',
            'payment_exception_status' => PaypalBTransaction::CAPTURE_MISMATCH,
        ]);
        $pendingCapture = $this->transaction([
            'status'                   => 'pending',
            'paypal_capture_id'        => '2GG279541U471931P',
            'payment_exception_status' => 'capture_pending',
        ]);
        $creatingWithAccount    = $this->transaction(['status' => 'creating', 'account_id' => 3]);
        $creatingWithoutAccount = $this->transaction(['status' => 'creating', 'account_id' => null]);

        $this->assertTrue($this->invoke('isSupersedable', $plainPending));
        $this->assertTrue($this->invoke('isSupersedable', $creatingWithoutAccount));
        $this->assertFalse($this->invoke('isSupersedable', $unknown), '捕获结果未知的会话可能已经扣款，不能作废');
        $this->assertFalse($this->invoke('isSupersedable', $mismatch));
        $this->assertFalse($this->invoke('isSupersedable', $pendingCapture));
        $this->assertFalse($this->invoke('isSupersedable', $creatingWithAccount), '创建结果未知的会话沿用原账号重试');

        $this->assertFalse($plainPending->hasUnknownCapture());
        $this->assertFalse($plainPending->awaitsCaptureResult());
        $this->assertTrue($unknown->hasUnknownCapture());
        $this->assertTrue($unknown->awaitsCaptureResult());
        $this->assertTrue($mismatch->awaitsCaptureResult());
        $this->assertFalse($pendingCapture->hasUnknownCapture());
        $this->assertTrue($pendingCapture->awaitsCaptureResult());

        // 标记只对 pending 会话有意义：已完成 / 已失败的会话不再视为结果未知。
        $completed = $this->transaction([
            'status'                   => 'completed',
            'payment_exception_status' => PaypalBTransaction::CAPTURE_UNKNOWN,
        ]);
        $this->assertFalse($completed->hasUnknownCapture());
    }

    public function test_capture_unknown_marker_fits_existing_columns(): void
    {
        // 不新增迁移：标记写在 payment_exception_status / failure_class（均为 varchar(32)）上。
        foreach ([PaypalBTransaction::CAPTURE_UNKNOWN, PaypalBTransaction::CAPTURE_MISMATCH, PaypalBTransaction::FAILURE_CAPTURE_DECLINED] as $value) {
            $this->assertLessThanOrEqual(32, strlen($value));
        }
    }
}
