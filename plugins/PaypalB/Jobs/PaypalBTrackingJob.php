<?php

namespace Plugin\PaypalB\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBTrackingService;

/**
 * 把会话上的运单上报到 PayPal。不使用队列：由 dispatchAfterResponse 在本次请求响应后于同一进程执行。
 *
 * 重试节奏和次数上限由 PaypalBTrackingService 记在交易行上（tracking_next_attempt_at / tracking_attempts），
 * 失败后由 paypal-b:dispatch-tracking 补投，或后台“重新上报运单”手动执行。
 */
class PaypalBTrackingJob
{
    use Dispatchable;

    public function __construct(public int $transactionId)
    {
    }

    public function handle(PaypalBTrackingService $tracking): void
    {
        $transaction = PaypalBTransaction::query()->find($this->transactionId);
        if (! $transaction || $transaction->status !== 'completed') {
            return;
        }

        try {
            $tracking->send($transaction);
        } catch (\Throwable $exception) {
            // 失败状态已由 send() 记在交易行上，等待补投；这里只记日志。
            report($exception);
        }
    }
}
