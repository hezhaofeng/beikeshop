<?php

namespace Plugin\PaypalB\Services;

use Beike\Models\Order;
use Beike\Repositories\OrderPaymentRepo;
use Beike\Services\StateMachineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\PaypalB\Models\PaypalBTransaction;

/**
 * B 站自营订单的收款入账。
 *
 * 与跨站影子订单相反：这里的订单是本站真实订单，有真实商品和库存，
 * 因此必须走 StateMachineService，让扣库存、更新销量和买家通知正常发生。
 */
class PaypalBLocalOrderService
{
    public function markPaid(PaypalBTransaction $transaction): void
    {
        if (! $transaction->isLocal() || (string) $transaction->status !== 'completed') {
            return;
        }

        try {
            DB::transaction(function () use ($transaction): void {
                $order = $this->resolveOrder($transaction);
                if (! $order) {
                    throw new \RuntimeException('未找到与 PayPal 交易对应的本站订单。');
                }

                $payment = [
                    'transaction_id' => (string) $transaction->paypal_capture_id,
                    'request'        => [
                        'channel'         => PaypalBLocalPaymentService::CODE,
                        'source'          => PaypalBTransaction::SOURCE_LOCAL,
                        'reference'       => $transaction->reference,
                        'paypal_order_id' => $transaction->paypal_order_id,
                        'account_id'      => $transaction->account_id,
                        'amount'          => $transaction->amount,
                        'currency'        => $transaction->currency,
                    ],
                    'response'       => ['provider_status' => $transaction->provider_status],
                    'callback'       => ['paid_at' => $transaction->paid_at?->toIso8601String()],
                ];

                if ($order->status === StateMachineService::UNPAID) {
                    StateMachineService::getInstance($order)
                        ->setPayment($payment)
                        ->changeStatus(StateMachineService::PAID, 'PayPal 已确认收款。');

                    return;
                }

                // 订单已被后台改到其他状态时不强行推进，但已到账的款项必须留下审计记录。
                OrderPaymentRepo::createOrUpdatePayment($order->id, $payment);
            });
        } catch (\Throwable $exception) {
            // 入账失败不能回滚已经到账的收款，交由后台按交易记录人工处理。
            Log::error('PaypalB 自营订单入账失败，收款结果不受影响。', [
                'transaction_id'    => $transaction->transaction_id,
                'order_number'      => $transaction->order_number,
                'paypal_capture_id' => $transaction->paypal_capture_id,
                'error'             => $exception->getMessage(),
            ]);
            report($exception);
        }
    }

    /**
     * 优先用建单时锁定的订单 ID。
     *
     * 仅当交易早于 shop_order_id 落库（历史数据）时才回退到订单号反查，
     * 且必须排除跨站镜像订单：两站订单号可能撞号，而镜像订单建出来就是已支付状态，
     * 一旦命中会把自营收款审计写到镜像单上，真实订单则永远停在未支付。
     */
    private function resolveOrder(PaypalBTransaction $transaction): ?Order
    {
        if ($transaction->shop_order_id) {
            return Order::query()->lockForUpdate()->find($transaction->shop_order_id);
        }

        return Order::query()
            ->where('number', (string) $transaction->order_number)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('paypal_b_transactions')
                    ->whereColumn('paypal_b_transactions.shop_order_id', 'orders.id')
                    ->where('paypal_b_transactions.source', '<>', PaypalBTransaction::SOURCE_LOCAL);
            })
            ->lockForUpdate()
            ->first();
    }
}
