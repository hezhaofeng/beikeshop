<?php

namespace Plugin\PaypalB\Console;

use Illuminate\Console\Command;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBCallbackService;

class DispatchPaypalBCallbacks extends Command
{
    protected $signature = 'paypal-b:dispatch-callbacks {--limit=100}';

    protected $description = '投递 PayPal B 站待发送的 A 站状态回调。';

    public function handle(PaypalBCallbackService $callbacks): int
    {
        $limit        = max(1, min(1000, (int) $this->option('limit')));
        $transactions = PaypalBTransaction::query()
            // 自营订单没有 A 站回调目标，不必参与补偿投递。
            ->where('source', '<>', PaypalBTransaction::SOURCE_LOCAL)
            ->whereIn('status', ['completed', 'cancelled', 'failed', 'expired'])
            ->whereIn('callback_status', ['idle', 'pending', 'failed', 'sending'])
            ->where(function ($query): void {
                $query->whereNull('callback_next_attempt_at')
                    ->orWhere('callback_next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($transactions as $transaction) {
            $callbacks->queue($transaction);
        }

        $this->info("已投递 {$transactions->count()} 条 PayPal 回调任务。");

        return self::SUCCESS;
    }
}
