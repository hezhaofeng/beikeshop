<?php

namespace Plugin\PaypalA\Console;

use Illuminate\Console\Command;
use Plugin\PaypalA\Models\PaypalATransaction;
use Plugin\PaypalA\Services\PaypalAFulfillmentService;

class DispatchPaypalAFulfillment extends Command
{
    protected $signature = 'paypal-a:dispatch-fulfillment {--limit=20}';

    protected $description = '补投 PayPal A 站待同步到 B 站的履约证据。';

    /**
     * 这里同步发送而不是再入队一次：队列worker 未覆盖 paypal_a 队列时，
     * 派发只会把任务堆在库里，本命令就失去了兜底意义。
     * send() 自带租约与重试计数，与 Worker 并发执行是安全的。
     */
    public function handle(PaypalAFulfillmentService $fulfillment): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));

        // 只捞已入队但未送达的记录。idle 表示该订单从未产生过发货单，
        // 纳入进来会让本命令每次都扫描全部历史成交订单。
        $transactions = PaypalATransaction::query()
            ->where('status', 'completed')
            ->whereNotNull('b_transaction_id')
            ->whereIn('fulfillment_status', ['pending', 'sending', 'failed'])
            ->where(function ($query): void {
                $query->whereNull('fulfillment_next_attempt_at')
                    ->orWhere('fulfillment_next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent   = 0;
        $failed = 0;
        foreach ($transactions as $transaction) {
            try {
                $fulfillment->send($transaction) ? $sent++ : $failed++;
            } catch (\Throwable $exception) {
                // 单条失败不能中断整批补投。
                report($exception);
                $failed++;
            }
        }

        $this->info("履约证据补投完成：成功 {$sent} 条，待重试 {$failed} 条。");

        return self::SUCCESS;
    }
}
