<?php

namespace Plugin\PaypalB\Console;

use Beike\Models\Order;
use Beike\Models\OrderProduct;
use Beike\Models\OrderTotal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBMoney;
use Plugin\PaypalB\Services\PaypalBShopOrderService;

class BackfillPaypalBShopOrders extends Command
{
    protected $signature = 'paypal-b:backfill-orders {--limit=200} {--dry-run} {--with-adjustment : 为已存在的镜像订单补差价调整行，不改商品单价}';

    protected $description = '为历史已收款但尚未生成本站订单的 PayPal 交易补建订单；可选为已有镜像订单补差价调整行。';

    public function handle(PaypalBShopOrderService $orders): int
    {
        $limit  = max(1, min(2000, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('with-adjustment')) {
            return $this->backfillAdjustments($limit, $dryRun);
        }

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

    /**
     * 历史镜像订单的商品行按 A 站单价写入，小计与实收本就相等；只有后台改过商品行或
     * 分配过本站商品的订单才会出现差额。这里只补一条调整行让总额回到实收，不回溯改单价。
     */
    private function backfillAdjustments(int $limit, bool $dryRun): int
    {
        $transactions = PaypalBTransaction::query()
            ->where('source', '<>', PaypalBTransaction::SOURCE_LOCAL)
            ->whereNotNull('shop_order_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $updated = 0;
        foreach ($transactions as $transaction) {
            $order = Order::query()->find($transaction->shop_order_id);
            if (! $order) {
                continue;
            }
            $currency = strtoupper((string) $transaction->currency);
            $totals   = OrderTotal::query()->where('order_id', $order->id)->get();
            if ($totals->contains(fn (OrderTotal $total): bool => $total->code === PaypalBShopOrderService::ADJUSTMENT_CODE)) {
                continue;
            }

            $subMinor = 0;
            foreach (OrderProduct::query()->where('order_id', $order->id)->get() as $product) {
                $subMinor += PaypalBMoney::toMinor(PaypalBMoney::multiply((string) $product->price, (int) $product->quantity, $currency), $currency);
            }
            $feeMinor = 0;
            foreach ($totals as $total) {
                if (in_array($total->code, ['sub_total', 'order_total'], true)) {
                    continue;
                }
                $feeMinor += PaypalBMoney::signedToMinor((string) $total->value, $currency);
            }
            $adjustment = PaypalBMoney::toMinor((string) $transaction->amount, $currency) - $subMinor - $feeMinor;
            if ($adjustment === 0) {
                continue;
            }

            $value = ($adjustment < 0 ? '-' : '') . PaypalBMoney::fromMinor(abs($adjustment), $currency);
            if ($dryRun) {
                $this->line(sprintf('待补差价：订单 %s（%s）调整 %s %s', $order->number, $transaction->transaction_id, $value, $currency));

                continue;
            }

            DB::transaction(function () use ($order, $subMinor, $currency, $value): void {
                OrderTotal::query()->where('order_id', $order->id)->where('code', 'sub_total')
                    ->update(['value' => PaypalBMoney::fromMinor($subMinor, $currency)]);
                OrderTotal::query()->create([
                    'order_id'  => $order->id,
                    'code'      => PaypalBShopOrderService::ADJUSTMENT_CODE,
                    'title'     => '价格调整',
                    'value'     => $value,
                    'reference' => json_encode([], JSON_UNESCAPED_UNICODE),
                ]);
            });
            $updated++;
        }

        $this->info($dryRun ? '差价核对完成（未写入）。' : sprintf('已为 %d 笔镜像订单补差价调整行。', $updated));

        return self::SUCCESS;
    }
}
