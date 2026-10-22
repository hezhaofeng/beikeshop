<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\PaypalB\Jobs\PaypalBTrackingJob;
use Plugin\PaypalB\Models\PaypalBAccount;
use Plugin\PaypalB\Models\PaypalBTransaction;

/**
 * 把会话上的履约证据（A 站同步来的运单）上报到 PayPal Orders v2 运单接口，供 PayPal 自动查询轨迹、
 * 支撑卖家保障与争议举证。
 *
 * 与 A 站回调一样走 outbox：先在会话上登记待上报，本次请求响应后在同一进程内上报（不使用队列），失败按退避重试；
 * 每 5 分钟的补投命令兜底。同一运单号在 PayPal 侧幂等（重复提交返回同一 tracker），已上报的运单
 * 记在 tracking_payload 里，不会重复调用；A 站删除运单时把对应 tracker 置为 CANCELLED。
 *
 * tracking_status 取值：
 * - idle：没有需要上报的运单；pending：待上报；sending：某个 进程 持有 5 分钟租约正在上报；
 * - sent：与 PayPal 侧一致（终态）；failed：可重试故障，按退避等待下一次；
 * - abandoned：永久错误或一轮内连续失败 MAX_ATTEMPTS 次，停止自动重试（终态），
 *   等待履约证据出现新差异或后台“重新上报运单”。
 *
 * tracking_payload 按运单号记录 PayPal 侧状态：status 为 SHIPPED / CANCELLED（PayPal 侧 tracker 状态）、
 * FAILED（未能登记）、SKIPPED（本地判定不能上报，例如超长运单号）；失败时另记 error、permanent 与
 * failed_target（失败时想达到的承运商），同一目标的永久错误不再自动重试。
 */
class PaypalBTrackingService
{
    /**
     * 一轮上报内连续失败的上限：按 60/300/900/3600/7200 秒退避，第 6 次仍失败后转人工处理。
     */
    public const MAX_ATTEMPTS = 6;

    /**
     * PayPal Orders v2 运单号上限；A 站接受最长 128 位，超出的运单号只能记录跳过原因。
     */
    public const MAX_TRACKING_NUMBER_LENGTH = 64;

    /**
     * tracking_attempts 只增不减，写回时据此确认仍持有本次租约（同 PaypalBCallbackService）。
     * 每轮上报从下一个 ROUND_STRIDE 的整数倍起算，轮内失败次数 = tracking_attempts % ROUND_STRIDE；
     * 不清零是为了让新一轮的领取号不会与旧 进程 的领取号相同，旧结果不会误写回。
     */
    private const ROUND_STRIDE = 100;

    private const LEASE_MINUTES = 5;

    /** failed_target 的特殊取值：运单已被 A 站删除、运单号超长。承运商签名含换行，不会与之相同。 */
    private const TARGET_REMOVED = 'REMOVED';

    private const TARGET_OVERSIZED = 'OVERSIZED';

    public function __construct(private readonly array $configuration)
    {
    }

    /**
     * 有新运单或运单变更时调用：登记待上报并在响应后上报。收款未完成时只登记，capture 完成后由 onCompleted() 再上报。
     *
     * 其他 进程 正在租约内上报时不改状态：它写回时会与最新履约证据比较，有差异会自行开始新一轮；
     * 这里强改成 pending 会破坏租约、造成同一运单并发上报。
     */
    public function queue(PaypalBTransaction $transaction): void
    {
        if (! $this->enabled() || ! $this->applicable($transaction)) {
            return;
        }

        $queued = DB::transaction(function () use ($transaction): bool {
            $locked = PaypalBTransaction::query()->lockForUpdate()->find($transaction->id);
            if (! $locked || $this->leaseActive($locked)) {
                return false;
            }
            // 已转人工的会话只在出现新差异时重新开始，A 站重复推送同样的运单不会再触发一轮自动重试。
            if (! $this->hasPendingChanges($locked, $locked->tracking_status === 'abandoned')) {
                $this->settle($locked);

                return false;
            }

            $this->startRound($locked);

            return (string) $locked->status === 'completed';
        });

        if ($queued) {
            PaypalBTrackingJob::dispatchAfterResponse((int) $transaction->id);
        }
    }

    /**
     * 收款入账后：之前只登记未上报的运单（买家先付款审核、后发货又先于 PayPal 放款的情况）此时补报。
     */
    public function onCompleted(PaypalBTransaction $transaction): void
    {
        if (! $this->enabled() || $transaction->status !== 'completed') {
            return;
        }
        if ($this->hasPendingChanges($transaction)) {
            $this->queue($transaction);
        }
    }

    /**
     * 执行上报。返回 true 表示无事可做、已与 PayPal 一致或由别的 进程 处理；false 表示有失败（已登记重试或转人工）。
     *
     * $force 为后台“重新上报运单”：开始新的一轮（失败次数清零），忽略退避时间、已转人工状态和永久错误标记；
     * 仍然尊重其他 进程 的租约，避免同一运单号被并发取消 / 登记。
     */
    public function send(PaypalBTransaction $transaction, bool $force = false): bool
    {
        $transaction = PaypalBTransaction::query()->with('account')->findOrFail($transaction->id);
        if (! $this->enabled() || ! $this->applicable($transaction)) {
            return true;
        }
        if ($transaction->status !== 'completed' || trim((string) $transaction->paypal_capture_id) === '') {
            // PayPal 只接受 COMPLETED 捕获的运单；待确认收款等 Webhook 放款后再报。
            return true;
        }
        if (! $force && ! $this->hasPendingChanges($transaction)) {
            // 与 PayPal 已一致却仍停在 pending / failed：落到终态，免得补投命令每 5 分钟反复捞起。
            DB::transaction(function () use ($transaction): void {
                $this->settle(PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id));
            });

            return true;
        }

        $claimed = DB::transaction(function () use ($transaction, $force): ?PaypalBTransaction {
            $locked = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($this->leaseActive($locked)) {
                return null;
            }
            if ($force) {
                $locked->tracking_attempts = $this->nextRoundBase((int) $locked->tracking_attempts);
            } elseif ($locked->tracking_status === 'abandoned') {
                // 已转人工：只由履约证据变化（queue）或后台“重新上报运单”重新开始。
                return null;
            } elseif ($locked->tracking_status === 'failed' && $locked->tracking_next_attempt_at?->isFuture()) {
                // 仍在退避期内（重复投递的任务），不提前重试。
                return null;
            } elseif ($this->roundFailures((int) $locked->tracking_attempts) >= self::MAX_ATTEMPTS) {
                // 租约反复过期（进程 崩溃、超时）也计入次数，达到上限同样转人工。
                $locked->forceFill([
                    'tracking_status'          => 'abandoned',
                    'tracking_next_attempt_at' => null,
                    'tracking_last_error'      => mb_substr(
                        '连续 ' . self::MAX_ATTEMPTS . ' 次上报未完成，已停止自动重试'
                        . ($locked->tracking_last_error ? '：' . $locked->tracking_last_error : '。'),
                        0,
                        2000
                    ),
                ])->saveOrFail();

                return null;
            }

            $locked->forceFill([
                'tracking_status'          => 'sending',
                'tracking_next_attempt_at' => now()->addMinutes(self::LEASE_MINUTES),
                'tracking_attempts'        => ((int) $locked->tracking_attempts) + 1,
            ])->saveOrFail();

            return $locked;
        });
        if (! $claimed) {
            return true;
        }

        // 以领取时加锁读到的记录上报：领取前读到的 tracking_payload 可能已被上一个 进程 更新。
        $claimed->setRelation('account', $transaction->account);
        $attempt     = (int) $claimed->tracking_attempts;
        $fingerprint = $this->evidenceFingerprint($claimed);

        try {
            $result = $this->reportShipments($claimed, $force);
        } catch (\Throwable $exception) {
            // 逐运单处理之外的异常（代码或数据问题），无法定位到具体运单。
            report($exception);
            $result = [
                'reported'  => (array) ($claimed->tracking_payload ?? []),
                'errors'    => [mb_substr($exception->getMessage(), 0, 2000)],
                'retryable' => ! $this->isPermanent($exception),
            ];
        }

        return $this->writeBack($claimed, $attempt, $fingerprint, $result);
    }

    /**
     * 按租约写回结果：只有仍持有本次租约（tracking_attempts 未变）才写。上报期间 A 站又同步了运单时，
     * 写回后与最新证据比较，仍有差异就开始新一轮并立即再投，而不是被误记为已一致。
     *
     * @param array{reported: array, errors: array<int, string>, retryable: bool} $result
     */
    private function writeBack(PaypalBTransaction $claimed, int $attempt, string $fingerprint, array $result): bool
    {
        $outcome = DB::transaction(function () use ($claimed, $attempt, $fingerprint, $result): string {
            $locked = PaypalBTransaction::query()->lockForUpdate()->findOrFail($claimed->id);
            if ((int) $locked->tracking_attempts !== $attempt || $locked->tracking_status !== 'sending') {
                // 租约过期后已被新一轮接管，结果交给新的持有者。
                return 'lost';
            }

            $locked->tracking_payload = $result['reported'];
            $errors                   = $result['errors'];

            if ($this->evidenceFingerprint($locked) !== $fingerprint && $this->hasPendingChanges($locked)) {
                $this->startRound($locked, $errors === [] ? null : implode('；', $errors));

                return (string) $locked->status === 'completed' ? 'requeued' : 'pending';
            }

            if ($errors === []) {
                $locked->forceFill([
                    'tracking_status'          => 'sent',
                    'tracking_next_attempt_at' => null,
                    'tracking_synced_at'       => now(),
                    'tracking_last_error'      => null,
                ])->saveOrFail();

                return 'sent';
            }

            $message  = mb_substr(implode('；', $errors), 0, 2000);
            $failures = $this->roundFailures($attempt);
            if (! $result['retryable'] || $failures >= self::MAX_ATTEMPTS) {
                $locked->forceFill([
                    'tracking_status'          => 'abandoned',
                    'tracking_next_attempt_at' => null,
                    'tracking_last_error'      => $result['retryable']
                        ? mb_substr('连续 ' . $failures . ' 次上报失败，已停止自动重试：' . $message, 0, 2000)
                        : $message,
                ])->saveOrFail();

                return 'abandoned';
            }

            $locked->forceFill([
                'tracking_status'          => 'failed',
                'tracking_next_attempt_at' => now()->addSeconds($this->retryDelay($failures)),
                'tracking_last_error'      => $message,
            ])->saveOrFail();

            return 'failed';
        });

        if ($outcome === 'requeued') {
            PaypalBTrackingJob::dispatchAfterResponse((int) $claimed->id);
        }
        if ($outcome === 'abandoned') {
            Log::warning('PaypalB 运单上报已停止自动重试，需人工处理。', [
                'transaction_id' => $claimed->transaction_id,
                'order_number'   => $claimed->order_number,
                'errors'         => $result['errors'],
            ]);
        }

        return in_array($outcome, ['sent', 'lost', 'requeued', 'pending'], true);
    }

    /**
     * 与 PayPal 侧已无待处理差异时把状态落到终态：全部一致为 sent，只剩永久错误为 abandoned。
     * 仍有可重试差异或处于租约内的会话不动。调用方须已持有行锁。
     */
    private function settle(PaypalBTransaction $locked): void
    {
        if (! in_array((string) $locked->tracking_status, ['pending', 'failed', 'sending', 'abandoned'], true)
            || $this->leaseActive($locked)
            || $this->hasPendingChanges($locked)) {
            return;
        }

        $errors = $this->activePermanentErrors($locked, (array) ($locked->tracking_payload ?? []));
        if ($errors === []) {
            $locked->forceFill([
                'tracking_status'          => 'sent',
                'tracking_next_attempt_at' => null,
                'tracking_synced_at'       => now(),
                'tracking_last_error'      => null,
            ])->saveOrFail();

            return;
        }

        $message = mb_substr(implode('；', $errors), 0, 2000);
        if ($locked->tracking_status !== 'abandoned' || $locked->tracking_last_error !== $message) {
            $locked->forceFill([
                'tracking_status'          => 'abandoned',
                'tracking_next_attempt_at' => null,
                'tracking_last_error'      => $message,
            ])->saveOrFail();
        }
    }

    /**
     * 逐个运单对比 PayPal 侧已登记的状态：新增的创建 tracker，A 站已删除的置为 CANCELLED，承运商变化的先取消再新建。
     * 单个运单失败只记在该运单上，不影响其他运单。
     *
     * @return array{reported: array<string, array<string, mixed>>, errors: array<int, string>, retryable: bool}
     */
    private function reportShipments(PaypalBTransaction $transaction, bool $force): array
    {
        $reported = (array) ($transaction->tracking_payload ?? []);
        $current  = $this->currentShipments($transaction);
        // 本次遇到的可重试错误；永久错误记在各运单上，最后统一汇总。
        $transient = [];

        // 超过 PayPal 长度上限的运单号：无法上报，逐个记录原因供后台查看。
        foreach ($this->oversizedTrackingNumbers($transaction) as $trackingNo => $shipment) {
            if (($reported[$trackingNo]['status'] ?? '') === 'SKIPPED') {
                continue;
            }
            Log::warning('PaypalB 运单号过长，跳过上报 PayPal。', [
                'transaction_id' => $transaction->transaction_id,
                'order_number'   => $transaction->order_number,
                'tracking_no'    => $trackingNo,
                'length'         => mb_strlen($trackingNo),
            ]);
            $reported[$trackingNo] = [
                'status'        => 'SKIPPED',
                'carrier_code'  => $shipment['carrier_code'],
                'carrier_name'  => $shipment['carrier_name'],
                'error'         => '运单号超过 PayPal 上限 ' . self::MAX_TRACKING_NUMBER_LENGTH . ' 个字符，无法上报。',
                'permanent'     => true,
                'failed_target' => self::TARGET_OVERSIZED,
                'reported_at'   => now()->toIso8601String(),
            ];
        }

        $account = $transaction->account;
        if (! $account || ! $account->usesApi()) {
            // 账号类型不会自己变，重试没有意义：直接作为永久错误转人工。
            return [
                'reported'  => $reported,
                'errors'    => array_merge(
                    ['该会话的收款账号不是自动 API 账号，无法上报运单。'],
                    $this->activePermanentErrors($transaction, $reported)
                ),
                'retryable' => false,
            ];
        }

        $client    = $this->apiClient($account);
        $orderId   = (string) $transaction->paypal_order_id;
        $captureId = (string) $transaction->paypal_capture_id;
        $notify    = (bool) ($this->configuration['tracking_notify_payer'] ?? false);

        // 已上报但 A 站已删除或换了承运商的运单：取消旧 tracker。
        foreach ($reported as $trackingNo => $entry) {
            $trackingNo = (string) $trackingNo;
            if (! is_array($entry) || ($entry['status'] ?? '') !== 'SHIPPED') {
                continue;
            }
            $desired = isset($current[$trackingNo]) ? self::targetSignature($current[$trackingNo]) : self::TARGET_REMOVED;
            if ($desired === self::targetSignature($entry)) {
                continue;
            }
            if (! $force && $this->settledFailure($entry, $desired, false)) {
                continue;
            }

            try {
                $client->cancelTracker($orderId, (string) $entry['tracker_id']);
                $reported[$trackingNo] = ['status' => 'CANCELLED', 'reported_at' => now()->toIso8601String()]
                    + self::withoutError($entry);
            } catch (\Throwable $exception) {
                report($exception);
                $permanent             = $this->isPermanent($exception);
                $reported[$trackingNo] = self::withError($entry, '取消旧运单失败，' . $exception->getMessage(), $permanent, $desired);
                if (! $permanent) {
                    $transient[] = $trackingNo . '：取消旧运单失败，' . $exception->getMessage();
                }
            }
        }

        foreach ($current as $trackingNo => $shipment) {
            $trackingNo = (string) $trackingNo;
            $existing   = is_array($reported[$trackingNo] ?? null) ? $reported[$trackingNo] : null;
            $desired    = self::targetSignature($shipment);
            if ($existing && ($existing['status'] ?? '') === 'SHIPPED') {
                if (self::targetSignature($existing) === $desired) {
                    // 已登记且一致：清掉之前取消失败等遗留错误。
                    $reported[$trackingNo] = self::withoutError($existing);
                }

                // 承运商不一致说明上面取消旧 tracker 失败，等下一次先取消。
                continue;
            }
            if ($existing && ! $force && $this->settledFailure($existing, $desired, false)) {
                continue;
            }

            try {
                $trackerId = $this->registerTracker($client, $orderId, $captureId, $trackingNo, $shipment, $existing, $notify);
            } catch (\Throwable $exception) {
                report($exception);
                $permanent             = $this->isPermanent($exception);
                $reported[$trackingNo] = self::withError(
                    ['status' => 'FAILED'] + self::shipmentFields($shipment) + (array) $existing,
                    $exception->getMessage(),
                    $permanent,
                    $desired
                );
                if (! $permanent) {
                    $transient[] = $trackingNo . '：' . $exception->getMessage();
                }

                continue;
            }

            if (! $shipment['recognized']) {
                Log::warning('PaypalB 运单以 OTHER 承运商上报，PayPal 不会自动查询轨迹。', [
                    'transaction_id' => $transaction->transaction_id,
                    'order_number'   => $transaction->order_number,
                    'carrier_code'   => $shipment['carrier_code'],
                    'carrier_name'   => $shipment['carrier_name'],
                ]);
            }
            $reported[$trackingNo] = ['tracker_id' => $trackerId] + self::shipmentFields($shipment) + [
                'status'      => 'SHIPPED',
                'reported_at' => now()->toIso8601String(),
            ];
        }

        return [
            'reported'  => $reported,
            'errors'    => array_merge($transient, $this->activePermanentErrors($transaction, $reported)),
            'retryable' => $transient !== [],
        ];
    }

    /**
     * 登记运单。更换承运商时同一运单号的 tracker id（{capture_id}-{tracking_number}）不变，旧 tracker 已被置为
     * CANCELLED；PayPal 若以 422 拒绝重新登记，需要把原 tracker 恢复为 SHIPPED 并改承运商，而 PaypalBApiClient
     * 只提供取消（PATCH /status=CANCELLED），因此记为带明确原因的永久错误，由运营在 PayPal 后台处理，不再无限重试。
     */
    private function registerTracker(
        PaypalBApiClient $client,
        string $orderId,
        string $captureId,
        string $trackingNo,
        array $shipment,
        ?array $existing,
        bool $notify
    ): string {
        try {
            return $client->addTracker($orderId, $captureId, $trackingNo, $shipment['carrier'], $shipment['carrier_name_other'], $notify);
        } catch (PaypalBApiException $exception) {
            if ($exception->httpStatus !== 422 || trim((string) ($existing['tracker_id'] ?? '')) === '') {
                throw $exception;
            }

            throw new PaypalBApiException(
                '该运单号此前已在 PayPal 登记并取消，PayPal 拒绝按新承运商重新登记（' . $exception->getMessage() . '）。请在 PayPal 后台手工更新该运单。',
                $exception->httpStatus,
                $exception->paypalName,
                $exception->issues,
                false,
                $exception
            );
        }
    }

    /**
     * 当前履约证据里可上报的运单，按运单号去重并换算承运商；超长运单号见 oversizedTrackingNumbers()。
     *
     * @return array<string, array{carrier: string, carrier_name_other: string|null, recognized: bool, carrier_code: string, carrier_name: string}>
     */
    public function currentShipments(PaypalBTransaction $transaction): array
    {
        $shipments = [];
        foreach ($this->evidenceShipments($transaction) as $trackingNo => $shipment) {
            if (mb_strlen((string) $trackingNo) > self::MAX_TRACKING_NUMBER_LENGTH) {
                continue;
            }
            $shipments[$trackingNo] = PaypalBCarrierResolver::resolve($shipment['carrier_code'], $shipment['carrier_name']) + $shipment;
        }

        return $shipments;
    }

    /**
     * 超过 PayPal 运单号长度上限、无法上报的运单。
     *
     * @return array<string, array{carrier_code: string, carrier_name: string}>
     */
    public function oversizedTrackingNumbers(PaypalBTransaction $transaction): array
    {
        return array_filter(
            $this->evidenceShipments($transaction),
            static fn ($trackingNo): bool => mb_strlen((string) $trackingNo) > self::MAX_TRACKING_NUMBER_LENGTH,
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * 履约证据与 PayPal 侧登记是否有待处理的差异：有未上报的运单、或已上报的运单被删除 / 换承运商。
     * 同一目标已记为永久错误的运单不算（否则无限重试）；$onlyNew 时同一目标的可重试失败也不算，
     * 用于判断已转人工的会话是否出现了新差异。超长运单号尚未记录原因时算差异。
     */
    public function hasPendingChanges(PaypalBTransaction $transaction, bool $onlyNew = false): bool
    {
        $current  = $this->currentShipments($transaction);
        $reported = (array) ($transaction->tracking_payload ?? []);
        foreach ($current as $trackingNo => $shipment) {
            $entry = $reported[$trackingNo] ?? null;
            if (! is_array($entry)) {
                return true;
            }
            $desired = self::targetSignature($shipment);
            if (($entry['status'] ?? '') === 'SHIPPED' && self::targetSignature($entry) === $desired) {
                continue;
            }
            if (! $this->settledFailure($entry, $desired, $onlyNew)) {
                return true;
            }
        }
        foreach ($reported as $trackingNo => $entry) {
            if (is_array($entry) && ($entry['status'] ?? '') === 'SHIPPED' && ! isset($current[(string) $trackingNo])
                && ! $this->settledFailure($entry, self::TARGET_REMOVED, $onlyNew)) {
                return true;
            }
        }
        foreach (array_keys($this->oversizedTrackingNumbers($transaction)) as $trackingNo) {
            if (($reported[$trackingNo]['status'] ?? '') !== 'SKIPPED') {
                return true;
            }
        }

        return false;
    }

    public function enabled(): bool
    {
        return (bool) ($this->configuration['tracking_report_enabled'] ?? false);
    }

    /**
     * 只处理跨站会话：本站自营订单的发货在本站，运单上报另有入口时再扩展。
     */
    private function applicable(PaypalBTransaction $transaction): bool
    {
        return ! $transaction->isLocal() && (string) $transaction->paypal_order_id !== '';
    }

    /**
     * 开始新的一轮上报：轮内失败次数从零计，立即可被领取。调用方须已持有行锁。
     */
    private function startRound(PaypalBTransaction $locked, ?string $lastError = null): void
    {
        $locked->forceFill([
            'tracking_status'          => 'pending',
            'tracking_next_attempt_at' => now(),
            'tracking_attempts'        => $this->nextRoundBase((int) $locked->tracking_attempts),
            'tracking_last_error'      => $lastError === null ? null : mb_substr($lastError, 0, 2000),
        ])->saveOrFail();
    }

    private function nextRoundBase(int $attempts): int
    {
        return (intdiv(max(0, $attempts), self::ROUND_STRIDE) + 1) * self::ROUND_STRIDE;
    }

    private function roundFailures(int $attempts): int
    {
        return max(0, $attempts) % self::ROUND_STRIDE;
    }

    private function leaseActive(PaypalBTransaction $transaction): bool
    {
        return $transaction->tracking_status === 'sending' && (bool) $transaction->tracking_next_attempt_at?->isFuture();
    }

    /**
     * @return array<string, array{carrier_code: string, carrier_name: string}>
     */
    private function evidenceShipments(PaypalBTransaction $transaction): array
    {
        $shipments = [];
        foreach ((array) data_get($transaction->fulfillment_evidence, 'shipments', []) as $shipment) {
            $trackingNo = trim((string) ($shipment['tracking_no'] ?? ''));
            if ($trackingNo === '') {
                continue;
            }
            $shipments[$trackingNo] = [
                'carrier_code' => (string) ($shipment['carrier_code'] ?? ''),
                'carrier_name' => (string) ($shipment['carrier_name'] ?? ''),
            ];
        }

        return $shipments;
    }

    private function evidenceFingerprint(PaypalBTransaction $transaction): string
    {
        return hash('sha256', (string) json_encode($this->evidenceShipments($transaction)));
    }

    /**
     * 该运单针对同一目标已经失败过：永久错误总算已结清；$includeRetryable 时可重试错误也算。
     */
    private function settledFailure(array $entry, string $desired, bool $includeRetryable): bool
    {
        if (! isset($entry['error']) || (string) ($entry['failed_target'] ?? '') !== $desired) {
            return false;
        }

        return ! empty($entry['permanent']) || $includeRetryable;
    }

    /**
     * 仍然有效的永久错误：只统计失败目标与当前履约证据一致的运单；A 站换了承运商或删掉运单后，旧错误自然失效。
     *
     * @return array<int, string>
     */
    private function activePermanentErrors(PaypalBTransaction $transaction, array $reported): array
    {
        $current   = $this->currentShipments($transaction);
        $oversized = $this->oversizedTrackingNumbers($transaction);
        $errors    = [];
        foreach ($reported as $trackingNo => $entry) {
            $trackingNo = (string) $trackingNo;
            if (! is_array($entry)) {
                continue;
            }
            $desired = match (true) {
                isset($current[$trackingNo])   => self::targetSignature($current[$trackingNo]),
                isset($oversized[$trackingNo]) => self::TARGET_OVERSIZED,
                default                        => self::TARGET_REMOVED,
            };
            if (! empty($entry['permanent']) && $this->settledFailure($entry, $desired, false)) {
                $errors[] = $trackingNo . '：' . $entry['error'];
            }
        }

        return $errors;
    }

    private function isPermanent(\Throwable $exception): bool
    {
        // 只有 PayPal 明确的业务拒绝和配置错误才是永久错误；网络、5xx、限流与未知异常都可重试。
        return $exception instanceof PaypalBApiException && ! $exception->retryable;
    }

    private static function targetSignature(array $shipment): string
    {
        return (string) ($shipment['carrier'] ?? '') . "\n" . (string) ($shipment['carrier_name_other'] ?? '');
    }

    private static function shipmentFields(array $shipment): array
    {
        return [
            'carrier'            => $shipment['carrier'],
            'carrier_name_other' => $shipment['carrier_name_other'],
            'carrier_code'       => $shipment['carrier_code'],
            'carrier_name'       => $shipment['carrier_name'],
        ];
    }

    private static function withError(array $entry, string $message, bool $permanent, string $target): array
    {
        return array_merge($entry, [
            'error'         => mb_substr($message, 0, 500),
            'permanent'     => $permanent,
            'failed_target' => $target,
            'failed_at'     => now()->toIso8601String(),
        ]);
    }

    private static function withoutError(array $entry): array
    {
        unset($entry['error'], $entry['permanent'], $entry['failed_target'], $entry['failed_at']);

        return $entry;
    }

    /**
     * 单独成方法便于测试替换 PayPal 客户端。
     */
    protected function apiClient(PaypalBAccount $account): PaypalBApiClient
    {
        return new PaypalBApiClient(
            $account,
            (bool) ($this->configuration['sandbox_mode'] ?? true),
            (int) ($this->configuration['request_timeout_seconds'] ?? 10)
        );
    }

    private function retryDelay(int $failures): int
    {
        return match (true) {
            $failures <= 1 => 60,
            $failures <= 2 => 300,
            $failures <= 3 => 900,
            $failures <= 4 => 3600,
            default        => 7200,
        };
    }
}
