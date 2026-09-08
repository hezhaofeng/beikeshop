<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugin\PaypalB\Jobs\PaypalBRefundJob;
use Plugin\PaypalB\Models\PaypalBAccount;
use Plugin\PaypalB\Models\PaypalBRefund;
use Plugin\PaypalB\Models\PaypalBTransaction;

final class PaypalBRefundService
{
    private const ACTIVE_STATUSES = ['pending', 'processing', 'reconciling'];

    /**
     * 创建退款 outbox。事务锁同时保护累计退款额和同一捕获号的并发退款请求。
     */
    public function request(
        PaypalBTransaction $transaction,
        string $amount,
        string $noteToPayer = null,
        string $idempotencyKey = null
    ): PaypalBRefund {
        $currency       = strtoupper((string) $transaction->currency);
        $normalized     = PaypalBMoney::normalize($amount, $currency, 'amount');
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        try {
            $result = Cache::store('database')->lock('paypal_b:refund:transaction:' . $transaction->id, 180)
                ->block(15, fn (): array => DB::transaction(function () use (
                    $transaction,
                    $normalized,
                    $currency,
                    $noteToPayer,
                    $idempotencyKey
                ): array {
                    $locked = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
                    if ($idempotencyKey !== null) {
                        $existing = PaypalBRefund::query()
                            ->where('transaction_id', $locked->id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();
                        if ($existing) {
                            return ['refund' => $existing, 'created' => false];
                        }
                    }

                    if ($locked->status !== 'completed' || trim((string) $locked->paypal_capture_id) === '') {
                        throw ValidationException::withMessages([
                            'refund' => '只有已完成且有 PayPal 捕获号的交易可以退款。',
                        ]);
                    }
                    if (strtoupper((string) $locked->currency) !== $currency) {
                        throw ValidationException::withMessages(['amount' => '退款币种与原收款币种不一致。']);
                    }

                    $requestedMinor = PaypalBMoney::toMinor($normalized, $currency, 'amount');
                    if ($requestedMinor <= 0) {
                        throw ValidationException::withMessages(['amount' => '退款金额必须大于零。']);
                    }
                    $totalMinor    = PaypalBMoney::toMinor($locked->amount, $currency);
                    $reservedMinor = $this->refundSumMinor(
                        $locked,
                        array_merge(['completed'], self::ACTIVE_STATUSES)
                    );
                    if ($requestedMinor > $totalMinor - $reservedMinor) {
                        throw ValidationException::withMessages([
                            'amount' => '退款金额超过尚未退款的可用余额。',
                        ]);
                    }

                    $refund = PaypalBRefund::query()->create([
                        'transaction_id'  => $locked->id,
                        'request_id'      => $this->newRequestId('PB-REF-'),
                        'idempotency_key' => $idempotencyKey,
                        'amount'          => $normalized,
                        'currency'        => $currency,
                        'source'          => 'api',
                        'status'          => 'pending',
                        'note_to_payer'   => $this->normalizeNote($noteToPayer),
                    ]);
                    $locked->forceFill([
                        'refunded_amount' => PaypalBMoney::fromMinor(
                            $this->refundSumMinor($locked, ['completed']),
                            $currency
                        ),
                        'refund_status' => 'processing',
                    ])->saveOrFail();

                    return ['refund' => $refund, 'created' => true, 'transaction_id' => $locked->id];
                }));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'refund' => '该交易正在处理其他退款请求，请稍后重试。',
            ]);
        }

        /** @var PaypalBRefund $refund */
        $refund = $result['refund'];
        if ($result['created']) {
            PaypalBRefundJob::dispatch((int) $refund->id)->afterCommit();
            $this->queueCallback((int) $result['transaction_id']);
        }

        return $refund;
    }

    public function process(int $refundId): void
    {
        $claim = DB::transaction(function () use ($refundId): ?array {
            $refund = PaypalBRefund::query()->lockForUpdate()->find($refundId);
            if (! $refund || $refund->status === 'completed' || $refund->status === 'failed') {
                return null;
            }
            if ($refund->status === 'processing'
                && $refund->updated_at?->isAfter(now()->subMinutes(5))) {
                return null;
            }

            $transaction = PaypalBTransaction::query()->lockForUpdate()->find($refund->transaction_id);
            if (! $transaction) {
                $refund->forceFill([
                    'status'            => 'failed',
                    'failure_retryable' => false,
                    'failure_class'     => 'configuration',
                    'last_error'        => '退款对应的支付交易不存在。',
                    'processed_at'      => now(),
                ])->saveOrFail();

                return null;
            }
            if ($transaction->status !== 'completed' || ! $transaction->paypal_capture_id) {
                $this->markRefundFailedLocked($refund, $transaction, '原 PayPal 收款已不是可退款状态。', 'configuration');

                return null;
            }

            $refund->forceFill([
                'status'            => 'processing',
                'provider_status'   => null,
                'failure_retryable' => null,
                'failure_class'     => null,
                'last_error'        => null,
            ])->saveOrFail();

            return [
                'refund'      => $refund->fresh(),
                'transaction' => $transaction->fresh(),
            ];
        });

        if (! $claim) {
            return;
        }

        /** @var PaypalBRefund $refund */
        $refund      = $claim['refund'];
        $transaction = $claim['transaction'];
        $account     = $transaction->account;
        if (! $account || ! $account->usesApi()) {
            $this->markFailed($refund, $transaction, 'PayPal 收款账号不可用于退款。', false, 'configuration');

            return;
        }
        if (! $transaction->account_fingerprint
            || ! hash_equals((string) $transaction->account_fingerprint, $this->accountFingerprint($account))) {
            $this->markFailed($refund, $transaction, 'PayPal 收款应用已变化，退款必须由后台核对后处理。', false, 'configuration');

            return;
        }

        try {
            $configuration = PaypalBConfiguration::all();
            $provider      = (new PaypalBApiClient(
                $account,
                (bool) $configuration['sandbox_mode'],
                (int) $configuration['request_timeout_seconds']
            ))->refundCapture(
                (string) $transaction->paypal_capture_id,
                (string) $refund->request_id,
                (string) $transaction->reference,
                (string) $refund->amount,
                (string) $refund->currency,
                $refund->note_to_payer
            );
            $providerData = $this->assertProviderRefund($provider, $refund);
            if ($providerData['status'] !== 'COMPLETED') {
                $this->markReconciling($refund, $transaction, $provider, 'PayPal 退款结果仍在确认。');

                throw new \RuntimeException('PayPal 退款结果仍在确认。');
            }

            $this->completeRefund($refund, $transaction, $provider, $providerData['id']);
        } catch (PaypalBApiException $exception) {
            if ($exception->retryable) {
                $this->markReconciling($refund, $transaction, $this->errorPayload($exception), $exception->getMessage());

                throw $exception;
            }

            $this->markFailed($refund, $transaction, $exception->getMessage(), false, $exception->failureClass(), $this->errorPayload($exception));
        } catch (ValidationException $exception) {
            $this->markFailed($refund, $transaction, $exception->getMessage(), false, 'provider_rejected');
        } catch (\Throwable $exception) {
            $this->markReconciling($refund, $transaction, $this->errorPayload($exception), $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * PayPal 退款 Webhook 可能不携带具体 refund 对象；缺少可核对金额时只进入对账状态，不自动取消 A 站订单。
     */
    public function applyWebhook(PaypalBTransaction $transaction, array $event): PaypalBTransaction
    {
        $type      = (string) ($event['event_type'] ?? '');
        $resource  = (array) ($event['resource'] ?? []);
        $captureId = trim((string) ($resource['id'] ?? ''));
        if ($transaction->paypal_capture_id && ! hash_equals((string) $transaction->paypal_capture_id, $captureId)) {
            throw ValidationException::withMessages(['webhook' => 'PayPal 退款事件与原捕获号不一致。']);
        }

        if ($type === 'PAYMENT.CAPTURE.REFUNDED') {
            $refundData = $this->webhookRefundData($resource);
            if ($refundData === null) {
                $this->markTransactionReconciling($transaction, $event, '退款 Webhook 缺少可核对的退款号或金额。');

                return $transaction->fresh();
            }

            $this->recordExternalRefund(
                $transaction,
                $event,
                $refundData['id'],
                $refundData['amount'],
                $refundData['currency'],
                'webhook'
            );

            return $transaction->fresh();
        }

        if ($type === 'PAYMENT.CAPTURE.REVERSED') {
            $amount   = trim((string) data_get($resource, 'amount.value', ''));
            $currency = strtoupper(trim((string) data_get($resource, 'amount.currency_code', '')));
            if ($amount === '' || $currency === '') {
                $this->markTransactionReconciling($transaction, $event, 'PayPal 撤销事件缺少可核对的金额或币种。');

                return $transaction->fresh();
            }
            if ($currency !== strtoupper((string) $transaction->currency)) {
                throw ValidationException::withMessages(['webhook' => 'PayPal 撤销事件币种与原收款不一致。']);
            }

            $this->recordExternalRefund($transaction, $event, null, $amount, $currency, 'reversal');

            return $transaction->fresh();
        }

        throw ValidationException::withMessages(['webhook' => '不支持的 PayPal 退款事件类型。']);
    }

    public function retry(PaypalBRefund $refund): void
    {
        if (! in_array($refund->status, ['reconciling'], true)) {
            throw ValidationException::withMessages(['refund' => '只有待对账退款可以重试。']);
        }

        $refund->forceFill([
            'status'            => 'pending',
            'failure_retryable' => null,
            'failure_class'     => null,
            'last_error'        => null,
        ])->saveOrFail();
        PaypalBRefundJob::dispatch((int) $refund->id)->afterCommit();
    }

    private function completeRefund(
        PaypalBRefund $refund,
        PaypalBTransaction $transaction,
        array $provider,
        string $providerRefundId
    ): void {
        $transactionId = $transaction->id;
        $changed       = DB::transaction(function () use ($refund, $provider, $providerRefundId, $transactionId): bool {
            $lockedRefund      = PaypalBRefund::query()->lockForUpdate()->findOrFail($refund->id);
            $lockedTransaction = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transactionId);

            return $this->settleCompletedRefundLocked(
                $lockedRefund,
                $lockedTransaction,
                $provider,
                $providerRefundId
            );
        });

        if ($changed) {
            $this->queueCallback($transactionId);
        }
    }

    private function recordExternalRefund(
        PaypalBTransaction $transaction,
        array $event,
        ?string $providerRefundId,
        string $amount,
        string $currency,
        string $source
    ): void {
        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId === '') {
            throw ValidationException::withMessages(['webhook' => 'PayPal 退款事件缺少事件号。']);
        }
        $currency = strtoupper($currency);
        $amount   = PaypalBMoney::normalize($amount, $currency, 'refund.amount');
        if ($currency !== strtoupper((string) $transaction->currency)) {
            throw ValidationException::withMessages(['webhook' => 'PayPal 退款币种与原收款不一致。']);
        }

        $result = DB::transaction(function () use (
            $transaction,
            $event,
            $eventId,
            $providerRefundId,
            $amount,
            $currency,
            $source
        ): array {
            $lockedTransaction = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $existing          = PaypalBRefund::query()->where('provider_event_id', $eventId)->first();
            if ($existing) {
                if ((int) $existing->transaction_id !== (int) $lockedTransaction->id) {
                    throw ValidationException::withMessages(['webhook' => 'PayPal 退款事件已经绑定到其他支付交易。']);
                }

                return ['refund' => $existing, 'changed' => false];
            }
            if ($providerRefundId !== null) {
                $existing = PaypalBRefund::query()->where('provider_refund_id', $providerRefundId)->first();
                if ($existing) {
                    if ((int) $existing->transaction_id !== (int) $lockedTransaction->id) {
                        throw ValidationException::withMessages(['webhook' => 'PayPal 退款号已经绑定到其他支付交易。']);
                    }
                    if (strtoupper((string) $existing->currency) !== $currency
                        || ! PaypalBMoney::equal($existing->amount, $amount, $currency)) {
                        throw ValidationException::withMessages(['webhook' => 'PayPal Webhook 退款金额与本地退款记录不一致。']);
                    }

                    $changed = $this->settleCompletedRefundLocked(
                        $existing,
                        $lockedTransaction,
                        $event,
                        $providerRefundId,
                        $eventId
                    );

                    return ['refund' => $existing, 'changed' => $changed];
                }
            }

            $refund = PaypalBRefund::query()->create([
                'transaction_id'     => $lockedTransaction->id,
                'request_id'         => $this->newRequestId('PB-WEB-'),
                'amount'             => $amount,
                'currency'           => $currency,
                'source'             => $source,
                'status'             => 'pending',
            ]);
            $changed = $this->settleCompletedRefundLocked(
                $refund,
                $lockedTransaction,
                $event,
                $providerRefundId,
                $eventId
            );

            return ['refund' => $refund, 'changed' => $changed];
        });

        if ($result['changed']) {
            $this->queueCallback((int) $transaction->id);
        }
    }

    /**
     * 在已锁定的退款和交易上完成一次结算，API 响应与外部 Webhook 共用这条路径。
     */
    private function settleCompletedRefundLocked(
        PaypalBRefund $refund,
        PaypalBTransaction $transaction,
        array $provider,
        string $providerRefundId = null,
        string $providerEventId = null
    ): bool {
        $providerRefundId = $providerRefundId !== null ? trim($providerRefundId) : null;
        $providerEventId  = $providerEventId  !== null ? trim($providerEventId) : null;
        if ($providerRefundId === '') {
            throw ValidationException::withMessages(['refund' => 'PayPal 退款号不能为空。']);
        }
        if ($providerEventId === '') {
            throw ValidationException::withMessages(['webhook' => 'PayPal 退款事件号不能为空。']);
        }
        if (strtoupper((string) $refund->currency) !== strtoupper((string) $transaction->currency)) {
            throw ValidationException::withMessages(['refund' => '退款币种与原收款币种不一致。']);
        }
        if ($providerRefundId !== null && PaypalBRefund::query()
            ->where('provider_refund_id', $providerRefundId)
            ->where('id', '<>', $refund->id)
            ->exists()) {
            throw ValidationException::withMessages(['refund' => '该 PayPal 退款号已经绑定到其他退款记录。']);
        }

        $completedMinor = $this->refundSumMinor($transaction, ['completed'], $refund->id);
        $refundMinor    = PaypalBMoney::toMinor($refund->amount, (string) $refund->currency);
        $totalMinor     = PaypalBMoney::toMinor($transaction->amount, (string) $transaction->currency);
        if ($refundMinor <= 0 || $completedMinor > $totalMinor - $refundMinor) {
            throw ValidationException::withMessages(['refund' => '退款累计额超过原 PayPal 收款额。']);
        }

        $newRefundedMinor = $completedMinor + $refundMinor;
        $fullyRefunded    = $newRefundedMinor === $totalMinor;
        $refund->forceFill([
            'provider_refund_id' => $providerRefundId,
            'provider_event_id'  => $providerEventId,
            'status'             => 'completed',
            'provider_status'    => 'COMPLETED',
            'provider_payload'   => $provider,
            'failure_retryable'  => false,
            'failure_class'      => null,
            'last_error'         => null,
            'processed_at'       => now(),
            'refunded_at'        => now(),
        ])->saveOrFail();
        $transaction->forceFill([
            'refunded_amount'           => PaypalBMoney::fromMinor($newRefundedMinor, (string) $transaction->currency),
            'refund_status'             => $fullyRefunded ? 'completed' : 'partial',
            // A 站只接收完整退款的取消状态，部分退款保持已支付。
            'status'                    => $fullyRefunded ? 'cancelled' : 'completed',
            'payment_exception_status'  => null,
            'payment_exception_payload' => null,
        ])->saveOrFail();

        return true;
    }

    private function markReconciling(
        PaypalBRefund $refund,
        PaypalBTransaction $transaction,
        array $provider,
        string $message
    ): void {
        DB::transaction(function () use ($refund, $transaction, $provider, $message): void {
            $lockedRefund = PaypalBRefund::query()->lockForUpdate()->findOrFail($refund->id);
            $lockedRefund->forceFill([
                'status'            => 'reconciling',
                'provider_payload'  => $provider,
                'failure_retryable' => false,
                'failure_class'     => 'provider_unknown',
                'last_error'        => mb_substr($message, 0, 2000),
            ])->saveOrFail();
            $lockedTransaction = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $lockedTransaction->forceFill([
                'refund_status'             => 'reconciling',
                'payment_exception_status'  => 'refund_reconciling',
                'payment_exception_payload' => [
                    'message'     => mb_substr($message, 0, 2000),
                    'refund_id'   => $lockedRefund->id,
                    'recorded_at' => now()->toIso8601String(),
                ],
            ])->saveOrFail();
        });
        $this->queueCallback((int) $transaction->id);
    }

    private function markFailed(
        PaypalBRefund $refund,
        PaypalBTransaction $transaction,
        string $message,
        bool $retryable,
        string $failureClass,
        array $provider = []
    ): void {
        DB::transaction(function () use ($refund, $transaction, $message, $retryable, $failureClass, $provider): void {
            $lockedRefund = PaypalBRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($lockedRefund->status === 'completed') {
                return;
            }
            $lockedRefund->forceFill([
                'status'            => 'failed',
                'provider_payload'  => $provider ?: null,
                'failure_retryable' => $retryable,
                'failure_class'     => $failureClass,
                'last_error'        => mb_substr($message, 0, 2000),
                'processed_at'      => now(),
            ])->saveOrFail();
            $lockedTransaction = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $lockedTransaction->forceFill([
                'refund_status'              => $this->transactionRefundStatus($lockedTransaction),
                'payment_exception_status'   => 'refund_failed',
                'payment_exception_payload'  => [
                    'message'       => mb_substr($message, 0, 2000),
                    'refund_id'     => $lockedRefund->id,
                    'failure_class' => $failureClass,
                    'recorded_at'   => now()->toIso8601String(),
                ],
            ])->saveOrFail();
        });
        $this->queueCallback((int) $transaction->id);
    }

    private function markRefundFailedLocked(
        PaypalBRefund $refund,
        PaypalBTransaction $transaction,
        string $message,
        string $failureClass
    ): void {
        $refund->forceFill([
            'status'            => 'failed',
            'failure_retryable' => false,
            'failure_class'     => $failureClass,
            'last_error'        => $message,
            'processed_at'      => now(),
        ])->saveOrFail();
        $transaction->forceFill([
            'refund_status' => $this->transactionRefundStatus($transaction),
        ])->saveOrFail();
    }

    private function markTransactionReconciling(PaypalBTransaction $transaction, array $event, string $message): void
    {
        $transaction->forceFill([
            'refund_status'              => 'reconciling',
            'payment_exception_status'   => 'refund_webhook_reconciling',
            'payment_exception_payload'  => [
                'message'     => $message,
                'event_id'    => (string) ($event['id'] ?? ''),
                'event_type'  => (string) ($event['event_type'] ?? ''),
                'recorded_at' => now()->toIso8601String(),
            ],
        ])->saveOrFail();
        $this->queueCallback((int) $transaction->id);
    }

    private function assertProviderRefund(array $provider, PaypalBRefund $refund): array
    {
        $id       = trim((string) ($provider['id'] ?? ''));
        $status   = strtoupper(trim((string) ($provider['status'] ?? '')));
        $amount   = trim((string) data_get($provider, 'amount.value', ''));
        $currency = strtoupper(trim((string) data_get($provider, 'amount.currency_code', '')));
        if ($id === '' || $status === '' || $amount === '' || $currency === '') {
            throw PaypalBApiException::malformedResponse('PayPal 退款响应缺少退款号、状态或金额。');
        }
        if ($currency !== strtoupper((string) $refund->currency)
            || ! PaypalBMoney::equal($refund->amount, $amount, (string) $refund->currency)) {
            throw PaypalBApiException::malformedResponse('PayPal 退款金额或币种与本地退款请求不一致。');
        }

        return ['id' => $id, 'status' => $status];
    }

    private function webhookRefundData(array $resource): ?array
    {
        $refund = data_get($resource, 'refunds.0');
        if (! is_array($refund)) {
            $refund = data_get($resource, 'refund');
        }
        if (! is_array($refund)) {
            return null;
        }

        $id       = trim((string) ($refund['id'] ?? ''));
        $amount   = trim((string) data_get($refund, 'amount.value', ''));
        $currency = strtoupper(trim((string) data_get($refund, 'amount.currency_code', '')));
        if ($id === '' || $amount === '' || $currency === '') {
            return null;
        }

        return ['id' => $id, 'amount' => $amount, 'currency' => $currency];
    }

    private function refundSumMinor(
        PaypalBTransaction $transaction,
        array $statuses,
        int $exceptRefundId = null
    ): int {
        $query = PaypalBRefund::query()
            ->where('transaction_id', $transaction->id)
            ->whereIn('status', $statuses);
        if ($exceptRefundId !== null) {
            $query->where('id', '<>', $exceptRefundId);
        }

        $sum = 0;
        foreach ($query->get(['amount', 'currency']) as $refund) {
            $minor = PaypalBMoney::toMinor($refund->amount, (string) $refund->currency);
            if ($minor > 0 && $sum > PHP_INT_MAX - $minor) {
                throw ValidationException::withMessages(['refund' => '退款累计额超出可计算范围。']);
            }
            $sum += $minor;
        }

        return $sum;
    }

    private function transactionRefundStatus(PaypalBTransaction $transaction): string
    {
        $completed = $this->refundSumMinor($transaction, ['completed']);
        $total     = PaypalBMoney::toMinor($transaction->amount, (string) $transaction->currency);
        if ($completed >= $total && $total > 0) {
            return 'completed';
        }
        if ($completed > 0) {
            return 'partial';
        }
        if (PaypalBRefund::query()
            ->where('transaction_id', $transaction->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists()) {
            return 'processing';
        }

        return 'failed';
    }

    private function normalizeIdempotencyKey(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => '退款幂等键格式无效。']);
        }

        return $key;
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 255);
    }

    private function newRequestId(string $prefix): string
    {
        return $prefix . Str::upper(Str::random(30));
    }

    private function queueCallback(int $transactionId): void
    {
        try {
            $transaction = PaypalBTransaction::query()->find($transactionId);
            if ($transaction) {
                app(PaypalBCallbackService::class)->queue($transaction);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function accountFingerprint(PaypalBAccount $account): string
    {
        $configuration = PaypalBConfiguration::all();

        return hash('sha256', implode('|', [
            (string) $account->id,
            (string) $account->client_id,
            (bool) $configuration['sandbox_mode'] ? 'sandbox' : 'live',
        ]));
    }

    private function errorPayload(\Throwable $exception): array
    {
        return [
            'class'       => get_class($exception),
            'message'     => mb_substr($exception->getMessage(), 0, 2000),
            'http_status' => $exception instanceof PaypalBApiException ? $exception->httpStatus : null,
            'paypal_name' => $exception instanceof PaypalBApiException ? $exception->paypalName : '',
            'issues'      => $exception instanceof PaypalBApiException ? $exception->issues : [],
            'retryable'   => $exception instanceof PaypalBApiException && $exception->retryable,
            'recorded_at' => now()->toIso8601String(),
        ];
    }
}
