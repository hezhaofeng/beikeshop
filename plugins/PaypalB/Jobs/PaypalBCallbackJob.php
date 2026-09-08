<?php

namespace Plugin\PaypalB\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBCallbackService;
use RuntimeException;

class PaypalBCallbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    public int $timeout = 45;

    public function __construct(public int $transactionId)
    {
        $this->onQueue('paypal_b');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200];
    }

    public function handle(PaypalBCallbackService $callbacks): void
    {
        $transaction = PaypalBTransaction::query()->find($this->transactionId);
        if (! $transaction || ! in_array($transaction->status, ['completed', 'cancelled', 'failed', 'expired'], true)) {
            return;
        }

        if (! $callbacks->send($transaction)) {
            throw new RuntimeException('B 站到 A 站的 PayPal 状态回调失败。');
        }
    }

    public function failed(\Throwable $exception): void
    {
        PaypalBTransaction::query()
            ->whereKey($this->transactionId)
            ->whereIn('callback_status', ['pending', 'sending', 'failed'])
            ->update([
                'callback_status'     => 'failed',
                'callback_last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'updated_at'          => now(),
            ]);
    }
}
