<?php

namespace Plugin\PaypalB\Console;

use Illuminate\Console\Command;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBTransactionService;

class SyncPaypalBPendingCaptures extends Command
{
    protected $signature = 'paypal-b:sync-pending-captures {--limit=50} {--max-days=45}';

    protected $description = '向 PayPal 查询已捕获但仍待确认的收款（审核、电子支票等），Webhook 丢失时也能推进到最终状态。';

    /**
     * 只处理有捕获号且仍为 pending 的会话；按最久未更新的优先，避免每次都查同一批。
     * 超过 max-days 仍未确认的交给后台人工核对，不再自动轮询。
     */
    public function handle(PaypalBTransactionService $service): int
    {
        $limit   = max(1, min(500, (int) $this->option('limit')));
        $maxDays = max(1, min(180, (int) $this->option('max-days')));

        $transactions = PaypalBTransaction::query()
            ->where('status', 'pending')
            ->whereNotNull('paypal_capture_id')
            ->where('created_at', '>=', now()->subDays($maxDays))
            // 刚写入的记录留给 Webhook；补偿只查至少 10 分钟没有变化的。
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $completed = 0;
        $failed    = 0;
        $pending   = 0;
        foreach ($transactions as $transaction) {
            try {
                $status = (string) $service->syncWithPaypal($transaction)->status;
            } catch (\Throwable $exception) {
                // 单条异常（含 PayPal 拒绝）不能中断整批；拒绝已由服务落库。
                report($exception);
                $status = (string) $transaction->fresh()?->status;
            }

            match ($status) {
                'completed' => $completed++,
                'failed'    => $failed++,
                default     => $pending++,
            };
        }

        $this->info("待确认收款同步完成：已到账 {$completed} 条，已拒绝 {$failed} 条，仍待确认 {$pending} 条。");

        return self::SUCCESS;
    }
}
