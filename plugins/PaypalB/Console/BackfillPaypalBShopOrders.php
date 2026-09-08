<?php

namespace Plugin\PaypalB\Console;

use Illuminate\Console\Command;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBShopOrderService;

class BackfillPaypalBShopOrders extends Command
{
    protected $signature = 'paypal-b:backfill-orders {--limit=200} {--dry-run}';

    protected $description = '为历史已收款但尚未生成本站订单的 PayPal 交易补建订单。';

    public function handle(PaypalBShopOrderService $orders): int
    {
        $limit        = max(1, min(2000, (int) $this->option('limit')));
        $dryRun       = (bool) $this->option('dry-run');
        $transactions = PaypalBTransaction::query()
            ->where('status', 'completed')
            // 自营订单已有真实订单，不需要补建影子订单。
            ->where('source', '<>', PaypalBTransaction::SOURCE_LOCAL)
            ->whereNull('shop_order_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($transactions->isEmpty()) {
            $this->info('没有需要补建订单的 PayPal 交易。');

            return self::SUCCESS;
        }

        $created = 0;
        foreach ($transactions as $transaction) {
            if ($dryRun) {
                $this->line(sprintf(
                    '待补建：%s（订单号 %s，%s %s）',
                    $transaction->transaction_id,
                    $transaction->order_number,
                    $transaction->amount,
                    $transaction->currency
                ));

                continue;
            }

            if ($orders->createFromTransaction($transaction)) {
                $created++;

                continue;
            }

            $this->warn(sprintf('补建失败，详见日志：%s', $transaction->transaction_id));
        }

        $this->info($dryRun
            ? sprintf('共 %d 笔交易待补建（未写入）。', $transactions->count())
            : sprintf('已补建 %d / %d 笔订单。', $created, $transactions->count()));

        return self::SUCCESS;
    }
}
