<?php

namespace Plugin\PaypalB\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugin\PaypalB\Services\PaypalBRefundService;

class PaypalBRefundJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    public int $timeout = 60;

    public function __construct(public int $refundId)
    {
        $this->onQueue('paypal_b');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200];
    }

    public function handle(PaypalBRefundService $refunds): void
    {
        $refunds->process($this->refundId);
    }
}
