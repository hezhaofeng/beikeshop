<?php

namespace Plugin\PaypalA\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugin\PaypalA\Models\PaypalATransaction;
use Plugin\PaypalA\Services\PaypalAFulfillmentService;
use RuntimeException;

class PaypalAFulfillmentSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    public int $timeout = 45;

    public function __construct(public int $transactionId)
    {
        $this->onQueue('paypal_a');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200];
    }

    public function handle(PaypalAFulfillmentService $fulfillment): void
    {
        $transaction = PaypalATransaction::query()->find($this->transactionId);
        if (! $transaction || $transaction->status !== 'completed') {
            return;
        }

        if (! $fulfillment->send($transaction)) {
            throw new RuntimeException('A 站到 B 站的履约证据同步失败。');
        }
    }

    public function failed(\Throwable $exception): void
    {
        // 队列彻底放弃后保留失败原因，等待补偿命令按退避时间重新投递。
        PaypalATransaction::query()
            ->whereKey($this->transactionId)
            ->whereIn('fulfillment_status', ['pending', 'sending', 'failed'])
            ->update([
                'fulfillment_status'     => 'failed',
                'fulfillment_last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'updated_at'             => now(),
            ]);
    }
}
