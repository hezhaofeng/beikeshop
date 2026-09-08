<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Plugin\PaypalB\Jobs\PaypalBCallbackJob;
use Plugin\PaypalB\Models\PaypalBTransaction;

class PaypalBCallbackService
{
    public function queue(PaypalBTransaction $transaction): void
    {
        // 自营订单没有 A 站回调目标，入队只会产生必然失败的重试。
        if ($transaction->isLocal()) {
            $transaction->forceFill([
                'callback_status'          => 'skipped',
                'callback_next_attempt_at' => null,
                'callback_last_error'      => null,
            ])->saveOrFail();

            return;
        }

        $transaction->forceFill([
            'callback_status'          => 'pending',
            'callback_next_attempt_at' => now(),
            'callback_last_error'      => null,
        ])->saveOrFail();

        PaypalBCallbackJob::dispatch((int) $transaction->id)->afterCommit();
    }

    public function send(PaypalBTransaction $transaction, bool $force = false): bool
    {
        $transaction = PaypalBTransaction::query()->findOrFail($transaction->id);
        if ($transaction->isLocal()) {
            return false;
        }
        if (! in_array($transaction->status, ['completed', 'cancelled', 'failed', 'expired'], true)) {
            return false;
        }

        $payload = app(PaypalBTransactionService::class)->statusPayload($transaction);
        if (! $force
            && $transaction->callback_status                               === 'sent'
            && $this->callbackPayloadMatches((array) $transaction->callback_payload, $payload)) {
            return true;
        }

        $snapshotStatus = (string) $payload['status'];
        $claim          = DB::transaction(function () use ($transaction, $payload, $snapshotStatus, $force): array {
            $locked = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ((string) $locked->status !== $snapshotStatus) {
                $this->requeueForLatestStatus($locked);

                return ['claimed' => false];
            }
            if (! $force
                && $locked->callback_status                               === 'sent'
                && $this->callbackPayloadMatches((array) $locked->callback_payload, $payload)) {
                return ['claimed' => false];
            }
            if (! $force
                && $locked->callback_status === 'sending'
                && $locked->callback_next_attempt_at?->isFuture()) {
                // 另一个 Worker 已经领取且仍在租约内，避免同一状态并发发送两次。
                return ['claimed' => false];
            }

            $attempt = ((int) $locked->callback_attempts) + 1;
            $locked->forceFill([
                'callback_status'          => 'sending',
                // 请求超时时间最多 30 秒，保留 5 分钟租约可覆盖 Worker 短暂中断。
                'callback_next_attempt_at' => now()->addMinutes(5),
                'callback_payload'         => $payload,
                'callback_attempts'        => $attempt,
            ])->saveOrFail();

            return ['claimed' => true, 'attempt' => $attempt];
        });
        if (! $claim['claimed']) {
            return true;
        }
        $attempt = (int) $claim['attempt'];

        $configuration = PaypalBConfiguration::all();

        try {
            $response = Http::timeout($configuration['request_timeout_seconds'])
                ->acceptJson()
                ->asJson()
                ->withHeaders(BridgeSignature::headers($payload, $configuration['a_callback_signing_secret']))
                ->post((string) $transaction->callback_url, $payload);

            if (! $response->successful()) {
                throw new \RuntimeException('A 站回调返回 HTTP ' . $response->status() . '。');
            }

            DB::transaction(function () use ($transaction, $payload, $snapshotStatus, $attempt): void {
                $locked = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
                if ((int) $locked->callback_attempts !== $attempt) {
                    return;
                }
                if ((string) $locked->status !== $snapshotStatus
                    || ! $this->callbackPayloadMatches((array) $locked->callback_payload, $payload)) {
                    $this->requeueForLatestStatus($locked);

                    return;
                }

                $locked->forceFill([
                    'callback_status'          => 'sent',
                    'callback_payload'         => $payload,
                    'callback_next_attempt_at' => null,
                    'callback_last_sent_at'    => now(),
                    'callback_last_error'      => null,
                ])->saveOrFail();
            });

            return true;
        } catch (\Throwable $exception) {
            $retry = DB::transaction(function () use ($transaction, $payload, $snapshotStatus, $attempt, $exception): bool {
                $locked = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
                if ((int) $locked->callback_attempts !== $attempt) {
                    return false;
                }
                if ((string) $locked->status !== $snapshotStatus
                    || ! $this->callbackPayloadMatches((array) $locked->callback_payload, $payload)) {
                    $this->requeueForLatestStatus($locked);

                    return false;
                }

                $locked->forceFill([
                    'callback_status'          => 'failed',
                    'callback_payload'         => $payload,
                    'callback_next_attempt_at' => now()->addSeconds($this->retryDelay($attempt)),
                    'callback_last_error'      => mb_substr($exception->getMessage(), 0, 2000),
                ])->saveOrFail();

                return true;
            });

            return ! $retry;
        }
    }

    private function requeueForLatestStatus(PaypalBTransaction $transaction): void
    {
        $callbackPayload = (array) $transaction->callback_payload;
        if ($transaction->callback_status                  === 'sent'
            && (string) ($callbackPayload['status'] ?? '') === (string) $transaction->status) {
            return;
        }

        $transaction->forceFill([
            'callback_status'          => 'pending',
            'callback_next_attempt_at' => now(),
            'callback_last_error'      => null,
        ])->saveOrFail();
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

    private function callbackPayloadMatches(array $stored, array $current): bool
    {
        foreach (['status', 'refund_status', 'refunded_amount', 'dispute_status', 'provider_transaction_id'] as $field) {
            if ((string) data_get($stored, $field, '') !== (string) data_get($current, $field, '')) {
                return false;
            }
        }

        return true;
    }
}
