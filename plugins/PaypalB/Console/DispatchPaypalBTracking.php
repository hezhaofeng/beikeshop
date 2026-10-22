<?php

namespace Plugin\PaypalB\Console;

use Illuminate\Console\Command;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBTrackingService;

/**
 * 补投待上报 PayPal 的运单：上报失败、收款晚于发货完成等情况下由计划任务兜底。
 *
 * 只捞 pending / 到期的 failed / 租约过期的 sending；sent 与 abandoned 是终态，abandoned 需要履约证据变化
 * 或后台“重新上报运单”才会重新开始，这里不会每轮反复投递。
 */
class DispatchPaypalBTracking extends Command
{
    protected $signature = 'paypal-b:dispatch-tracking {--limit=100}';

    protected $description = '投递 PayPal B 站待上报 PayPal 的运单任务。';

    public function handle(PaypalBTrackingService $tracking): int
    {
        if (! $tracking->enabled()) {
            $this->info('运单上报未启用。');

            return self::SUCCESS;
        }

        $limit        = max(1, min(1000, (int) $this->option('limit')));
        $transactions = PaypalBTransaction::query()
            ->where('source', '<>', PaypalBTransaction::SOURCE_LOCAL)
            ->where('status', 'completed')
            ->whereNotNull('paypal_capture_id')
            ->whereIn('tracking_status', ['pending', 'failed', 'sending'])
            ->where(function ($query): void {
                $query->whereNull('tracking_next_attempt_at')
                    ->orWhere('tracking_next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $failed = 0;
        foreach ($transactions as $transaction) {
            // 同步上报，不依赖队列 Worker；单条失败不中断整批。
            try {
                $tracking->send($transaction) || $failed++;
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        $this->info("已处理 {$transactions->count()} 条 PayPal 运单上报，待重试 {$failed} 条。");

        return self::SUCCESS;
    }
}
