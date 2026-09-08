<?php

namespace Plugin\PaypalA\Services;

use Beike\Models\Order;
use Illuminate\Support\Facades\DB;
use Plugin\PaypalA\Jobs\PaypalAFulfillmentSyncJob;
use Plugin\PaypalA\Models\PaypalATransaction;

/**
 * 把发货证据从 A 站送到 B 站。B 站用它应对 PayPal 拒付举证，
 * 因此投递必须可重试、可补偿，不能像原先那样失败即丢。
 */
class PaypalAFulfillmentService
{
    /**
     * 只有已收款且在 B 站有会话的交易才有履约证据可同步。
     */
    public function transactionFor(Order $order): ?PaypalATransaction
    {
        return PaypalATransaction::query()
            ->where('order_id', $order->id)
            ->where('status', 'completed')
            ->whereNotNull('b_transaction_id')
            ->latest('id')
            ->first();
    }

    /**
     * 发货数据变化时入队。证据未变则不投递，
     * 否则订单状态机每流转一次都会重打一次 B 站接口。
     */
    public function queue(Order $order): void
    {
        $transaction = $this->transactionFor($order);
        if (! $transaction) {
            return;
        }

        $digest = $this->digest($order);
        if ($digest === null) {
            return;
        }
        if ($transaction->fulfillment_status === 'sent'
            && hash_equals((string) $transaction->fulfillment_digest, $digest)) {
            return;
        }

        $transaction->forceFill([
            'fulfillment_status'          => 'pending',
            'fulfillment_next_attempt_at' => now(),
            'fulfillment_last_error'      => null,
        ])->saveOrFail();

        PaypalAFulfillmentSyncJob::dispatch((int) $transaction->id)->afterCommit();
    }

    /**
     * 返回 false 表示本次投递失败且应当由队列重试。
     */
    public function send(PaypalATransaction $transaction, bool $force = false): bool
    {
        $transaction = PaypalATransaction::query()->find($transaction->id);
        if (! $transaction
            || $transaction->status !== 'completed'
            || ! $transaction->b_transaction_id) {
            return true;
        }

        $order = Order::query()->find($transaction->order_id);
        if (! $order) {
            return true;
        }

        $digest = $this->digest($order);
        if ($digest === null) {
            // 发货单被撤回后没有证据可送，保留上一次成功状态即可。
            return true;
        }
        if (! $force
            && $transaction->fulfillment_status === 'sent'
            && hash_equals((string) $transaction->fulfillment_digest, $digest)) {
            return true;
        }

        $claim = DB::transaction(function () use ($transaction, $digest, $force): array {
            $locked = PaypalATransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if (! $force
                && $locked->fulfillment_status === 'sent'
                && hash_equals((string) $locked->fulfillment_digest, $digest)) {
                return ['claimed' => false];
            }
            if (! $force
                && $locked->fulfillment_status === 'sending'
                && $locked->fulfillment_next_attempt_at?->isFuture()) {
                // 另一个 Worker 已领取且仍在租约内，避免同一份证据并发送两次。
                return ['claimed' => false];
            }

            $attempt = ((int) $locked->fulfillment_attempts) + 1;
            $locked->forceFill([
                'fulfillment_status'          => 'sending',
                // 请求超时最多 30 秒，5 分钟租约足以覆盖 Worker 短暂中断。
                'fulfillment_next_attempt_at' => now()->addMinutes(5),
                'fulfillment_attempts'        => $attempt,
            ])->saveOrFail();

            return ['claimed' => true, 'attempt' => $attempt];
        });
        if (! $claim['claimed']) {
            return true;
        }
        $attempt = (int) $claim['attempt'];

        try {
            $configuration = PaypalAPaymentService::bridgeConfiguration(
                plugin_setting(PaypalAPaymentService::CODE, [])
            );
            (new PaypalABridgeClient($configuration))->syncFulfillment($transaction, $order);

            DB::transaction(function () use ($transaction, $digest, $attempt): void {
                $locked = PaypalATransaction::query()->lockForUpdate()->findOrFail($transaction->id);
                if ((int) $locked->fulfillment_attempts !== $attempt) {
                    // 已被更新的证据取代，本次结果不再写回。
                    return;
                }

                $locked->forceFill([
                    'fulfillment_status'          => 'sent',
                    'fulfillment_digest'          => $digest,
                    'fulfillment_next_attempt_at' => null,
                    'fulfillment_last_sent_at'    => now(),
                    'fulfillment_last_error'      => null,
                ])->saveOrFail();
            });

            return true;
        } catch (\Throwable $exception) {
            $retry = DB::transaction(function () use ($transaction, $attempt, $exception): bool {
                $locked = PaypalATransaction::query()->lockForUpdate()->findOrFail($transaction->id);
                if ((int) $locked->fulfillment_attempts !== $attempt) {
                    return false;
                }

                $locked->forceFill([
                    'fulfillment_status'          => 'failed',
                    'fulfillment_next_attempt_at' => now()->addSeconds($this->retryDelay($attempt)),
                    'fulfillment_last_error'      => mb_substr($exception->getMessage(), 0, 2000),
                ])->saveOrFail();

                return true;
            });

            return ! $retry;
        }
    }

    /**
     * 没有发货单时返回 null：无证据可送，不是失败。
     */
    private function digest(Order $order): ?string
    {
        // 钩子传入的订单可能已加载过变更前的发货单关联，这里必须强制重读：
        // 用 loadMissing 会沿用旧数据，导致刚发生的发货变更被判定为"证据未变"而永久跳过。
        $order->load('orderShipments');
        if ($order->orderShipments->isEmpty()) {
            return null;
        }

        $fulfillment = PaypalAOrderSnapshot::fromOrder($order)['fulfillment'];
        // synced_at 每次生成都不同，计入摘要会让任何一次钩子都被判定为"证据已变更"。
        unset($fulfillment['synced_at']);

        return hash('sha256', BridgeSignature::canonicalJson($fulfillment));
    }

    private function retryDelay(int $attempt): int
    {
        return match (true) {
            $attempt <= 1 => 60,
            $attempt <= 2 => 300,
            $attempt <= 3 => 900,
            $attempt <= 4 => 3600,
            default       => 7200,
        };
    }
}
