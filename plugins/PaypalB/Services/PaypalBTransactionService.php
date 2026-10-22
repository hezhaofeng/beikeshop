<?php

namespace Plugin\PaypalB\Services;

use Beike\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugin\PaypalB\Models\PaypalBAccount;
use Plugin\PaypalB\Models\PaypalBTransaction;

class PaypalBTransactionService
{
    private const ITEM_SOURCE_A_ORDER = 'a_order';

    private const ITEM_SOURCE_A_MAPPING = 'mapped';

    /**
     * 拒绝内嵌时返回给 A 站的稳定错误码。A 站按字面匹配决定是否降级，文案不可随意改动。
     */
    public const EMBEDDED_NOT_ALLOWED = 'embedded_not_allowed';

    private const RISK_RATE_LIMIT_WINDOW_MINUTES = 15;

    private const RISK_RATE_LIMIT_MAX_ATTEMPTS = 5;

    public function __construct(private readonly array $configuration)
    {
    }

    /**
     * B 站在企业和个人卖家 API 账号池中轮询；创建结果未知时保留原账号和幂等请求号。
     */
    public function create(array $payload): PaypalBTransaction
    {
        $data = $this->validateCreatePayload($payload);

        // A 站可能因重复点击、多个标签页或网络重试并发提交同一订单；锁必须覆盖整个账号选择和 PayPal 创建过程。
        try {
            return Cache::store('database')->lock(
                'paypal_b:create:order:' . hash('sha256', $data['order_number']),
                600
            )->block(15, fn (): PaypalBTransaction => $this->createUnlocked($data));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'payment' => '相同订单的支付会话正在创建，请稍后重试。',
            ]);
        }
    }

    private function assertBridgeEnabled(): void
    {
        if (empty($this->configuration['bridge_enabled'])) {
            throw ValidationException::withMessages([
                'bridge' => 'PaypalB 当前未启用 A/B 跨站支付。',
            ]);
        }
    }

    private function createUnlocked(array $data): PaypalBTransaction
    {
        $transaction = null;
        $existing    = PaypalBTransaction::query()->where('reference', $data['reference'])->first();
        if ($existing) {
            $this->assertSameRequest($existing, $data);

            if ($existing->status === 'completed') {
                return $existing;
            }
            if ($existing->mode !== 'automatic' || $existing->status === 'awaiting_manual_confirmation') {
                $this->invalidateLegacySession($existing, 'manual_collection_disabled');

                throw ValidationException::withMessages([
                    'payment' => '该支付会话使用了已停用的人工收款模式，请重新发起 PayPal 付款。',
                ]);
            }
            if ($existing->status === 'pending') {
                $this->assertPayPalItemSnapshot($existing);

                return $existing;
            }
            if ($existing->paypal_order_id || $existing->paypal_capture_id) {
                // 任何已有远端 ID 的会话都只能继续原会话，不能创建第二个 PayPal Order。
                $this->assertPayPalItemSnapshot($existing);

                return $existing;
            }

            if (($existing->status === 'creating' && $existing->account_id)
                || $existing->status        === 'reconciling'
                || $existing->failure_class === 'provider_unknown') {
                $account = $existing->account;
                if (! $account || ! $account->usesApi()) {
                    if ($account && ! $account->usesApi()) {
                        $this->invalidateLegacySession($existing, 'manual_collection_disabled');

                        throw ValidationException::withMessages([
                            'payment' => '原支付会话使用了已停用的人工收款账号，请重新发起 PayPal 付款。',
                        ]);
                    }

                    throw ValidationException::withMessages([
                        'payment' => trans('PaypalB::common.session_reconciling'),
                    ]);
                }

                return $this->retryUnknownCreate($existing, $account);
            }

            if ($existing->status               === 'failed'
                && $existing->failure_retryable === false
                && $existing->failure_class !== 'configuration') {
                throw ValidationException::withMessages([
                    'payment' => (string) (data_get($existing->provider_payload, 'error') ?: 'PayPal 拒绝了本次订单，不能自动更换账号重试。'),
                ]);
            }

            // A 站可能在 B 站暂时不可用时重试同一引用；失败会话可以重新轮询账号，已完成会话仍保持幂等。
            $existing->forceFill([
                'account_id'               => null,
                'account_fingerprint'      => null,
                'status'                   => 'creating',
                'paypal_order_id'          => null,
                'paypal_capture_id'        => null,
                'approval_url'             => null,
                'provider_status'          => null,
                'provider_payload'         => null,
                'failure_retryable'        => null,
                'failure_class'            => null,
                'callback_last_error'      => null,
                'callback_status'          => 'idle',
                'callback_next_attempt_at' => null,
                'callback_last_sent_at'    => null,
                'paid_at'                  => null,
                'expires_at'               => $data['expires_at'],
                'callback_url'             => $data['callback_url'],
                'buyer_locale'             => $data['buyer_locale'],
                'bridge_request'           => $this->bridgeRequestData($data),
                'order_snapshot_hash'      => $data['order_snapshot_hash'],
                'buyer_snapshot'           => $data['buyer'],
                'order_items'              => $data['order_items'],
                'paypal_order_items'       => $data['paypal_order_items'],
                'paypal_item_source'       => $data['paypal_item_source'],
                'order_totals'             => $data['order_totals'],
                'shipping_address'         => $data['shipping_address'],
                'fulfillment_evidence'     => $data['fulfillment'],
                'fulfillment_updated_at'   => now(),
                'buyer_email_hash'         => $data['buyer_email_hash'],
                'buyer_ip_hash'            => $data['buyer_ip_hash'],
                'risk_fingerprint'         => $data['risk_fingerprint'],
                'risk_flags'               => [],
            ])->saveOrFail();
            $transaction = $existing;
        }

        if ($transaction === null) {
            $this->assertRiskRateLimit($data);
        }

        $otherAttempts = PaypalBTransaction::query()
            ->where('order_number', $data['order_number'])
            ->where('reference', '<>', $data['reference'])
            ->orderByDesc('id')
            ->get();
        foreach ($otherAttempts as $otherAttempt) {
            if (($otherAttempt->paypal_order_id || $otherAttempt->paypal_capture_id)
                && ! $this->isTerminalUncapturedAttempt($otherAttempt)) {
                throw ValidationException::withMessages([
                    'payment' => '该订单已有 PayPal 远端支付会话，请继续原会话或由后台完成核对。',
                ]);
            }
            if ($otherAttempt->status === 'reconciling' || $otherAttempt->failure_class === 'provider_unknown') {
                throw ValidationException::withMessages([
                    'payment' => '该订单存在尚未确认结果的 PayPal 创建请求，请勿重复付款。',
                ]);
            }
            if (in_array($otherAttempt->status, ['creating', 'pending', 'awaiting_manual_confirmation', 'completed'], true)) {
                throw ValidationException::withMessages(['payment' => '该订单已经存在其他支付会话，不能重复创建 PayPal 订单。']);
            }
            if ($otherAttempt->status               === 'failed'
                && $otherAttempt->failure_retryable === false
                && $otherAttempt->failure_class !== 'configuration') {
                throw ValidationException::withMessages([
                    'payment' => '该订单此前已被 PayPal 或账号配置拒绝，不能通过新引用自动更换收款账号。',
                ]);
            }
        }

        $account = $this->selectAccount($data['currency'], $data['allowed_methods']);

        $transaction ??= PaypalBTransaction::query()->create([
            'transaction_id'         => 'PB-' . Str::upper(Str::random(28)),
            'public_token'           => Str::random(64),
            'account_id'             => $account->id,
            'account_fingerprint'    => $this->accountFingerprint($account),
            'reference'              => $data['reference'],
            'order_number'           => $data['order_number'],
            'amount'                 => $data['amount'],
            'currency'               => $data['currency'],
            'buyer_locale'           => $data['buyer_locale'],
            'presentation'           => $data['presentation'],
            'callback_url'           => $data['callback_url'],
            'mode'                   => 'automatic',
            'status'                 => 'creating',
            'callback_status'        => 'idle',
            'bridge_request'         => $this->bridgeRequestData($data),
            'order_snapshot_hash'    => $data['order_snapshot_hash'],
            'buyer_snapshot'         => $data['buyer'],
            'order_items'            => $data['order_items'],
            'paypal_order_items'     => $data['paypal_order_items'],
            'paypal_item_source'     => $data['paypal_item_source'],
            'order_totals'           => $data['order_totals'],
            'shipping_address'       => $data['shipping_address'],
            'fulfillment_evidence'   => $data['fulfillment'],
            'fulfillment_updated_at' => now(),
            'expires_at'             => $data['expires_at'],
            'buyer_email_hash'       => $data['buyer_email_hash'],
            'buyer_ip_hash'          => $data['buyer_ip_hash'],
            'risk_fingerprint'       => $data['risk_fingerprint'],
            'risk_flags'             => [],
        ]);

        $transaction->forceFill([
            'account_id'          => $account->id,
            'account_fingerprint' => $this->accountFingerprint($account),
            'mode'                => 'automatic',
        ])->saveOrFail();

        return $this->submitPaypalOrder($transaction, $account, 1);
    }

    /**
     * B 站自营订单建单。不做签名、回调地址和重放校验（订单本来就在本站），
     * 但金额、商品与费用的一致性校验必须保留，防止本地数据错误传到 PayPal。
     * 同一订单的串行化与会话复用由 PaypalBLocalPaymentService 在调用前完成，
     * 这里只保证不与该订单已有的会话冲突。
     */
    public function createLocal(Order $order, array $data): PaypalBTransaction
    {
        $currency = strtoupper((string) $data['currency']);
        $amount   = PaypalBMoney::normalize($data['amount'], $currency);
        $items    = $this->validateOrderItems($data['order_items'] ?? null);
        $totals   = $this->validateOrderTotals($data['order_totals'] ?? null);
        $shipping = $this->validateShippingAddress($data['shipping_address'] ?? null);
        $buyer    = $this->validateBuyer($data['buyer'] ?? null);
        $this->assertOrderAmounts($items, $totals, $amount, $currency);
        $this->assertNoConflictingLocalAttempts($order);

        $enabledMethods = $this->locallyEnabledMethods();
        if ($enabledMethods === []) {
            throw ValidationException::withMessages([
                'payment' => trans('PaypalB::common.local_no_method'),
            ]);
        }

        // 本地支付页只渲染钱包按钮，信用卡走托管页且是可选能力：账号只需具备主付款方式，
        // 否则一开启信用卡开关，所有仅支持钱包的账号都会被排除。
        $primaryMethod  = in_array('wallet', $enabledMethods, true) ? 'wallet' : 'card';
        $account        = $this->selectAccount($currency, [$primaryMethod]);
        $allowedMethods = array_values(array_filter(
            $enabledMethods,
            static fn (string $method): bool => $account->supportsPaymentMethods([$method])
        ));
        $buyerEmailHash = $this->auditHash($buyer['email']);
        $buyerIpHash    = $this->auditHash($buyer['ip']);
        $transaction    = PaypalBTransaction::query()->create([
            'transaction_id'         => 'PB-' . Str::upper(Str::random(28)),
            'public_token'           => Str::random(64),
            'account_id'             => $account->id,
            'account_fingerprint'    => $this->accountFingerprint($account),
            'reference'              => (string) $data['reference'],
            'order_number'           => (string) $data['order_number'],
            'source'                 => PaypalBTransaction::SOURCE_LOCAL,
            // 自营订单在此就已确定，直接锁定订单 ID：
            // 入账时若只按订单号反查，可能命中同号的跨站镜像订单，
            // 导致真实自营订单永远停在未支付。
            'shop_order_id'          => $order->id,
            'amount'                 => $amount,
            'currency'               => $currency,
            'buyer_locale'           => $this->validateLocale($data['buyer_locale'] ?? null),
            // 自营订单没有 A 站可回调，空串表示无回调目标。
            'callback_url'           => '',
            'mode'                   => 'automatic',
            'status'                 => 'creating',
            'callback_status'        => 'idle',
            'bridge_request'         => [
                'order_number'        => (string) $data['order_number'],
                'amount'              => $amount,
                'currency'            => $currency,
                'allowed_methods'     => $allowedMethods,
                'source'              => PaypalBTransaction::SOURCE_LOCAL,
                'paypal_payload_mode' => $this->payloadMode(),
            ],
            'order_snapshot_hash'    => hash('sha256', BridgeSignature::canonicalJson([
                'order_items'      => $items,
                'order_totals'     => $totals,
                'shipping_address' => $shipping,
            ])),
            'buyer_snapshot'         => $buyer,
            'order_items'            => $items,
            // 自营订单展示和收款用的是同一批真实商品，无需映射。
            'paypal_order_items'     => $items,
            'paypal_item_source'     => self::ITEM_SOURCE_A_ORDER,
            'order_totals'           => $totals,
            'shipping_address'       => $shipping,
            'fulfillment_evidence'   => $this->validateFulfillment([]),
            'fulfillment_updated_at' => now(),
            'expires_at'             => $data['expires_at'],
            'buyer_email_hash'       => $buyerEmailHash,
            'buyer_ip_hash'          => $buyerIpHash,
            'risk_fingerprint'       => $this->riskFingerprint($buyer, $buyerEmailHash, $buyerIpHash),
            'risk_flags'             => [],
        ]);

        return $this->submitPaypalOrder($transaction, $account, 1);
    }

    private function retryUnknownCreate(PaypalBTransaction $transaction, PaypalBAccount $account): PaypalBTransaction
    {
        if ($transaction->expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'payment' => '该 PayPal 创建请求已超过支付会话有效期，请先在 B 站后台核对远端订单。',
            ]);
        }
        $this->assertAccountApplication($transaction, $account);

        return $this->submitPaypalOrder($transaction, $account, 2);
    }

    /**
     * 向 PayPal 提交创建订单请求并登记结果。三条创建路径（跨站首次、本地首次、同账号重试）
     * 对成功、可重试失败、明确拒绝和响应异常的处理必须完全一致，否则轮询冷却和对账状态会漂移。
     *
     * 可重试失败与响应异常都不能证明远端未创建成功，只进入同账号核对流程，不切换账号。
     */
    private function submitPaypalOrder(PaypalBTransaction $transaction, PaypalBAccount $account, int $attempt): PaypalBTransaction
    {
        $selector = $this->accountSelector();

        try {
            $provider = $this->createPaypalOrder($transaction, $account);
            $this->storeCreatedOrder($transaction, $provider);
            $selector->markSuccess($account);

            // 配置为建会话时创建镜像订单：A 站下单后立即出现在 B 站订单列表（待支付）。失败只记日志。
            app(PaypalBShopOrderService::class)->createPendingFromTransaction($transaction->fresh());

            return $transaction->fresh();
        } catch (PaypalBApiException $exception) {
            if ($exception->retryable) {
                $selector->markFailure($account, $exception->getMessage());
                $this->markUnknownCreate($transaction, $exception, $attempt);

                return $transaction->fresh();
            }

            $selector->recordRejectedRequest($account, $exception->getMessage());
            $this->markRejectedCreate($transaction, $exception, $attempt);

            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            $selector->markFailure($account, $exception->getMessage());
            $this->markUnknownCreate($transaction, $exception, $attempt);

            return $transaction->fresh();
        }
    }

    /**
     * 自营会话的创建结果未知（连接故障、5xx、响应异常）或进程中断在 creating 时，
     * 沿用同一账号和同一个 PayPal-Request-Id 重试；PayPal 会幂等返回原订单，不会产生第二个远端订单。
     */
    public function retryLocal(PaypalBTransaction $transaction): PaypalBTransaction
    {
        if (! $transaction->isLocal() || $transaction->paypal_order_id) {
            throw new \LogicException('只有尚未取得 PayPal 订单号的自营会话才能重试创建。');
        }

        $account = $transaction->account;
        if (! $account || ! $account->usesApi()) {
            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.session_reconciling')]);
        }

        return $this->retryUnknownCreate($transaction, $account);
    }

    /**
     * 与跨站会话相同的约束：同一订单不能并行存在远端会话；被 PayPal 或账号配置拒绝后，
     * 也不能靠新会话把订单轮询到另一个账号。按 shop_order_id 而不是订单号定位，
     * 避免与同号的跨站镜像订单混淆。已过期且无捕获的会话不构成冲突。
     */
    private function assertNoConflictingLocalAttempts(Order $order): void
    {
        $attempts = PaypalBTransaction::query()
            ->where('source', PaypalBTransaction::SOURCE_LOCAL)
            ->where('shop_order_id', $order->id)
            ->orderByDesc('id')
            ->get();
        foreach ($attempts as $attempt) {
            if (($attempt->paypal_order_id || $attempt->paypal_capture_id)
                && ! $this->isTerminalUncapturedAttempt($attempt)) {
                throw ValidationException::withMessages([
                    'payment' => '该订单已有 PayPal 远端支付会话，请继续原会话或由后台完成核对。',
                ]);
            }
            if ($attempt->status === 'reconciling') {
                throw ValidationException::withMessages([
                    'payment' => trans('PaypalB::common.session_reconciling'),
                ]);
            }
            if (in_array($attempt->status, ['creating', 'pending', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'payment' => '该订单已经存在其他支付会话，不能重复创建 PayPal 订单。',
                ]);
            }
            if ($attempt->status               === 'failed'
                && $attempt->failure_retryable === false
                && $attempt->failure_class !== 'configuration') {
                throw ValidationException::withMessages([
                    'payment' => '该订单此前已被 PayPal 或账号配置拒绝，不能自动更换收款账号重试。',
                ]);
            }
        }
    }

    private function accountSelector(): PaypalBAccountSelector
    {
        return new PaypalBAccountSelector((int) ($this->configuration['account_cooldown_minutes'] ?? 10));
    }

    /**
     * 账号池为空、币种不支持或能力不匹配都属于买家可见的业务拒绝，不是 500。
     */
    private function selectAccount(string $currency, array $allowedMethods): PaypalBAccount
    {
        try {
            return $this->accountSelector()->select($currency, $allowedMethods);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }
    }

    /**
     * B 站配置允许的付款方式；跨站请求还会再与 A 站申请的方式取交集。
     */
    private function locallyEnabledMethods(): array
    {
        $methods = [];
        if (! empty($this->configuration['wallet_enabled'])) {
            $methods[] = 'wallet';
        }
        if (! empty($this->configuration['card_enabled'])) {
            $methods[] = 'card';
        }

        return $methods;
    }

    private function createPaypalOrder(PaypalBTransaction $transaction, PaypalBAccount $account): array
    {
        $payload = PaypalBOrderPayload::build(
            $transaction,
            $this->returnUrl($transaction),
            $this->cancelUrl($transaction),
            (string) ($this->configuration['merchant_display_name'] ?? ''),
            $this->paypalOrderItems($transaction),
            ['mode' => PaypalBOrderPayload::modeFor($transaction, $this->configuration)]
        );

        return $this->apiClient($account)->createOrder($transaction, $payload);
    }

    /**
     * 当前配置决定新会话向 PayPal 提交的请求体格式；会话一旦创建就把格式记在 bridge_request 上。
     */
    private function payloadMode(): string
    {
        return PaypalBOrderPayload::normalizeMode($this->configuration['paypal_payload_mode'] ?? null);
    }

    private function apiClient(PaypalBAccount $account): PaypalBApiClient
    {
        // 配置缺失时宁可落到沙盒，也不能误连正式环境。
        return new PaypalBApiClient(
            $account,
            (bool) ($this->configuration['sandbox_mode'] ?? true),
            (int) ($this->configuration['request_timeout_seconds'] ?? 10)
        );
    }

    private function storeCreatedOrder(PaypalBTransaction $transaction, array $provider): void
    {
        $approvalUrl = $this->approvalUrl($provider);
        if ($approvalUrl === '' || ! $this->isSecureUrl($approvalUrl)) {
            throw new \RuntimeException('PayPal 未返回买家授权地址。');
        }

        $providerOrderId = trim((string) ($provider['id'] ?? ''));
        if ($providerOrderId === '') {
            throw new \RuntimeException('PayPal 未返回订单号。');
        }

        $transaction->forceFill([
            'paypal_order_id'     => $providerOrderId,
            'approval_url'        => $approvalUrl,
            'provider_status'     => (string) ($provider['status'] ?? 'CREATED'),
            'provider_payload'    => $provider,
            'status'              => 'pending',
            'failure_retryable'   => null,
            'failure_class'       => null,
            'callback_last_error' => null,
        ])->saveOrFail();
    }

    private function markUnknownCreate(PaypalBTransaction $transaction, \Throwable $exception, int $attempt): void
    {
        $transaction->forceFill([
            'status'                    => 'reconciling',
            'provider_payload'          => $this->errorPayload($exception, $attempt, true),
            'failure_retryable'         => false,
            'failure_class'             => 'provider_unknown',
            'payment_exception_status'  => 'create_unknown',
            'payment_exception_payload' => $this->exceptionAuditPayload($exception),
            'callback_last_error'       => mb_substr($exception->getMessage(), 0, 2000),
        ])->saveOrFail();
    }

    private function markRejectedCreate(PaypalBTransaction $transaction, PaypalBApiException $exception, int $attempt): void
    {
        $transaction->forceFill([
            'status'                    => 'failed',
            'provider_payload'          => $this->errorPayload($exception, $attempt, false),
            'failure_retryable'         => false,
            'failure_class'             => $exception->failureClass(),
            'payment_exception_status'  => 'create_rejected',
            'payment_exception_payload' => $this->exceptionAuditPayload($exception),
            'callback_last_error'       => mb_substr($exception->getMessage(), 0, 2000),
        ])->saveOrFail();
    }

    private function errorPayload(\Throwable $exception, int $attempt, bool $unknown): array
    {
        return [
            'error'          => $exception->getMessage(),
            'attempt'        => $attempt,
            'http_status'    => $exception instanceof PaypalBApiException ? $exception->httpStatus : null,
            'paypal_name'    => $exception instanceof PaypalBApiException ? $exception->paypalName : '',
            'issues'         => $exception instanceof PaypalBApiException ? $exception->issues : [],
            'retryable'      => $exception instanceof PaypalBApiException && $exception->retryable,
            'result_unknown' => $unknown,
        ];
    }

    private function accountFingerprint(PaypalBAccount $account): string
    {
        return hash('sha256', implode('|', [
            (string) $account->id,
            (string) $account->client_id,
            ! empty($this->configuration['sandbox_mode']) ? 'sandbox' : 'live',
        ]));
    }

    private function assertAccountApplication(PaypalBTransaction $transaction, PaypalBAccount $account): void
    {
        if (! $transaction->account_fingerprint
            || ! hash_equals((string) $transaction->account_fingerprint, $this->accountFingerprint($account))) {
            throw ValidationException::withMessages([
                'payment' => 'PayPal 收款应用已发生变化，请由 B 站后台核对原支付请求后再继续。',
            ]);
        }
    }

    /**
     * 在 B 站再次按最小货币单位核对 A 站快照，避免客户端篡改行金额或费用明细。
     */
    private function assertOrderAmounts(array $items, array $totals, string $amount, string $currency): void
    {
        $itemTotal = 0;
        foreach ($items as $index => $item) {
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    "order_items.{$index}.quantity" => '商品数量必须大于零。',
                ]);
            }

            $expectedLineTotal = PaypalBMoney::multiply(
                $item['unit_price'] ?? '',
                $quantity,
                $currency,
                "order_items.{$index}.unit_price"
            );
            if (! PaypalBMoney::equal($expectedLineTotal, $item['line_total'] ?? '', $currency)) {
                throw ValidationException::withMessages([
                    "order_items.{$index}.line_total" => '商品行总额不等于单价乘以数量。',
                ]);
            }

            $itemTotal = $this->addMinor(
                $itemTotal,
                PaypalBMoney::toMinor($item['line_total'], $currency, "order_items.{$index}.line_total"),
                '商品明细合计'
            );
        }

        $subTotal      = null;
        $orderTotal    = null;
        $adjustmentSum = 0;
        foreach ($totals as $index => $total) {
            $code  = strtolower(trim((string) ($total['code'] ?? '')));
            $value = PaypalBMoney::signedToMinor(
                $total['value'] ?? '',
                $currency,
                "order_totals.{$index}.value"
            );

            if ($code === 'sub_total') {
                if ($subTotal !== null || $value < 0) {
                    throw ValidationException::withMessages([
                        "order_totals.{$index}" => '订单必须包含唯一且非负的商品小计。',
                    ]);
                }
                $subTotal = $value;

                continue;
            }

            if ($code === 'order_total') {
                if ($orderTotal !== null || $value < 0) {
                    throw ValidationException::withMessages([
                        "order_totals.{$index}" => '订单必须包含唯一且非负的订单总额。',
                    ]);
                }
                $orderTotal = $value;

                continue;
            }

            $adjustmentSum = $this->addMinor($adjustmentSum, $value, '订单费用与折扣合计');
        }

        if ($subTotal === null || $orderTotal === null) {
            throw ValidationException::withMessages([
                'order_totals' => '订单金额明细必须包含商品小计和订单总额。',
            ]);
        }

        $expectedAmount = PaypalBMoney::toMinor($amount, $currency);
        if ($itemTotal !== $subTotal) {
            throw ValidationException::withMessages([
                'order_totals' => '商品行合计与订单商品小计不一致。',
            ]);
        }
        if ($this->addMinor($itemTotal, $adjustmentSum, '订单应付金额') !== $orderTotal
            || $orderTotal                                        !== $expectedAmount
            || $expectedAmount <= 0) {
            throw ValidationException::withMessages([
                'amount' => '订单商品、费用、折扣和应付总额不一致。',
            ]);
        }
    }

    private function addMinor(int $first, int $second, string $field): int
    {
        if (($second > 0 && $first > PHP_INT_MAX - $second)
            || ($second < 0 && $first < PHP_INT_MIN - $second)) {
            throw ValidationException::withMessages([$field => '订单金额超出可计算范围。']);
        }

        return $first + $second;
    }

    /**
     * 仅把不可逆索引写入数据库；计数器使用 database cache 的原子 increment，不依赖 Redis。
     */
    private function assertRiskRateLimit(array $data): void
    {
        $store      = Cache::store('database');
        $dimensions = [
            'email'       => (string) $data['buyer_email_hash'],
            'ip'          => (string) $data['buyer_ip_hash'],
            'fingerprint' => (string) $data['risk_fingerprint'],
        ];
        $exceeded = false;

        foreach ($dimensions as $dimension => $value) {
            if ($value === '') {
                continue;
            }

            $key = 'paypal_b:risk:' . $dimension . ':' . $value;
            $store->add($key, 0, now()->addMinutes(self::RISK_RATE_LIMIT_WINDOW_MINUTES));
            $count = $store->increment($key);
            if ($count === false) {
                // 数据库缓存条目在极端并发下刚好过期时重新建立一个短窗口。
                $store->put($key, 1, now()->addMinutes(self::RISK_RATE_LIMIT_WINDOW_MINUTES));
                $count = 1;
            }
            if ((int) $count > self::RISK_RATE_LIMIT_MAX_ATTEMPTS) {
                $exceeded = true;
            }
        }

        if ($exceeded) {
            throw ValidationException::withMessages([
                'payment' => '本次支付请求过于频繁，请 15 分钟后重试。',
            ]);
        }
    }

    private function validateIp(mixed $value, string $field): string
    {
        if (! is_scalar($value)) {
            throw ValidationException::withMessages([$field => '买家 IP 地址格式无效。']);
        }
        $value = trim((string) $value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw ValidationException::withMessages([$field => '买家 IP 地址格式无效。']);
        }

        $packed = inet_pton($value);
        if ($packed === false || ($value === '0.0.0.0') || ($value === '::')) {
            throw ValidationException::withMessages([$field => '买家 IP 地址格式无效。']);
        }

        return (string) inet_ntop($packed);
    }

    private function validateUserAgent(mixed $value, string $field): string
    {
        if (! is_scalar($value)) {
            throw ValidationException::withMessages([$field => '买家 User-Agent 格式无效。']);
        }
        $value = (string) $value;
        // 控制字符必须在 trim 之前检查，否则末尾的 NUL 等字符会被静默去掉。
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw ValidationException::withMessages([$field => '买家 User-Agent 格式无效。']);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 1024) {
            throw ValidationException::withMessages([$field => '买家 User-Agent 格式无效。']);
        }

        return $value;
    }

    private function auditHash(string $value): string
    {
        $key = trim((string) ($this->configuration['risk_audit_secret'] ?? ''));
        if ($key === '') {
            $key = trim((string) ($this->configuration['audit_signing_secret'] ?? ''));
        }
        if ($key === '') {
            $key = trim((string) ($this->configuration['a_request_verification_secret'] ?? ''));
        }
        if ($key === '') {
            $key = 'paypal-b-risk-audit';

            try {
                if (app()->bound('config')) {
                    $key = trim((string) config('app.key', $key)) ?: $key;
                }
            } catch (\Throwable) {
                // 纯单元测试或未完整启动容器时，退回到稳定默认值。
            }
        }

        return hash_hmac('sha256', strtolower(trim($value)), $key);
    }

    private function riskFingerprint(array $buyer, string $emailHash, string $ipHash): string
    {
        return $this->auditHash(implode('|', [
            $emailHash,
            $ipHash,
            strtolower(trim((string) $buyer['user_agent'])),
        ]));
    }

    private function payerEmailHash(PaypalBTransaction $transaction, array $provider): ?string
    {
        $email = trim((string) (
            data_get($provider, 'payer.email_address')
            ?: data_get($provider, 'payment_source.paypal.email_address')
            ?: data_get($provider, 'purchase_units.0.payments.captures.0.payer.email_address')
        ));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $transaction->payer_email_hash ?: null;
        }

        $hash = $this->auditHash($email);
        if ($transaction->payer_email_hash !== null
            && ! hash_equals((string) $transaction->payer_email_hash, $hash)) {
            throw ValidationException::withMessages([
                'payment' => 'PayPal 返回的付款人邮箱与原支付记录不一致。',
            ]);
        }

        return $hash;
    }

    private function persistClientMetadataId(PaypalBTransaction $transaction, ?string $clientMetadataId): ?string
    {
        $candidate = trim((string) $clientMetadataId);
        if ($candidate !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $candidate)) {
            throw ValidationException::withMessages([
                'client_metadata_id' => 'PayPal Client Metadata ID 格式无效。',
            ]);
        }

        $stored = trim((string) $transaction->paypal_client_metadata_id);
        if ($stored !== '') {
            if ($candidate !== '' && ! hash_equals($stored, $candidate)) {
                throw ValidationException::withMessages([
                    'client_metadata_id' => 'PayPal 风险信号 ID 与原支付会话不一致。',
                ]);
            }

            return $stored;
        }
        if ($candidate === '') {
            return null;
        }

        $transaction->forceFill(['paypal_client_metadata_id' => $candidate])->saveOrFail();

        return $candidate;
    }

    private function exceptionAuditPayload(\Throwable $exception): array
    {
        return [
            'class'         => get_class($exception),
            'message'       => mb_substr($exception->getMessage(), 0, 2000),
            'http_status'   => $exception instanceof PaypalBApiException ? $exception->httpStatus : null,
            'paypal_name'   => $exception instanceof PaypalBApiException ? $exception->paypalName : '',
            'issues'        => $exception instanceof PaypalBApiException ? $exception->issues : [],
            'retryable'     => $exception instanceof PaypalBApiException && $exception->retryable,
            'failure_class' => $exception instanceof PaypalBApiException ? $exception->failureClass() : 'unknown',
            'recorded_at'   => now()->toIso8601String(),
        ];
    }

    /**
     * 已取消、失败或过期且没有实际捕获的旧会话，不应阻止订单重新发起付款。
     * 一旦存在 capture ID，仍必须人工核对，避免遗漏已经到账的款项。
     */
    private function isTerminalUncapturedAttempt(PaypalBTransaction $transaction): bool
    {
        return ! $transaction->paypal_capture_id
            && in_array((string) $transaction->status, ['cancelled', 'failed', 'expired'], true);
    }

    private function assertPayPalItemSnapshot(PaypalBTransaction $transaction): void
    {
        $source       = (string) $transaction->paypal_item_source;
        $orderItems   = $transaction->order_items;
        $paypalItems  = $transaction->paypal_order_items;
        if (! in_array($source, [self::ITEM_SOURCE_A_ORDER, self::ITEM_SOURCE_A_MAPPING], true)
            || ! is_array($orderItems) || $orderItems   === []
            || ! is_array($paypalItems) || $paypalItems === []) {
            throw ValidationException::withMessages([
                'payment' => '该 PayPal 会话缺少不可变的订单或支付商品快照，不能继续付款。',
            ]);
        }

        $this->assertOrderAmounts(
            $orderItems,
            (array) $transaction->order_totals,
            (string) $transaction->amount,
            strtoupper((string) $transaction->currency)
        );
        $this->assertOrderAmounts(
            $paypalItems,
            (array) $transaction->order_totals,
            (string) $transaction->amount,
            strtoupper((string) $transaction->currency)
        );
        $this->assertPaypalItemsMatchOrderItems($orderItems, $paypalItems, (string) $transaction->currency, $source);
    }

    public function capture(PaypalBTransaction $transaction, string $clientMetadataId = null): PaypalBTransaction
    {
        try {
            return Cache::store('database')->lock('paypal_b:capture:' . $transaction->id, 180)
                ->block(15, fn (): PaypalBTransaction => $this->captureUnlocked($transaction, $clientMetadataId));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'payment' => trans('PaypalB::common.session_processing'),
            ]);
        }
    }

    private function captureUnlocked(PaypalBTransaction $transaction, string $clientMetadataId = null): PaypalBTransaction
    {
        $clientMetadataId = $this->persistClientMetadataId($transaction, $clientMetadataId);
        $this->assertActive($transaction);
        if ($transaction->mode !== 'automatic' || ! $transaction->paypal_order_id) {
            throw ValidationException::withMessages(['payment' => '该会话不是可自动捕获的 PayPal 订单。']);
        }
        if ($transaction->status === 'completed') {
            return $transaction;
        }
        if (in_array($transaction->status, ['cancelled', 'failed', 'expired'], true)) {
            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.session_ended')]);
        }

        $account = $transaction->account;
        if (! $account || ! $account->usesApi()) {
            throw ValidationException::withMessages(['payment' => 'PayPal 收款账号不可用于自动捕获。']);
        }
        $this->assertAccountApplication($transaction, $account);

        $client = $this->apiClient($account);
        if ($transaction->hasPendingCapture()) {
            // 已经捕获过、只是资金待确认：再次捕获会被 PayPal 拒绝，只能查询原订单的最新结果。
            return $this->refreshCapture($transaction, $account, $client);
        }

        try {
            $provider = $client->captureOrder(
                (string) $transaction->paypal_order_id,
                (string) $transaction->reference,
                $clientMetadataId
            );
            $capture = $this->captureData($provider);
        } catch (\Throwable $exception) {
            // PayPal 回跳可能重复，或 Webhook 已经先完成捕获；读取订单状态可安全恢复这类幂等场景。
            $recovered = $this->recoverCapture($transaction, $client);
            if ($recovered !== null) {
                return $this->applyCaptureResult($transaction, $account, $recovered['provider'], $recovered['capture']);
            }

            $selector  = $this->accountSelector();
            $retryable = $exception instanceof PaypalBApiException && $exception->retryable;
            if ($retryable) {
                $selector->markFailure($account, $exception->getMessage());
            } else {
                $selector->recordRejectedRequest($account, $exception->getMessage());
            }
            $transaction->forceFill([
                'status'            => $retryable ? 'pending' : 'failed',
                'provider_payload'  => [
                    'error'       => $exception->getMessage(),
                    'http_status' => $exception instanceof PaypalBApiException ? $exception->httpStatus : null,
                    'paypal_name' => $exception instanceof PaypalBApiException ? $exception->paypalName : '',
                    'issues'      => $exception instanceof PaypalBApiException ? $exception->issues : [],
                    'retryable'   => $retryable,
                ],
                'failure_retryable'         => $retryable,
                'failure_class'             => $exception instanceof PaypalBApiException ? $exception->failureClass() : 'configuration',
                'payment_exception_status'  => $retryable ? 'capture_transient' : 'capture_rejected',
                'payment_exception_payload' => $this->exceptionAuditPayload($exception),
            ])->saveOrFail();
            if (! $retryable) {
                app(PaypalBCallbackService::class)->queue($transaction->fresh());
            }

            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }

        return $this->applyCaptureResult($transaction, $account, $provider, $capture);
    }

    /**
     * 按 PayPal 捕获状态落库：COMPLETED 入账；PENDING 记录捕获号等待最终结果；DECLINED/FAILED 视为拒绝。
     * 服务端捕获响应、幂等恢复、后台同步和补偿命令都走这一条路径。
     */
    private function applyCaptureResult(PaypalBTransaction $transaction, PaypalBAccount $account, array $provider, array $capture): PaypalBTransaction
    {
        try {
            $this->assertCaptureMatchesTransaction($transaction, $capture);
        } catch (\RuntimeException $exception) {
            // 金额或币种对不上是必须人工核对的异常，不能当作成功也不能当作失败自动处理。
            $transaction->forceFill([
                'provider_payload'          => $provider,
                'payment_exception_status'  => 'capture_mismatch',
                'payment_exception_payload' => [
                    'message'     => mb_substr($exception->getMessage(), 0, 2000),
                    'capture_id'  => (string) ($capture['id'] ?? ''),
                    'recorded_at' => now()->toIso8601String(),
                ],
            ])->saveOrFail();

            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }

        $status    = strtoupper((string) ($capture['status'] ?? ''));
        $captureId = (string) $capture['id'];
        if (in_array($status, ['COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED'], true)) {
            $this->markCompleted($transaction, $provider, $captureId);
            $this->accountSelector()->markSuccess($account);

            return $transaction->fresh();
        }
        if (in_array($status, ['DECLINED', 'FAILED'], true)) {
            $this->markCaptureDenied($transaction, $provider, $capture);

            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.capture_declined')]);
        }

        // PENDING：买家已付款，PayPal 审核、电子支票清算或人工接受后才会到账。账号本身工作正常。
        $this->markCapturePending($transaction, $provider, $capture);
        $this->accountSelector()->markSuccess($account);

        return $transaction->fresh();
    }

    /**
     * 记录待确认的捕获。捕获号一旦落库，会话就不会再过期，同一订单也不能再建新会话，
     * 否则买家可能付两次；PayPal 最终完成时按捕获号入账，不受会话有效期限制。
     */
    private function markCapturePending(PaypalBTransaction $transaction, array $provider, array $capture): void
    {
        $captureId = trim((string) ($capture['id'] ?? ''));
        if ($captureId !== '' && PaypalBTransaction::query()
            ->where('paypal_capture_id', $captureId)
            ->where('id', '<>', $transaction->id)
            ->exists()) {
            throw ValidationException::withMessages(['payment' => '该 PayPal 捕获号已经绑定到其他支付会话。']);
        }

        $transaction->forceFill([
            'status'                    => 'pending',
            'paypal_capture_id'         => $captureId !== '' ? $captureId : $transaction->paypal_capture_id,
            'provider_status'           => strtoupper((string) ($capture['status'] ?? 'PENDING')),
            'provider_payload'          => $provider,
            'failure_retryable'         => null,
            'failure_class'             => null,
            'callback_last_error'       => null,
            'payment_exception_status'  => 'capture_pending',
            'payment_exception_payload' => [
                'reason'            => (string) data_get($capture, 'status_details.reason', ''),
                'seller_protection' => (string) data_get($capture, 'seller_protection.status', ''),
                'recorded_at'       => now()->toIso8601String(),
            ],
        ])->saveOrFail();

        // 让 A 站立刻知道买家已付款、只是待确认，避免 A 站在会话到期后允许重新付款。
        app(PaypalBCallbackService::class)->queue($transaction->fresh());
    }

    /**
     * PayPal 明确拒绝了捕获（风控拒绝、电子支票退票等）。捕获号只留在审计字段里，
     * 不写入 paypal_capture_id，否则这条失败会话会永久阻止该订单重新付款。
     */
    private function markCaptureDenied(PaypalBTransaction $transaction, array $provider, array $capture): void
    {
        $transaction->forceFill([
            'status'                    => 'failed',
            'paypal_capture_id'         => null,
            'provider_status'           => strtoupper((string) ($capture['status'] ?? 'DECLINED')),
            'provider_payload'          => $provider,
            'failure_retryable'         => false,
            'failure_class'             => 'provider_rejected',
            'payment_exception_status'  => 'capture_denied',
            'payment_exception_payload' => [
                'capture_id'  => (string) ($capture['id'] ?? ''),
                'reason'      => (string) data_get($capture, 'status_details.reason', ''),
                'recorded_at' => now()->toIso8601String(),
            ],
            'callback_last_error' => mb_substr('PayPal 拒绝了捕获：' . (string) data_get($capture, 'status_details.reason', ''), 0, 2000),
        ])->saveOrFail();

        app(PaypalBCallbackService::class)->queue($transaction->fresh());
        app(PaypalBShopOrderService::class)->cancelFromTransaction($transaction->fresh(), 'capture_denied');
    }

    /**
     * 查询 PayPal 订单上的最新捕获结果并落库。捕获仍待确认或 PayPal 暂时不可达时保持原状。
     */
    private function refreshCapture(PaypalBTransaction $transaction, PaypalBAccount $account, PaypalBApiClient $client): PaypalBTransaction
    {
        $recovered = $this->recoverCapture($transaction, $client);
        if ($recovered === null) {
            return $transaction->fresh();
        }

        return $this->applyCaptureResult($transaction, $account, $recovered['provider'], $recovered['capture']);
    }

    /**
     * 后台“同步 PayPal 状态”和补偿命令使用：Webhook 丢失时也能把待确认的捕获推进到最终状态。
     */
    public function syncWithPaypal(PaypalBTransaction $transaction): PaypalBTransaction
    {
        if (! $transaction->paypal_order_id || ! in_array($transaction->status, ['pending', 'creating'], true)) {
            return $transaction;
        }
        $account = $transaction->account;
        if (! $account || ! $account->usesApi()) {
            throw ValidationException::withMessages(['payment' => 'PayPal 收款账号不可用，无法同步状态。']);
        }
        $this->assertAccountApplication($transaction, $account);

        try {
            return Cache::store('database')->lock('paypal_b:capture:' . $transaction->id, 180)
                ->block(15, fn (): PaypalBTransaction => $this->refreshCapture($transaction, $account, $this->apiClient($account)));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.session_processing')]);
        }
    }

    /**
     * 历史人工会话不能继续展示或确认；保留明确的失效记录并通知 A 站重建会话。
     */
    public function invalidateLegacySession(PaypalBTransaction $transaction, string $reason = 'legacy_mode_disabled'): void
    {
        if (in_array($transaction->status, ['completed', 'cancelled', 'failed', 'expired'], true)) {
            return;
        }

        $transaction->forceFill([
            'status'              => 'cancelled',
            'provider_status'     => 'LEGACY_SESSION_INVALIDATED',
            'failure_retryable'   => false,
            'failure_class'       => 'configuration',
            'provider_payload'    => [
                'reason'         => $reason,
                'invalidated_at' => now()->toIso8601String(),
            ],
            'callback_status'          => 'pending',
            'callback_next_attempt_at' => now(),
            'callback_last_error'      => null,
        ])->saveOrFail();

        try {
            app(PaypalBCallbackService::class)->queue($transaction->fresh());
        } catch (\Throwable $exception) {
            // 失效本身必须保留；队列或回调暂时不可用时由补偿命令再次投递。
            report($exception);
        }
        app(PaypalBShopOrderService::class)->cancelFromTransaction($transaction->fresh(), $reason);
    }

    public function applyWebhook(PaypalBTransaction $transaction, array $event): PaypalBTransaction
    {
        $eventType = (string) ($event['event_type'] ?? '');
        if (in_array($eventType, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'], true)) {
            return app(PaypalBRefundService::class)->applyWebhook($transaction, $event);
        }
        if (str_starts_with($eventType, 'CUSTOMER.DISPUTE.')) {
            return $this->applyDisputeWebhook($transaction, $event);
        }
        if (! in_array($eventType, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED'], true)) {
            throw ValidationException::withMessages(['webhook' => 'PayPal Webhook 事件类型不是收款结果事件。']);
        }

        $resource = (array) ($event['resource'] ?? []);
        $amount   = (array) ($resource['amount'] ?? []);
        $status   = strtoupper((string) ($resource['status'] ?? ''));
        $expected = match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED' => ['COMPLETED'],
            'PAYMENT.CAPTURE.PENDING'   => ['PENDING'],
            default                     => ['DECLINED', 'FAILED'],
        };
        if (! in_array($status, $expected, true)) {
            throw ValidationException::withMessages(['webhook' => 'PayPal Webhook 的捕获状态与事件类型不符。']);
        }
        $captureId = trim((string) ($resource['id'] ?? ''));
        $orderId   = trim((string) data_get($resource, 'supplementary_data.related_ids.order_id', ''));
        $invoiceId = trim((string) ($resource['invoice_id'] ?? ''));
        if ($captureId === '' || $orderId === '') {
            throw ValidationException::withMessages(['webhook' => 'PayPal Webhook 缺少捕获号或 PayPal 订单号。']);
        }
        if (! hash_equals((string) $transaction->paypal_order_id, $orderId)) {
            throw ValidationException::withMessages(['webhook' => 'PayPal Webhook 订单号与跨站会话不一致。']);
        }
        // 发票编号在完整明细模式是支付引用，在兼容模式是订单号；携带时必须与其一一致。
        if ($invoiceId !== '' && ! in_array($invoiceId, [(string) $transaction->reference, (string) $transaction->order_number], true)) {
            throw ValidationException::withMessages(['webhook' => 'PayPal Webhook 发票编号与支付会话不一致。']);
        }
        if ($transaction->paypal_capture_id && ! hash_equals((string) $transaction->paypal_capture_id, $captureId)) {
            throw ValidationException::withMessages(['webhook' => 'PayPal Webhook 捕获号与已记录交易不一致。']);
        }
        if (! isset($amount['value'], $amount['currency_code'])
            || ! PaypalBMoney::equal($transaction->amount, $amount['value'], (string) $transaction->currency)
            || strtoupper((string) $amount['currency_code']) !== strtoupper((string) $transaction->currency)) {
            throw ValidationException::withMessages(['webhook' => 'PayPal Webhook 金额与跨站订单不一致。']);
        }

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            $this->markCompleted($transaction, $event, $captureId);

            return $transaction->fresh();
        }
        if ($transaction->status === 'completed') {
            // 迟到的待处理或拒绝事件不能推翻已确认的收款；拒绝到账后的资金变化由撤销/争议事件处理。
            return $transaction;
        }
        if ($eventType === 'PAYMENT.CAPTURE.PENDING') {
            // 保留服务端捕获响应里的收货地址等信息，只补捕获号和待处理原因。
            $this->markCapturePending($transaction, (array) ($transaction->provider_payload ?: $event), $resource);

            return $transaction->fresh();
        }

        $this->markCaptureDenied($transaction, (array) ($transaction->provider_payload ?: $event), $resource);

        return $transaction->fresh();
    }

    /**
     * Webhook 按同一账号与 PayPal Order ID 定位（订单号在本表唯一）；发票编号的一致性在 applyWebhook 中校验，
     * 捕获号若已记录也必须一致。
     */
    public function findWebhookTransaction(array $event, int $accountId): ?PaypalBTransaction
    {
        $eventType = (string) ($event['event_type'] ?? '');
        $resource  = (array) ($event['resource'] ?? []);
        if (in_array($eventType, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED'], true)) {
            $captureId = trim((string) ($resource['id'] ?? ''));
            $orderId   = trim((string) data_get($resource, 'supplementary_data.related_ids.order_id', ''));
            if ($captureId === '' || $orderId === '') {
                return null;
            }

            return PaypalBTransaction::query()
                ->where('account_id', $accountId)
                ->where('paypal_order_id', $orderId)
                ->where(function ($query) use ($captureId): void {
                    $query->whereNull('paypal_capture_id')->orWhere('paypal_capture_id', $captureId);
                })
                ->first();
        }

        if (in_array($eventType, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'], true)) {
            $captureId = trim((string) ($resource['id'] ?? ''));
            if ($captureId === '') {
                return null;
            }

            return PaypalBTransaction::query()
                ->where('account_id', $accountId)
                ->where('paypal_capture_id', $captureId)
                ->first();
        }

        if (str_starts_with($eventType, 'CUSTOMER.DISPUTE.')) {
            $captureId = trim((string) (
                data_get($resource, 'disputed_transactions.0.seller_transaction_id')
                ?: data_get($resource, 'disputed_transactions.0.transaction_id')
                ?: data_get($resource, 'seller_transaction_id')
            ));
            if ($captureId === '') {
                return null;
            }

            return PaypalBTransaction::query()
                ->where('account_id', $accountId)
                ->where('paypal_capture_id', $captureId)
                ->first();
        }

        return null;
    }

    private function applyDisputeWebhook(PaypalBTransaction $transaction, array $event): PaypalBTransaction
    {
        $eventType = (string) ($event['event_type'] ?? '');
        $resource  = (array) ($event['resource'] ?? []);
        $disputeId = trim((string) (
            $resource['dispute_id']
            ?? $resource['id']
            ?? ''
        ));
        if ($disputeId === '') {
            throw ValidationException::withMessages(['webhook' => 'PayPal 争议事件缺少争议号。']);
        }

        $incomingStatus = match ($eventType) {
            'CUSTOMER.DISPUTE.CREATED'   => 'open',
            'CUSTOMER.DISPUTE.UPDATED'   => 'updated',
            'CUSTOMER.DISPUTE.RESOLVED'  => 'resolved',
            'CUSTOMER.DISPUTE.CANCELLED' => 'cancelled',
            default                      => throw ValidationException::withMessages(['webhook' => '不支持的 PayPal 争议事件类型。']),
        };
        $priority = ['open' => 1, 'updated' => 2, 'resolved' => 3, 'cancelled' => 4];
        $changed  = DB::transaction(function () use ($transaction, $event, $disputeId, $incomingStatus, $priority): bool {
            $locked  = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $current = (string) ($locked->dispute_status ?? '');
            if ($current !== '' && ($priority[$current] ?? 0) > ($priority[$incomingStatus] ?? 0)) {
                return false;
            }
            $locked->forceFill([
                'dispute_status'  => $incomingStatus,
                'dispute_payload' => [
                    'dispute_id'  => $disputeId,
                    'event'       => $event,
                    'recorded_at' => now()->toIso8601String(),
                ],
                'callback_status'          => 'pending',
                'callback_next_attempt_at' => now(),
                'callback_last_error'      => null,
            ])->saveOrFail();

            return true;
        });
        if ($changed) {
            $transaction->refresh();
            app(PaypalBCallbackService::class)->queue($transaction);
        }

        return $transaction->fresh();
    }

    public function syncFulfillment(PaypalBTransaction $transaction, array $payload): PaypalBTransaction
    {
        if (! hash_equals((string) $transaction->transaction_id, trim((string) ($payload['transaction_id'] ?? '')))
            || ! hash_equals((string) $transaction->reference, trim((string) ($payload['reference'] ?? '')))
            || ! hash_equals((string) $transaction->order_number, trim((string) ($payload['order_number'] ?? '')))) {
            throw ValidationException::withMessages(['fulfillment' => '履约证据与 B 站支付会话不一致。']);
        }

        $fulfillment = $this->validateFulfillment($payload['fulfillment'] ?? null);
        $transaction->forceFill([
            'fulfillment_evidence'   => $fulfillment,
            'fulfillment_updated_at' => now(),
        ])->saveOrFail();

        return $transaction->fresh();
    }

    public function statusPayload(PaypalBTransaction $transaction): array
    {
        // 已捕获待确认的会话不会过期：买家已经付款，过期只会把它推向重复付款。
        if ($transaction->expires_at
            && $transaction->expires_at->isPast()
            && ! $transaction->hasPendingCapture()
            && $transaction->status !== 'reconciling'
            && ! in_array($transaction->status, ['completed', 'cancelled', 'failed', 'expired'], true)) {
            $transaction->forceFill(['status' => 'expired'])->saveOrFail();
            app(PaypalBCallbackService::class)->queue($transaction->fresh());
            app(PaypalBShopOrderService::class)->cancelFromTransaction($transaction->fresh(), 'expired');
        }

        return [
            'transaction_id'           => (string) $transaction->transaction_id,
            'reference'                => (string) $transaction->reference,
            'order_number'             => (string) $transaction->order_number,
            'amount'                   => PaypalBMoney::normalize($transaction->amount, (string) $transaction->currency),
            'currency'                 => strtoupper((string) $transaction->currency),
            'status'                   => $transaction->status,
            'provider_transaction_id'  => (string) ($transaction->paypal_capture_id ?: data_get($transaction->provider_payload, 'provider_transaction_id', '')),
            'checkout_url'             => $this->checkoutUrl($transaction),
            'allowed_methods'          => $this->allowedMethods($transaction),
            'expires_at'               => $transaction->expires_at?->toIso8601String(),
            'refund_status'            => (string) ($transaction->refund_status ?: 'none'),
            'refunded_amount'          => PaypalBMoney::normalize($transaction->refunded_amount ?: '0', (string) $transaction->currency),
            'dispute_status'           => $transaction->dispute_status,
            // A 站据此确认 B 站确实按内嵌方式建了会话；缺失即视为旧版 B 站，不能内嵌。
            'presentation'             => (string) ($transaction->presentation ?: PaypalBTransaction::PRESENTATION_REDIRECT),
        ];
    }

    public function assertActiveForView(PaypalBTransaction $transaction): void
    {
        $this->assertActive($transaction);
    }

    public function checkoutUrl(PaypalBTransaction $transaction, string $method = 'wallet'): string
    {
        $url = BridgeUrl::join(
            $this->configuration['public_url'],
            '/paypal/checkout/' . rawurlencode((string) $transaction->public_token)
        );

        return $method === 'wallet' ? $url : $url . '?method=' . rawurlencode($method);
    }

    public function checkoutItems(PaypalBTransaction $transaction): array
    {
        return $this->paypalOrderItems($transaction);
    }

    public function returnUrl(PaypalBTransaction $transaction, string $method = 'wallet'): string
    {
        $query = http_build_query([
            'bridge_token' => $transaction->public_token,
            ...($method === 'wallet' ? [] : ['method' => $method]),
        ], '', '&', PHP_QUERY_RFC3986);

        return BridgeUrl::join($this->configuration['public_url'], '/paypal/return?' . $query);
    }

    public function cancelUrl(PaypalBTransaction $transaction): string
    {
        return BridgeUrl::join($this->configuration['public_url'], '/paypal/cancel?bridge_token=' . rawurlencode($transaction->public_token));
    }

    public function assertCheckoutMethod(PaypalBTransaction $transaction, string $method): string
    {
        $method = strtolower(trim($method));
        if (! in_array($method, $this->allowedMethods($transaction), true)) {
            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.method_not_enabled')]);
        }
        if ($method === 'card' && ! $this->configuration['card_enabled']) {
            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.card_not_enabled')]);
        }
        if ($method === 'wallet' && ! $this->configuration['wallet_enabled']) {
            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.wallet_not_enabled')]);
        }
        if ($method === 'card' && $transaction->mode !== 'automatic') {
            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.card_not_supported')]);
        }

        return $method;
    }

    public function cardClientToken(PaypalBTransaction $transaction): string
    {
        $this->assertCheckoutMethod($transaction, 'card');
        $account = $transaction->account;
        if (! $account || ! $account->usesApi()) {
            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.card_not_supported')]);
        }
        $this->assertAccountApplication($transaction, $account);

        try {
            return $this->apiClient($account)->clientToken();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.card_component_down')]);
        }
    }

    public function captureToken(PaypalBTransaction $transaction): string
    {
        return hash_hmac('sha256', implode('|', [
            (string) $transaction->transaction_id,
            (string) $transaction->public_token,
            (string) $transaction->paypal_order_id,
            (string) $transaction->reference,
        ]), $this->transactionSigningSecret());
    }

    public function assertCaptureToken(PaypalBTransaction $transaction, string $token): void
    {
        if ($token === '' || ! hash_equals($this->captureToken($transaction), $token)) {
            throw ValidationException::withMessages(['capture_token' => trans('PaypalB::common.capture_token_invalid')]);
        }
    }

    /**
     * 正常运行时由 PaypalBConfiguration 提供：桥接会话继续使用既有回调密钥，
     * 独立 B 站则使用 APP_KEY 派生密钥。最后的回退仅兼容旧交易和窄单元测试，
     * 不会被完整配置路径使用。
     */
    private function transactionSigningSecret(): string
    {
        $secret = trim((string) ($this->configuration['transaction_signing_secret']
            ?? $this->configuration['a_callback_signing_secret']
            ?? ''));

        return $secret !== '' ? $secret : 'paypal-b-legacy-transaction-signing';
    }

    private function validateCreatePayload(array $payload): array
    {
        $this->assertBridgeEnabled();
        foreach (['reference', 'order_number', 'amount', 'currency', 'callback_url'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                throw ValidationException::withMessages([$field => 'A 站请求缺少必要字段。']);
            }
        }

        $currency = strtoupper(trim((string) $payload['currency']));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ValidationException::withMessages(['currency' => '币种代码必须是三位大写字母。']);
        }
        $expiresRaw = trim((string) ($payload['expires_at'] ?? ''));
        if ($expiresRaw === '') {
            throw ValidationException::withMessages(['expires_at' => 'A 站请求缺少支付会话有效期。']);
        }

        try {
            // Eloquent 按 Carbon 实例自身时区格式化落库，必须先归一到应用时区，
            // 否则 A、B 两站时区不同时，会话一入库就被判定为已过期。
            $expiresAt = CarbonImmutable::parse($expiresRaw)->setTimezone(date_default_timezone_get());
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['expires_at' => 'A 站支付会话有效期格式无效。']);
        }
        if ($expiresAt->isPast() || $expiresAt->greaterThan(now()->addMinutes(120))) {
            throw ValidationException::withMessages(['expires_at' => 'A 站支付会话有效期无效。']);
        }
        if (! BridgeUrl::isAllowedCallback((string) $payload['callback_url'], $this->configuration['a_site_url'])) {
            throw ValidationException::withMessages(['callback_url' => '回调地址不在已配置的 A 站白名单内。']);
        }

        $reference   = trim((string) $payload['reference']);
        $orderNumber = trim((string) $payload['order_number']);
        if (! preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $reference)) {
            throw ValidationException::withMessages(['reference' => 'A 站支付引用格式无效。']);
        }
        if (strlen($orderNumber) > 128) {
            throw ValidationException::withMessages(['order_number' => 'A 站订单号过长。']);
        }

        $allowedMethods = $payload['allowed_methods'] ?? ['wallet'];
        if (! is_array($allowedMethods)) {
            throw ValidationException::withMessages(['allowed_methods' => 'A 站付款方式配置格式无效。']);
        }
        $allowedMethods = array_values(array_unique(array_filter(array_map(
            static fn (mixed $method): string => strtolower(trim((string) $method)),
            $allowedMethods
        ), static fn (string $method): bool => in_array($method, ['wallet', 'card'], true))));
        if (! $this->configuration['card_enabled']) {
            $allowedMethods = array_values(array_diff($allowedMethods, ['card']));
        }
        if (! $this->configuration['wallet_enabled']) {
            $allowedMethods = array_values(array_diff($allowedMethods, ['wallet']));
        }
        if ($allowedMethods === []) {
            throw ValidationException::withMessages(['allowed_methods' => 'B 站当前不允许请求的 PayPal 付款方式。']);
        }

        $buyer            = $this->validateBuyer($payload['buyer'] ?? null);
        $orderItems       = $this->validateOrderItems($payload['order_items'] ?? null);
        $paypalItemSource = $this->validatePaypalItemSource($payload['paypal_item_source'] ?? null);
        $paypalOrderItems = $this->validateOrderItems($payload['paypal_order_items'] ?? $orderItems);
        $orderTotals      = $this->validateOrderTotals($payload['order_totals'] ?? null);
        $shippingAddress  = $this->validateShippingAddress($payload['shipping_address'] ?? null);
        $fulfillment      = $this->validateFulfillment($payload['fulfillment'] ?? []);
        $orderSnapshot    = $this->validateOrderSnapshot($payload['order_snapshot'] ?? null);
        $amount           = PaypalBMoney::normalize($payload['amount'], $currency);

        if (! hash_equals($orderNumber, $orderSnapshot['number'])
            || ! hash_equals($currency, $orderSnapshot['currency'])
            || ! PaypalBMoney::equal($amount, $orderSnapshot['amount'], $currency)) {
            throw ValidationException::withMessages(['order_snapshot' => '真实订单快照与支付订单号、金额或币种不一致。']);
        }
        if ((bool) $orderSnapshot['shipping_required'] !== (bool) $shippingAddress['required']) {
            throw ValidationException::withMessages(['shipping_address' => '订单配送属性与收货地址快照不一致。']);
        }

        $snapshotHash = hash('sha256', BridgeSignature::canonicalJson([
            'buyer'            => $buyer,
            'order_items'      => $orderItems,
            'order_totals'     => $orderTotals,
            'shipping_address' => $shippingAddress,
            'order_snapshot'   => $orderSnapshot,
        ]));

        $this->assertOrderAmounts($orderItems, $orderTotals, $amount, $currency);
        $this->assertOrderAmounts($paypalOrderItems, $orderTotals, $amount, $currency);
        $this->assertPaypalItemsMatchOrderItems($orderItems, $paypalOrderItems, $currency, $paypalItemSource);

        $buyerEmailHash  = $this->auditHash($buyer['email']);
        $buyerIpHash     = $this->auditHash($buyer['ip']);
        $riskFingerprint = $this->riskFingerprint($buyer, $buyerEmailHash, $buyerIpHash);

        return [
            'reference'           => $reference,
            'order_number'        => $orderNumber,
            'amount'              => $amount,
            'currency'            => $currency,
            'buyer_locale'        => $this->validateLocale($payload['locale'] ?? null),
            'presentation'        => $this->validatePresentation($payload['presentation'] ?? null),
            'callback_url'        => trim((string) $payload['callback_url']),
            'expires_at'          => $expiresAt,
            'allowed_methods'     => $allowedMethods,
            'buyer'               => $buyer,
            'order_items'         => $orderItems,
            'paypal_item_source'  => $paypalItemSource,
            'paypal_order_items'  => $paypalOrderItems,
            'order_totals'        => $orderTotals,
            'shipping_address'    => $shippingAddress,
            'fulfillment'         => $fulfillment,
            'order_snapshot'      => $orderSnapshot,
            'order_snapshot_hash' => $snapshotHash,
            'buyer_email_hash'    => $buyerEmailHash,
            'buyer_ip_hash'       => $buyerIpHash,
            'risk_fingerprint'    => $riskFingerprint,
        ];
    }

    private function validateBuyer(mixed $buyer): array
    {
        if (! is_array($buyer)) {
            throw ValidationException::withMessages(['buyer' => 'A 站请求缺少真实买家快照。']);
        }
        $email = trim((string) ($buyer['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw ValidationException::withMessages(['buyer.email' => '真实买家邮箱无效。']);
        }

        $ip        = $this->validateIp($buyer['ip'] ?? '', 'buyer.ip');
        $userAgent = $this->validateUserAgent($buyer['user_agent'] ?? '', 'buyer.user_agent');

        return [
            'name'            => $this->limitedString($buyer['name'] ?? '', 255, 'buyer.name'),
            'email'           => $email,
            'calling_code'    => $this->limitedString($buyer['calling_code'] ?? '', 16, 'buyer.calling_code'),
            'telephone'       => $this->limitedString($buyer['telephone'] ?? '', 64, 'buyer.telephone'),
            'ip'              => $ip,
            'user_agent'      => $userAgent,
            'comment'         => $this->limitedString($buyer['comment'] ?? '', 2000, 'buyer.comment'),
            'billing_address' => is_array($buyer['billing_address'] ?? null) ? $buyer['billing_address'] : [],
        ];
    }

    /**
     * A 站语言只作为展示偏好，格式非法时回退到 B 站默认语言，不因此拒绝收款。
     */
    private function validateLocale(mixed $locale): ?string
    {
        $locale = strtolower(trim((string) $locale));

        return preg_match('/^[a-z]{2}(?:_[a-z]{2})?$/', $locale) === 1 ? $locale : null;
    }

    /**
     * 呈现方式由 A 站声明。B 站未允许内嵌时明确拒绝并给出稳定错误码，A 站据此自动降级为顶层跳转，
     * 而不是静默改成 redirect：那样 A 站会以为内嵌成功，把一个拒绝被嵌入的页面放进 iframe。
     */
    private function validatePresentation(mixed $presentation): string
    {
        $presentation = strtolower(trim((string) ($presentation ?: PaypalBTransaction::PRESENTATION_REDIRECT)));
        if (! in_array($presentation, [PaypalBTransaction::PRESENTATION_REDIRECT, PaypalBTransaction::PRESENTATION_EMBEDDED], true)) {
            throw ValidationException::withMessages(['presentation' => 'A 站提交了不支持的结账页呈现方式。']);
        }
        if ($presentation === PaypalBTransaction::PRESENTATION_EMBEDDED && empty($this->configuration['embedded_enabled'])) {
            throw ValidationException::withMessages([
                'presentation' => self::EMBEDDED_NOT_ALLOWED,
            ]);
        }

        return $presentation;
    }

    private function validatePaypalItemSource(mixed $source): string
    {
        $source = strtolower(trim((string) ($source ?: self::ITEM_SOURCE_A_ORDER)));
        if (! in_array($source, [self::ITEM_SOURCE_A_ORDER, self::ITEM_SOURCE_A_MAPPING], true)) {
            throw ValidationException::withMessages(['paypal_item_source' => 'A 站提交了不支持的 PayPal 商品明细模式。']);
        }

        return $source;
    }

    /**
     * 映射模式只能替换名称和 SKU；本地订单金额、数量和商品对应关系始终保持原样。
     */
    private function assertPaypalItemsMatchOrderItems(array $orderItems, array $paypalItems, string $currency, string $source): void
    {
        if (count($orderItems) !== count($paypalItems)) {
            throw ValidationException::withMessages(['paypal_order_items' => 'PayPal 商品数量与真实订单不一致。']);
        }

        foreach ($orderItems as $index => $item) {
            $paypalItem = $paypalItems[$index] ?? null;
            if (! is_array($paypalItem)
                || (int) ($paypalItem['source_product_id'] ?? 0) !== (int) ($item['source_product_id'] ?? 0)
                || (int) ($paypalItem['quantity'] ?? 0)          !== (int) ($item['quantity'] ?? 0)
                || ! PaypalBMoney::equal($paypalItem['unit_price'] ?? '', $item['unit_price'] ?? '', $currency)
                || ! PaypalBMoney::equal($paypalItem['line_total'] ?? '', $item['line_total'] ?? '', $currency)) {
                throw ValidationException::withMessages(["paypal_order_items.{$index}" => 'PayPal 商品明细的数量或金额与真实订单不一致。']);
            }
        }

        if ($source === self::ITEM_SOURCE_A_ORDER
            && ! hash_equals(
                hash('sha256', BridgeSignature::canonicalJson($orderItems)),
                hash('sha256', BridgeSignature::canonicalJson($paypalItems))
            )) {
            throw ValidationException::withMessages(['paypal_order_items' => '原商品明细模式下 PayPal 商品快照必须与真实订单一致。']);
        }
    }

    private function validateOrderItems(mixed $items): array
    {
        if (! is_array($items) || $items === [] || count($items) > 100) {
            throw ValidationException::withMessages(['order_items' => '真实订单必须包含 1 至 100 个商品明细。']);
        }

        $validated = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(["order_items.{$index}" => '商品明细格式无效。']);
            }
            $name            = $this->limitedString($item['name'] ?? '', 1000, "order_items.{$index}.name");
            $quantity        = (int) ($item['quantity'] ?? 0);
            $sourceProductId = filter_var($item['source_product_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($name === '' || $sourceProductId === false || $quantity < 1 || $quantity > 999999) {
                throw ValidationException::withMessages(["order_items.{$index}" => '商品名称或数量无效。']);
            }
            foreach (['unit_price', 'line_total'] as $moneyField) {
                if (! preg_match('/^\d{1,12}(?:\.\d{1,4})?$/', trim((string) ($item[$moneyField] ?? '')))) {
                    throw ValidationException::withMessages(["order_items.{$index}.{$moneyField}" => '商品金额格式无效。']);
                }
            }

            $validated[] = [
                'source_product_id' => (int) $sourceProductId,
                'name'              => $name,
                'sku'               => $this->limitedString($item['sku'] ?? '', 255, "order_items.{$index}.sku"),
                'quantity'          => $quantity,
                'unit_price'        => trim((string) $item['unit_price']),
                'line_total'        => trim((string) $item['line_total']),
            ];
        }

        return $validated;
    }

    private function validateOrderTotals(mixed $totals): array
    {
        if (! is_array($totals) || count($totals) > 50) {
            throw ValidationException::withMessages(['order_totals' => '订单金额明细格式无效。']);
        }

        $validated = [];
        foreach ($totals as $index => $total) {
            if (! is_array($total) || ! preg_match('/^-?\d{1,12}(?:\.\d{1,4})?$/', trim((string) ($total['value'] ?? '')))) {
                throw ValidationException::withMessages(["order_totals.{$index}" => '订单金额明细格式无效。']);
            }
            $validated[] = [
                'code'  => $this->limitedString($total['code'] ?? '', 64, "order_totals.{$index}.code"),
                'title' => $this->limitedString($total['title'] ?? '', 255, "order_totals.{$index}.title"),
                'value' => trim((string) $total['value']),
            ];
        }

        return $validated;
    }

    private function validateShippingAddress(mixed $shipping): array
    {
        if (! is_array($shipping)) {
            throw ValidationException::withMessages(['shipping_address' => 'A 站请求缺少收货地址快照。']);
        }
        $required  = ! empty($shipping['required']);
        $validated = [
            'required'     => $required,
            'name'         => $this->limitedString($shipping['name'] ?? '', 300, 'shipping_address.name'),
            'calling_code' => $this->limitedString($shipping['calling_code'] ?? '', 16, 'shipping_address.calling_code'),
            'telephone'    => $this->limitedString($shipping['telephone'] ?? '', 64, 'shipping_address.telephone'),
            'address_1'    => $this->limitedString($shipping['address_1'] ?? '', 300, 'shipping_address.address_1'),
            'address_2'    => $this->limitedString($shipping['address_2'] ?? '', 300, 'shipping_address.address_2'),
            'city'         => $this->limitedString($shipping['city'] ?? '', 120, 'shipping_address.city'),
            'zone'         => $this->limitedString($shipping['zone'] ?? '', 300, 'shipping_address.zone'),
            'postal_code'  => $this->limitedString($shipping['postal_code'] ?? '', 60, 'shipping_address.postal_code'),
            'country'      => $this->limitedString($shipping['country'] ?? '', 120, 'shipping_address.country'),
            'country_code' => strtoupper($this->limitedString($shipping['country_code'] ?? '', 2, 'shipping_address.country_code')),
        ];
        if ($required && ($validated['name'] === '' || $validated['address_1'] === '' || $validated['city'] === ''
                                                    || ! preg_match('/^[A-Z]{2}$/', $validated['country_code']))) {
            throw ValidationException::withMessages(['shipping_address' => '实物订单必须提供完整收件人、地址、城市和两位国家代码。']);
        }

        return $validated;
    }

    private function validateFulfillment(mixed $fulfillment): array
    {
        if (! is_array($fulfillment)) {
            throw ValidationException::withMessages(['fulfillment' => '履约证据格式无效。']);
        }
        $shipments = $fulfillment['shipments'] ?? [];
        if (! is_array($shipments) || count($shipments) > 20) {
            throw ValidationException::withMessages(['fulfillment.shipments' => '运单数量或格式无效。']);
        }
        $validatedShipments = [];
        foreach ($shipments as $index => $shipment) {
            if (! is_array($shipment)) {
                throw ValidationException::withMessages(["fulfillment.shipments.{$index}" => '运单格式无效。']);
            }
            $trackingNo = $this->limitedString($shipment['tracking_no'] ?? '', 128, "fulfillment.shipments.{$index}.tracking_no");
            if ($trackingNo === '') {
                continue;
            }
            $validatedShipments[] = [
                'carrier_code' => $this->limitedString($shipment['carrier_code'] ?? '', 64, "fulfillment.shipments.{$index}.carrier_code"),
                'carrier_name' => $this->limitedString($shipment['carrier_name'] ?? '', 255, "fulfillment.shipments.{$index}.carrier_name"),
                'tracking_no'  => $trackingNo,
                'shipped_at'   => $this->limitedString($shipment['shipped_at'] ?? '', 64, "fulfillment.shipments.{$index}.shipped_at"),
                'updated_at'   => $this->limitedString($shipment['updated_at'] ?? '', 64, "fulfillment.shipments.{$index}.updated_at"),
            ];
        }

        return [
            'order_status' => $this->limitedString($fulfillment['order_status'] ?? '', 32, 'fulfillment.order_status'),
            'shipments'    => $validatedShipments,
            'synced_at'    => $this->limitedString($fulfillment['synced_at'] ?? '', 64, 'fulfillment.synced_at'),
        ];
    }

    private function validateOrderSnapshot(mixed $snapshot): array
    {
        if (! is_array($snapshot)) {
            throw ValidationException::withMessages(['order_snapshot' => 'A 站请求缺少订单责任快照。']);
        }

        return [
            'number'               => $this->limitedString($snapshot['number'] ?? '', 128, 'order_snapshot.number'),
            'currency'             => strtoupper($this->limitedString($snapshot['currency'] ?? '', 3, 'order_snapshot.currency')),
            'amount'               => trim((string) ($snapshot['amount'] ?? '')),
            'shipping_required'    => ! empty($snapshot['shipping_required']),
            'shipping_method_code' => $this->limitedString($snapshot['shipping_method_code'] ?? '', 255, 'order_snapshot.shipping_method_code'),
            'shipping_method_name' => $this->limitedString($snapshot['shipping_method_name'] ?? '', 255, 'order_snapshot.shipping_method_name'),
            'created_at'           => $this->limitedString($snapshot['created_at'] ?? '', 64, 'order_snapshot.created_at'),
            'updated_at'           => $this->limitedString($snapshot['updated_at'] ?? '', 64, 'order_snapshot.updated_at'),
        ];
    }

    private function limitedString(mixed $value, int $max, string $field): string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$field => '字段长度超过限制。']);
        }

        return $value;
    }

    private function bridgeRequestData(array $data): array
    {
        return [
            'reference'           => $data['reference'],
            'order_number'        => $data['order_number'],
            'amount'              => $data['amount'],
            'currency'            => $data['currency'],
            'callback_url'        => $data['callback_url'],
            'expires_at'          => $data['expires_at']->toIso8601String(),
            'allowed_methods'     => $data['allowed_methods'],
            'order_snapshot'      => $data['order_snapshot'],
            'order_snapshot_hash' => $data['order_snapshot_hash'],
            'paypal_payload_mode' => $this->payloadMode(),
        ];
    }

    private function paypalOrderItems(PaypalBTransaction $transaction): array
    {
        $this->assertPayPalItemSnapshot($transaction);
        $items = $transaction->paypal_order_items;
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages([
                'payment' => '该 PayPal 会话缺少不可变的支付商品快照，不能继续付款。',
            ]);
        }

        return $items;
    }

    private function assertSameRequest(PaypalBTransaction $transaction, array $data): void
    {
        if ($transaction->order_number !== $data['order_number']
            || ! PaypalBMoney::equal($transaction->amount, $data['amount'], (string) $transaction->currency)
            || strtoupper((string) $transaction->currency) !== $data['currency']
            || $this->allowedMethods($transaction)         !== $data['allowed_methods']
            || ! hash_equals((string) $transaction->order_snapshot_hash, $data['order_snapshot_hash'])) {
            throw ValidationException::withMessages(['reference' => '引用已被不同金额或币种的订单占用。']);
        }
    }

    /**
     * 会话创建时固定下来的付款方式；跨站会话来自 A 站申请与 B 站开关的交集，本地会话来自账号实际能力。
     */
    public function allowedMethods(PaypalBTransaction $transaction): array
    {
        $methods = data_get($transaction->bridge_request, 'allowed_methods', ['wallet']);
        if (! is_array($methods)) {
            return ['wallet'];
        }

        $methods = array_values(array_unique(array_filter(array_map(
            static fn (mixed $method): string => strtolower(trim((string) $method)),
            $methods
        ), static fn (string $method): bool => in_array($method, ['wallet', 'card'], true))));

        return $methods ?: ['wallet'];
    }

    private function assertActive(PaypalBTransaction $transaction): void
    {
        if ($transaction->status === 'reconciling' || $transaction->failure_class === 'provider_unknown') {
            throw ValidationException::withMessages([
                'payment' => trans('PaypalB::common.session_reconciling'),
            ]);
        }
        if ($transaction->hasPendingCapture()) {
            // 捕获已发生，只等 PayPal 确认；有效期对它不再有意义。
            return;
        }
        if ($transaction->expires_at && $transaction->expires_at->isPast() && $transaction->status !== 'completed') {
            $transaction->forceFill(['status' => 'expired'])->saveOrFail();
            app(PaypalBShopOrderService::class)->cancelFromTransaction($transaction->fresh(), 'expired');

            throw ValidationException::withMessages(['payment' => trans('PaypalB::common.session_expired')]);
        }
    }

    private function approvalUrl(array $provider): string
    {
        foreach ((array) ($provider['links'] ?? []) as $link) {
            if (($link['rel'] ?? '') === 'approve') {
                return trim((string) ($link['href'] ?? ''));
            }
        }

        return '';
    }

    private function isSecureUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! empty($parts['host'])
            && empty($parts['user'])
            && empty($parts['pass']);
    }

    private function captureData(array $provider): array
    {
        $capture = data_get($provider, 'purchase_units.0.payments.captures.0');
        if (! is_array($capture)) {
            throw new \RuntimeException('PayPal 捕获响应缺少收款明细。');
        }

        return $capture;
    }

    private function assertCaptureMatchesTransaction(PaypalBTransaction $transaction, array $capture): void
    {
        $captureId = trim((string) ($capture['id'] ?? ''));
        if ($captureId === '') {
            throw new \RuntimeException('PayPal 捕获响应缺少交易号。');
        }

        $amount = (array) ($capture['amount'] ?? []);
        if (! isset($amount['value'], $amount['currency_code'])
            || strtoupper((string) $amount['currency_code']) !== strtoupper((string) $transaction->currency)
            || ! PaypalBMoney::equal($transaction->amount, $amount['value'], (string) $transaction->currency)) {
            throw new \RuntimeException('PayPal 捕获金额或币种与跨站订单不一致。');
        }
    }

    /**
     * 读取 PayPal 订单上已有的捕获，恢复 capture 响应丢失、重复回跳或 Webhook 先到的幂等场景；
     * 捕获仍待确认时同样返回，由调用方按状态处理。没有捕获或查询失败返回 null。
     */
    private function recoverCapture(PaypalBTransaction $transaction, PaypalBApiClient $client): ?array
    {
        try {
            $provider = $client->getOrder((string) $transaction->paypal_order_id);
            $capture  = $this->captureData($provider);
            if (trim((string) ($capture['id'] ?? '')) === '') {
                return null;
            }

            return ['provider' => $provider, 'capture' => $capture];
        } catch (\Throwable) {
            return null;
        }
    }

    private function markCompleted(PaypalBTransaction $transaction, array $provider, string $providerId): bool
    {
        $changed = DB::transaction(function () use ($transaction, $provider, $providerId): bool {
            $locked = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->status === 'completed') {
                if ($locked->paypal_capture_id && ! hash_equals((string) $locked->paypal_capture_id, $providerId)) {
                    throw ValidationException::withMessages(['payment' => '同一支付会话出现了不同的 PayPal 捕获号。']);
                }

                return false;
            }
            // 捕获早已在有效期内发生、只是资金待确认的会话，PayPal 几天后才完成也必须入账。
            $completingRecordedCapture = $locked->paypal_capture_id
                && hash_equals((string) $locked->paypal_capture_id, $providerId);
            if (! $completingRecordedCapture
                && ($locked->status === 'expired'
                || ($locked->expires_at && $locked->expires_at->isPast()))) {
                throw ValidationException::withMessages(['payment' => '支付会话已过期，不能再确认收款。']);
            }
            if (PaypalBTransaction::query()
                ->where('paypal_capture_id', $providerId)
                ->where('id', '<>', $locked->id)
                ->exists()) {
                throw ValidationException::withMessages(['payment' => '该 PayPal 捕获号已经绑定到其他支付会话。']);
            }
            $locked->forceFill([
                'status'                    => 'completed',
                'paypal_capture_id'         => $providerId,
                'provider_status'           => 'COMPLETED',
                'provider_payload'          => $provider,
                'payer_email_hash'          => $this->payerEmailHash($locked, $provider),
                'failure_retryable'         => null,
                'failure_class'             => null,
                'payment_exception_status'  => null,
                'payment_exception_payload' => null,
                // 自营订单没有 A 站可通知，回调状态直接标记为无需发送。
                'callback_status'           => $locked->isLocal() ? 'skipped' : 'pending',
                'callback_next_attempt_at'  => $locked->isLocal() ? null : now(),
                'callback_last_error'       => null,
                'paid_at'                   => now(),
            ])->saveOrFail();

            return true;
        });

        if ($changed) {
            $transaction->refresh();

            if ($transaction->isLocal()) {
                // 自营订单已有真实订单记录，推进本站订单状态即可，既不回调也不建影子单。
                app(PaypalBLocalOrderService::class)->markPaid($transaction);

                return $changed;
            }

            app(PaypalBCallbackService::class)->queue($transaction);
            // 收款确认后才生成本站订单；失败只记日志，不影响已到账的款项。
            app(PaypalBShopOrderService::class)->createFromTransaction($transaction);
        }

        return $changed;
    }
}
