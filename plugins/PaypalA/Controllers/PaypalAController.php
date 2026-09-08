<?php

namespace Plugin\PaypalA\Controllers;

use Beike\Models\Order;
use Beike\Repositories\OrderPaymentRepo;
use Beike\Repositories\OrderRepo;
use Beike\Services\StateMachineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Plugin\PaypalA\Models\PaypalATransaction;
use Plugin\PaypalA\Services\BridgeSignature;
use Plugin\PaypalA\Services\BridgeUrl;
use Plugin\PaypalA\Services\PaypalABridgeClient;
use Plugin\PaypalA\Services\PaypalAMoney;
use Plugin\PaypalA\Services\PaypalAPaymentService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PaypalAController
{
    /**
     * A 站创建一次性会话后顶层跳转 B 站，由 B 站承担完整支付页面和收款责任。
     */
    public function start(Request $request, string $number): RedirectResponse|View
    {
        try {
            $order         = $this->resolveShopOrder($request, $number);
            $configuration = PaypalAPaymentService::bridgeConfiguration(plugin_setting(PaypalAPaymentService::CODE, []));
            $method        = strtolower(trim((string) $request->input('method', 'wallet')));
            PaypalAPaymentService::assertPaymentMethodEnabled($method, $configuration);
            PaypalAPaymentService::assertPayableOrder($order);
            $transaction = $this->reserveTransaction($order, $configuration['payment_expiration_minutes'], $method);

            $gateway = new PaypalABridgeClient($configuration);
            // 复用本地缓存的 B 站响应时不会重新询问 B 站，排查终态问题必须区分这两条来源。
            $reusedResponse = $transaction->status !== 'reconciling'
                && is_array($transaction->b_response)
                && ! empty($transaction->b_response['checkout_url']);
            $response = $reusedResponse
                ? $transaction->b_response
                : $gateway->createSession($transaction, $order);

            $transaction = DB::transaction(function () use ($transaction, $response): PaypalATransaction {
                $locked = PaypalATransaction::query()->lockForUpdate()->findOrFail($transaction->id);
                $this->assertBridgeResponseMatchesTransaction($locked, $response);

                $locked->fill([
                    'b_transaction_id' => (string) $response['transaction_id'],
                    'status'           => $this->normalizeRemoteStatus((string) ($response['status'] ?? 'pending')),
                    'b_response'       => $response,
                ]);
                $locked->saveOrFail();

                return $locked;
            });

            if (in_array($transaction->status, ['failed', 'cancelled', 'expired'], true)) {
                // 三种终态原因完全不同，必须让运维和买家看到具体是哪一种。
                Log::warning('PaypalA 收到 B 站终态会话，无法跳转。', [
                    'order_number'     => $number,
                    'reference'        => $transaction->reference,
                    'b_transaction_id' => $transaction->b_transaction_id,
                    'remote_status'    => $transaction->status,
                    'reused_response'  => $reusedResponse,
                    'expires_at'       => $transaction->expires_at?->toIso8601String(),
                ]);

                throw ValidationException::withMessages([
                    'payment' => match ($transaction->status) {
                        'expired'   => trans('PaypalA::common.session_expired'),
                        'cancelled' => trans('PaypalA::common.session_cancelled'),
                        default     => trans('PaypalA::common.session_failed'),
                    },
                ]);
            }
            if ($transaction->status === 'reconciling') {
                throw ValidationException::withMessages([
                    'payment' => trans('PaypalA::common.session_reconciling'),
                ]);
            }
            $remoteMethods = (array) ($response['allowed_methods'] ?? ['wallet']);
            if (! in_array($method, $remoteMethods, true)) {
                throw ValidationException::withMessages(['payment' => trans('PaypalA::common.method_locked')]);
            }

            $checkoutUrl = trim((string) data_get($transaction->b_response, 'checkout_url'));
            if (! filter_var($checkoutUrl, FILTER_VALIDATE_URL)) {
                throw ValidationException::withMessages(['payment' => trans('PaypalA::common.checkout_url_invalid')]);
            }
            if (! BridgeUrl::sameOrigin($checkoutUrl, $configuration['b_site_url'])) {
                // 两站各自配置一次站点地址，协议、域名或端口不一致时会在此静默拦截跳转。
                Log::warning('PaypalA 拒绝跳转：B 站支付地址与本地配置的 B 站地址不同源。', [
                    'order_number'          => $number,
                    'checkout_url_origin'   => $this->urlOrigin($checkoutUrl),
                    'configured_b_site_url' => $this->urlOrigin($configuration['b_site_url']),
                ]);

                throw ValidationException::withMessages([
                    'payment' => trans('PaypalA::common.checkout_url_invalid'),
                ]);
            }

            $response = redirect()->away($this->checkoutUrlWithMethod($checkoutUrl, $method));
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            Log::warning('PaypalA 发起支付被拒绝。', [
                'order_number' => $number,
                'errors'       => $exception->errors(),
            ]);

            return redirect(shop_route('orders.pay', ['number' => $number], false))
                ->withErrors($exception->errors());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect(shop_route('orders.pay', ['number' => $number], false))
                ->withErrors(trans('PaypalA::common.start_failed'));
        }
    }

    /**
     * B 站浏览器回跳后主动查询状态。真正入账仍需 B 站签名结果。
     */
    public function returned(Request $request): RedirectResponse
    {
        $reference   = trim((string) $request->query('reference', ''));
        $transaction = PaypalATransaction::query()->where('reference', $reference)->first();
        if (! $transaction) {
            abort(404);
        }

        try {
            if ($transaction->b_transaction_id && $transaction->status !== 'completed') {
                $configuration = PaypalAPaymentService::bridgeConfiguration(plugin_setting(PaypalAPaymentService::CODE, []));
                $payload       = (new PaypalABridgeClient($configuration))->transactionStatus($transaction);
                $this->applyBridgeResult($payload, false);
                $transaction->refresh();
            }
        } catch (\Throwable $exception) {
            return redirect(shop_route('orders.pay', ['number' => $this->orderNumber($transaction)], false))
                ->withErrors(trans('PaypalA::common.result_confirming'));
        }

        if ($transaction->status === 'completed') {
            $response = redirect(shop_route('checkout.success', ['order_number' => $this->orderNumber($transaction)], false));
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        }

        $response = redirect(shop_route('orders.pay', ['number' => $this->orderNumber($transaction)], false))
            ->withErrors($this->paymentStatusMessage($transaction->status));
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * B 站仅在完成、取消、过期等最终或中间状态时调用；签名验证不信任浏览器参数。
     */
    public function callback(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            $payload = $request->all();
        }
        if (! is_array($payload)) {
            return json_fail('无效的支付回调数据。', [], 422);
        }

        try {
            $configuration = PaypalAPaymentService::bridgeConfiguration(plugin_setting(PaypalAPaymentService::CODE, []));
            BridgeSignature::verify(
                $payload,
                (string) $request->header(BridgeSignature::HEADER_TIMESTAMP, ''),
                (string) $request->header(BridgeSignature::HEADER_NONCE, ''),
                (string) $request->header(BridgeSignature::HEADER_SIGNATURE, ''),
                $configuration['callback_verification_secret'],
                'paypal_a:b_callback'
            );

            $this->applyBridgeResult($payload, true);

            return json_success('支付状态已同步。');
        } catch (ValidationException $exception) {
            return json_fail($exception->getMessage(), ['errors' => $exception->errors()], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return json_fail('支付状态同步失败。', [], 500);
        }
    }

    /**
     * 锁定本地订单与 A 侧会话。不同支付尝试保留不同引用，防止迟到回调覆盖新尝试。
     */
    private function reserveTransaction(Order $order, int $expirationMinutes, string $method): PaypalATransaction
    {
        return DB::transaction(function () use ($order, $expirationMinutes, $method): PaypalATransaction {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            PaypalAPaymentService::assertPayableOrder($lockedOrder);

            $active = PaypalATransaction::query()
                ->where('order_id', $lockedOrder->id)
                ->where(function ($query): void {
                    $query->where(function ($query): void {
                        $query->whereIn('status', ['initiating', 'pending'])
                            ->where('expires_at', '>', now());
                    })->orWhere('status', 'reconciling');
                })
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($active) {
                if (! $active->payment_method) {
                    $active->payment_method = $method;
                    $active->saveOrFail();
                }

                return $active;
            }

            PaypalATransaction::query()
                ->where('order_id', $lockedOrder->id)
                ->whereIn('status', ['initiating', 'pending'])
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired', 'updated_at' => now()]);

            return PaypalATransaction::query()->create([
                'order_id'       => $lockedOrder->id,
                'reference'      => (string) Str::uuid(),
                'amount'         => PaypalAMoney::normalize($lockedOrder->total, (string) $lockedOrder->currency_code),
                'currency'       => strtoupper((string) $lockedOrder->currency_code),
                'payment_method' => $method,
                'status'         => 'initiating',
                'expires_at'     => now()->addMinutes($expirationMinutes),
            ]);
        });
    }

    /**
     * 将 B 站签名状态转换为 A 站订单状态；完整性检查必须发生在状态机前。
     */
    private function applyBridgeResult(array $payload, bool $isCallback): void
    {
        $this->validateBridgePayload($payload);

        DB::transaction(function () use ($payload, $isCallback): void {
            $transaction = PaypalATransaction::query()
                ->where('reference', $payload['reference'])
                ->lockForUpdate()
                ->firstOrFail();
            $order = Order::query()->lockForUpdate()->findOrFail($transaction->order_id);

            $this->assertBridgePayloadMatchesTransaction($transaction, $order, $payload);
            $status        = $this->normalizeRemoteStatus((string) $payload['status']);
            $refund        = $this->refundPayload($payload, (string) $transaction->currency);
            $fullRefund    = $refund['status'] === 'completed';
            $refundChanged = (string) ($transaction->refund_status ?: 'none') !== $refund['status']
                || ! PaypalAMoney::equal(
                    $transaction->refunded_amount ?: '0',
                    $refund['amount'],
                    (string) $transaction->currency
                );
            $disputeChanged = (string) ($transaction->dispute_status ?: '') !== (string) ($payload['dispute_status'] ?? '');

            if ($fullRefund && $status !== 'cancelled') {
                throw ValidationException::withMessages(['refund_status' => '完整退款回调必须使用 cancelled 状态。']);
            }
            if ($fullRefund && ! PaypalAMoney::equal(
                $transaction->amount,
                $refund['amount'],
                (string) $transaction->currency
            )) {
                throw ValidationException::withMessages(['refunded_amount' => '完整退款金额必须等于原支付金额。']);
            }

            // 已支付会话的迟到取消只有在签名回调明确证明完整退款时才可生效。
            $statusChanged = $this->shouldApplyRemoteStatus((string) $transaction->status, $status)
                || ($fullRefund && $status === 'cancelled' && $transaction->status === 'completed');
            if (! $statusChanged && ! $refundChanged && ! $disputeChanged) {
                return;
            }

            $transaction->fill([
                'status'           => $statusChanged ? $status : $transaction->status,
                'b_response'       => $payload,
                'callback_payload' => $isCallback ? $payload : $transaction->callback_payload,
                'refunded_amount'  => $refund['amount'],
                'refund_status'    => $refund['status'],
                'dispute_status'   => $payload['dispute_status'] ?? $transaction->dispute_status,
            ]);

            if ($fullRefund) {
                $transaction->saveOrFail();
                $this->applyFullRefundToOrder($order);

                return;
            }
            if (! $statusChanged || $status !== 'completed') {
                $transaction->saveOrFail();

                return;
            }

            $transaction->paid_at = now();
            $transaction->saveOrFail();

            $paymentAuditData = $this->paymentAuditData($transaction, $payload);
            if ($order->status === StateMachineService::UNPAID) {
                PaypalAPaymentService::assertPayableOrder($order);
                StateMachineService::getInstance($order)
                    ->setPayment($paymentAuditData)
                    ->changeStatus(StateMachineService::PAID, 'PayPal B 站已确认收款。');
            } elseif ($order->status === StateMachineService::CANCELLED) {
                // 订单不能自动恢复为已支付，但已到账款项必须写入订单支付审计供后台处理。
                OrderPaymentRepo::createOrUpdatePayment($order->id, $paymentAuditData);
            }
        });
    }

    private function applyFullRefundToOrder(Order $order): void
    {
        if ($order->status === StateMachineService::PAID) {
            StateMachineService::getInstance($order)
                ->changeStatus(StateMachineService::REFUNDING, 'PayPal B 站已确认全额退款。');
            StateMachineService::getInstance($order)
                ->changeStatus(StateMachineService::CANCELLED, 'PayPal B 站已确认全额退款。');

            return;
        }
        if ($order->status === StateMachineService::REFUNDING) {
            StateMachineService::getInstance($order)
                ->changeStatus(StateMachineService::CANCELLED, 'PayPal B 站已确认全额退款。');

            return;
        }
        if ($order->status === StateMachineService::CANCELLED) {
            return;
        }

        throw ValidationException::withMessages([
            'refund_status' => '订单当前状态不能自动执行全额退款桥接，请由后台处理订单状态。',
        ]);
    }

    private function paymentAuditData(PaypalATransaction $transaction, array $payload): array
    {
        $providerTransactionId = trim((string) ($payload['provider_transaction_id'] ?? ''));

        return [
            'transaction_id' => $providerTransactionId !== '' ? $providerTransactionId : (string) $transaction->b_transaction_id,
            'request'        => [
                'channel'          => PaypalAPaymentService::CODE,
                'source'           => 'paypal_b_bridge',
                'reference'        => $transaction->reference,
                'b_transaction_id' => $transaction->b_transaction_id,
                'expected_amount'  => PaypalAMoney::normalize($transaction->amount, (string) $transaction->currency),
                'currency'         => $transaction->currency,
            ],
            'response'       => $payload,
            'callback'       => $payload,
        ];
    }

    private function validateBridgePayload(array $payload): void
    {
        $required = ['transaction_id', 'reference', 'order_number', 'amount', 'currency', 'status'];
        foreach ($required as $field) {
            if (! isset($payload[$field]) || trim((string) $payload[$field]) === '') {
                throw ValidationException::withMessages([$field => 'B 站返回的支付状态缺少必要字段。']);
            }
        }

        if (! in_array((string) $payload['status'], ['pending', 'awaiting_manual_confirmation', 'reconciling', 'completed', 'cancelled', 'failed', 'expired'], true)) {
            throw ValidationException::withMessages(['status' => 'B 站返回了未知支付状态。']);
        }

        if ((string) $payload['status'] === 'completed' && trim((string) ($payload['provider_transaction_id'] ?? '')) === '') {
            throw ValidationException::withMessages(['provider_transaction_id' => '已完成支付缺少 PayPal 或人工核验交易号。']);
        }

        $refundStatus = strtolower(trim((string) ($payload['refund_status'] ?? 'none')));
        if (! in_array($refundStatus, ['none', 'processing', 'partial', 'completed', 'failed', 'reconciling'], true)) {
            throw ValidationException::withMessages(['refund_status' => 'B 站返回了未知退款状态。']);
        }
        if (isset($payload['refunded_amount'])
            && trim((string) $payload['refunded_amount']) === '') {
            throw ValidationException::withMessages(['refunded_amount' => 'B 站退款累计额格式无效。']);
        }
    }

    private function assertBridgePayloadMatchesTransaction(PaypalATransaction $transaction, Order $order, array $payload): void
    {
        if ($transaction->b_transaction_id && ! hash_equals((string) $transaction->b_transaction_id, (string) $payload['transaction_id'])) {
            throw ValidationException::withMessages(['transaction_id' => 'B 站交易号与本地会话不一致。']);
        }
        if (! $transaction->b_transaction_id) {
            $transaction->b_transaction_id = (string) $payload['transaction_id'];
        }
        if (! hash_equals((string) $order->number, (string) $payload['order_number'])) {
            throw ValidationException::withMessages(['order_number' => 'B 站订单号与本地订单不一致。']);
        }
        if (! hash_equals(strtoupper((string) $transaction->currency), strtoupper((string) $payload['currency']))) {
            throw ValidationException::withMessages(['currency' => 'B 站支付币种与本地订单不一致。']);
        }
        if (! PaypalAMoney::equal($transaction->amount, $payload['amount'], (string) $transaction->currency)) {
            throw ValidationException::withMessages(['amount' => 'B 站支付金额与本地订单不一致。']);
        }
    }

    private function refundPayload(array $payload, string $currency): array
    {
        $status = strtolower(trim((string) ($payload['refund_status'] ?? 'none')));
        $amount = PaypalAMoney::normalize($payload['refunded_amount'] ?? '0', $currency, 'refunded_amount');

        return ['status' => $status, 'amount' => $amount];
    }

    private function assertBridgeResponseMatchesTransaction(PaypalATransaction $transaction, array $response): void
    {
        if (empty($response['transaction_id']) || ($response['reference'] ?? null) !== $transaction->reference) {
            throw ValidationException::withMessages(['payment' => 'B 站创建的支付会话与本地引用不一致。']);
        }
        if (! empty($transaction->b_transaction_id)
            && ! hash_equals((string) $transaction->b_transaction_id, (string) $response['transaction_id'])) {
            throw ValidationException::withMessages(['payment' => 'B 站支付会话被意外替换。']);
        }
        if (! hash_equals(strtoupper((string) $transaction->currency), strtoupper((string) ($response['currency'] ?? '')))) {
            throw ValidationException::withMessages(['payment' => 'B 站支付币种与本地会话不一致。']);
        }
        if (! PaypalAMoney::equal($transaction->amount, $response['amount'] ?? '', (string) $transaction->currency)) {
            throw ValidationException::withMessages(['payment' => 'B 站支付金额与本地会话不一致。']);
        }
    }

    private function normalizeRemoteStatus(string $status): string
    {
        return match ($status) {
            'awaiting_manual_confirmation' => 'pending',
            default                        => $status,
        };
    }

    private function shouldApplyRemoteStatus(string $current, string $incoming): bool
    {
        if ($current === 'completed') {
            return false;
        }
        if ($current === 'expired') {
            return false;
        }
        if ($incoming === 'completed') {
            // PayPal 可能先返回失败/过期状态，之后由 Capture 或 Webhook 确认到账。
            return true;
        }

        // 失败、取消和过期都是当前支付引用的终态；迟到的 pending 不得重新打开会话。
        if (in_array($current, ['failed', 'cancelled', 'expired'], true)) {
            return false;
        }

        // 已有可付款会话或已完成创建的中间状态，不接受更弱的 reconciling 回退。
        if ($incoming === 'reconciling' && $current === 'pending') {
            return false;
        }

        return true;
    }

    private function resolveShopOrder(Request $request, string $number): Order
    {
        $customer = current_customer();
        $order    = OrderRepo::getOrderByNumber($number, $customer);
        if (! $order) {
            abort(404);
        }

        if (! $customer && ! $this->isGuestOrderAuthorized($order, $number, (string) $request->input('email', ''))) {
            abort(404);
        }

        return $order;
    }

    private function isGuestOrderAuthorized(Order $order, string $number, string $email): bool
    {
        if ($order->customer_id) {
            return false;
        }

        if (in_array($number, array_map('strval', session('guest_order_numbers', [])), true)) {
            return true;
        }

        return $email !== '' && hash_equals((string) $order->email, trim($email));
    }

    private function orderNumber(PaypalATransaction $transaction): string
    {
        return (string) Order::query()->whereKey($transaction->order_id)->value('number');
    }

    /**
     * 只输出协议、域名和端口，便于排查配置差异且不泄露一次性支付令牌。
     */
    private function urlOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '(无效地址)';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return $scheme . '://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    private function checkoutUrlWithMethod(string $url, string $method): string    {
        $query = http_build_query([
            'method' => $method,
        ], '', '&', PHP_QUERY_RFC3986);

        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    private function paymentStatusMessage(string $status): string
    {
        return match ($status) {
            'cancelled'   => trans('PaypalA::common.status_cancelled'),
            'failed'      => trans('PaypalA::common.status_failed'),
            'expired'     => trans('PaypalA::common.status_expired'),
            'reconciling' => trans('PaypalA::common.status_reconciling'),
            default       => trans('PaypalA::common.status_pending'),
        };
    }
}
