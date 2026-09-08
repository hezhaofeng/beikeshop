<?php

namespace Plugin\PaypalB\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugin\PaypalB\Models\PaypalBWebhookEvent;
use Plugin\PaypalB\Services\PaypalBTransactionService;

class PaypalBWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('paypal_b');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(PaypalBTransactionService $service): void
    {
        $event = DB::transaction(function (): ?PaypalBWebhookEvent {
            $record = PaypalBWebhookEvent::query()->lockForUpdate()->find($this->webhookEventId);
            if (! $record || in_array($record->status, ['processed', 'ignored'], true)) {
                return null;
            }
            if ($record->status === 'processing'
                && $record->updated_at?->isAfter(now()->subMinutes(5))) {
                // 同一事件的重复投递已经由其他 Worker 处理，避免并发重复推进订单。
                return null;
            }

            $record->forceFill([
                'status'     => 'processing',
                'last_error' => null,
            ])->saveOrFail();

            return $record->fresh();
        });

        if (! $event) {
            return;
        }

        $payload     = (array) $event->payload;
        $transaction = $service->findWebhookTransaction($payload, (int) $event->account_id);
        if (! $transaction) {
            $event->forceFill([
                'status'       => 'ignored',
                'last_error'   => '未找到与 PayPal Order、invoice_id 和捕获号同时匹配的支付会话。',
                'processed_at' => now(),
            ])->saveOrFail();

            return;
        }

        try {
            $service->applyWebhook($transaction, $payload);
            $event->forceFill([
                'status'         => 'processed',
                'transaction_id' => $transaction->id,
                'last_error'     => null,
                'processed_at'   => now(),
            ])->saveOrFail();
        } catch (ValidationException $exception) {
            // 已验签但字段不匹配属于审计事件，不因重复重试反复触碰订单状态。
            $event->forceFill([
                'status'       => 'ignored',
                'last_error'   => mb_substr($exception->getMessage(), 0, 2000),
                'processed_at' => now(),
            ])->saveOrFail();
        } catch (\Throwable $exception) {
            $event->forceFill([
                'status'     => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->saveOrFail();

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        PaypalBWebhookEvent::query()
            ->whereKey($this->webhookEventId)
            ->whereNotIn('status', ['processed', 'ignored'])
            ->update([
                'status'     => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
    }
}
