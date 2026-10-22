<?php

namespace Plugin\PaypalB\Services;

use Beike\Models\Order;
use Beike\Services\StateMachineService;
use Beike\Shop\Services\PaymentService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugin\PaypalB\Models\PaypalBTransaction;

/**
 * B 站自营订单的 PayPal 支付入口。
 *
 * 与跨站网关共用账号池、Orders API、Webhook 和退款逻辑，但不经过 S2S 握手：
 * 订单本来就在 B 站，没有 A 站可回调，也不需要生成影子订单。
 */
class PaypalBLocalPaymentService extends PaymentService
{
    public const CODE = 'paypal_b';

    /**
     * 仍可能推进到付款的会话状态；其余状态要么已终结，要么已收款。
     */
    private const ACTIVE_STATUSES = ['creating', 'pending', 'reconciling'];

    public function __construct($order)
    {
        parent::__construct($order);

        if ($this->paymentMethodCode !== self::CODE) {
            throw new \LogicException('订单支付方式不是 PayPal。');
        }
    }

    public function instruction(array $setting = []): array
    {
        $configuration = PaypalBConfiguration::all();
        $transaction   = $this->reserveTransaction($configuration);
        if (! $transaction->paypal_order_id) {
            // 创建结果尚未确认时不能渲染按钮：没有 PayPal 订单号，SDK 会直接报错，买家却看不到原因。
            throw ValidationException::withMessages([
                'payment' => trans('PaypalB::common.session_reconciling'),
            ]);
        }
        $transaction->loadMissing('account');
        $transactionService = app(PaypalBTransactionService::class);
        $allowedMethods     = $transactionService->allowedMethods($transaction);

        return [
            'order_number'      => (string) $this->order->number,
            'amount'            => PaypalBMoney::normalize($this->order->total, (string) $this->order->currency_code),
            'currency'          => strtoupper((string) $this->order->currency_code),
            'checkout_url'      => $transactionService->checkoutUrl($transaction),
            'expires_in'        => $configuration['payment_expiration_minutes'] ?? 30,
            // 本地订单直接在商城 payment 页面加载 PayPal Buttons，不再跳转 B 站 checkout。
            'paypal_order_id'   => (string) $transaction->paypal_order_id,
            'client_id'         => (string) $transaction->account?->client_id,
            'capture_url'       => route('shop.paypal_b.capture', ['token' => $transaction->public_token], false),
            'status_url'        => route('shop.paypal_b.status', ['token' => $transaction->public_token], false),
            'capture_token'     => $transactionService->captureToken($transaction),
            'return_url'        => route('shop.paypal_b.local_return', ['token' => $transaction->public_token], false),
            'paypal_locale'     => PaypalBLocale::paypalLocale((string) locale()),
            // 会话绑定的账号不支持钱包时只提供托管页的信用卡入口；两者都没有时 createLocal 已拒绝建单。
            'wallet_enabled'    => in_array('wallet', $allowedMethods, true),
            'card_checkout_url' => in_array('card', $allowedMethods, true)
                ? $transactionService->checkoutUrl($transaction, 'card')
                : null,
        ];
    }

    public function mobilePaymentData(array $setting = []): array
    {
        $instruction = $this->instruction($setting);

        return [
            'payment_type' => self::CODE,
            'status'       => 'requires_redirect',
            'checkout_url' => $instruction['checkout_url'],
            'order_number' => $instruction['order_number'],
        ];
    }

    /**
     * 同一订单同一时间只允许一个会话：与跨站路径一样用 database cache 锁串行化。
     * 数据库事务只覆盖订单状态校验、过期清理和会话复用；PayPal 网络请求在事务和行锁之外执行，
     * 否则一次 PayPal 慢响应会一直持有订单行、轮询指针和全部账号行的锁，拖住其他所有付款。
     */
    private function reserveTransaction(array $configuration): PaypalBTransaction
    {
        try {
            return Cache::store('database')
                ->lock('paypal_b:create:local_order:' . (int) $this->order->id, 600)
                ->block(15, fn (): PaypalBTransaction => $this->reserveTransactionLocked($configuration));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'payment' => trans('PaypalB::common.session_processing'),
            ]);
        }
    }

    private function reserveTransactionLocked(array $configuration): PaypalBTransaction
    {
        $orderId = (int) $this->order->id;
        $state   = DB::transaction(function () use ($orderId): array {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status !== StateMachineService::UNPAID) {
                throw ValidationException::withMessages([
                    'order' => trans('PaypalB::common.local_order_not_payable'),
                ]);
            }

            // 已捕获待确认的会话：买家已付款，只等 PayPal 给出结果，绝不能再建会话让买家付第二次。
            if (PaypalBTransaction::hasLocalPendingCaptureForOrder((int) $order->id)) {
                throw ValidationException::withMessages([
                    'payment' => trans('PaypalB::common.session_capture_pending'),
                ]);
            }

            // 过期的待对账会话可以安全废弃：它从未拿到授权地址，买家不可能已经在 PayPal 付款；
            // 跨站会话不能这样处理，因为 A 站可能仍会按原引用重试。
            PaypalBTransaction::query()
                ->where('source', PaypalBTransaction::SOURCE_LOCAL)
                ->where('shop_order_id', $order->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->whereNull('paypal_capture_id')
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired', 'updated_at' => now()]);

            $active = PaypalBTransaction::query()
                ->where('source', PaypalBTransaction::SOURCE_LOCAL)
                ->where('shop_order_id', $order->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first();

            return ['order' => $order, 'active' => $active];
        });

        $service = app(PaypalBTransactionService::class);
        $active  = $state['active'];
        if ($active instanceof PaypalBTransaction) {
            // 已取得 PayPal 订单号的会话直接复用；创建结果未知的沿用原账号和请求号重试。
            return $active->paypal_order_id ? $active : $service->retryLocal($active);
        }

        return $service->createLocal($state['order'], $this->localPayload($state['order'], $configuration));
    }

    /**
     * 把 B 站真实订单转成网关所需的快照结构，字段含义与跨站请求保持一致。
     */
    private function localPayload(Order $order, array $configuration): array
    {
        $order->loadMissing(['orderProducts', 'orderTotals']);
        $currency = strtoupper((string) $order->currency_code);

        $items = [];
        foreach ($order->orderProducts as $product) {
            $items[] = [
                'source_product_id' => (int) $product->product_id,
                'name'              => (string) $product->name,
                'sku'               => (string) $product->product_sku,
                'quantity'          => (int) $product->quantity,
                'unit_price'        => PaypalBMoney::normalize($product->price, $currency),
                'line_total'        => PaypalBMoney::multiply($product->price, (int) $product->quantity, $currency),
            ];
        }

        $totals = [];
        foreach ($order->orderTotals as $total) {
            $totals[] = [
                'code'  => (string) $total->code,
                'title' => (string) $total->title,
                'value' => $this->signedNormalize($total->value, $currency),
            ];
        }

        return [
            'reference'    => (string) Str::uuid(),
            'order_number' => (string) $order->number,
            'amount'       => PaypalBMoney::normalize($order->total, $currency),
            'currency'     => $currency,
            'expires_at'   => now()->addMinutes((int) ($configuration['payment_expiration_minutes'] ?? 30)),
            'buyer_locale' => locale(),
            'buyer'        => [
                'name'            => (string) $order->customer_name,
                'email'           => (string) $order->email,
                'calling_code'    => (string) $order->calling_code,
                'telephone'       => (string) $order->telephone,
                'ip'              => (string) ($order->ip ?: request()->getClientIp()),
                'user_agent'      => (string) ($order->user_agent ?: request()->userAgent()),
                'comment'         => (string) $order->comment,
                'billing_address' => [
                    'address_1'    => (string) $order->payment_address_1,
                    'address_2'    => (string) $order->payment_address_2,
                    'city'         => (string) $order->payment_city,
                    'zone'         => (string) $order->payment_zone,
                    'postal_code'  => (string) $order->payment_zipcode,
                    'country_code' => $this->countryCode((int) $order->payment_country_id),
                ],
            ],
            'order_items'      => $items,
            'order_totals'     => $totals,
            'shipping_address' => [
                'required'     => (bool) $order->shipping_method_code,
                'name'         => (string) $order->shipping_customer_name,
                'calling_code' => (string) $order->shipping_calling_code,
                'telephone'    => (string) $order->shipping_telephone,
                'address_1'    => (string) $order->shipping_address_1,
                'address_2'    => (string) $order->shipping_address_2,
                'city'         => (string) $order->shipping_city,
                'zone'         => (string) $order->shipping_zone,
                'postal_code'  => (string) $order->shipping_zipcode,
                'country'      => (string) $order->shipping_country,
                'country_code' => $this->countryCode((int) $order->shipping_country_id),
            ],
        ];
    }

    /**
     * 订单费用行可以是负数（折扣），而 PaypalBMoney::normalize 只接受非负值。
     */
    private function signedNormalize(mixed $value, string $currency): string
    {
        $value    = trim((string) $value);
        $negative = str_starts_with($value, '-');

        return ($negative ? '-' : '') . PaypalBMoney::normalize(
            $negative ? ltrim($value, '-') : $value,
            $currency,
            'order_totals.value'
        );
    }

    private function countryCode(int $countryId): string
    {
        if ($countryId <= 0) {
            return '';
        }

        try {
            return strtoupper((string) \Beike\Models\Country::query()->whereKey($countryId)->value('code'));
        } catch (\Throwable) {
            return '';
        }
    }
}
