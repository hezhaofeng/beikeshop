<?php

namespace Plugin\PaypalB\Services;

use Beike\Models\Order;
use Beike\Services\StateMachineService;
use Beike\Shop\Services\PaymentService;
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

        return [
            'order_number' => (string) $this->order->number,
            'amount'       => PaypalBMoney::normalize($this->order->total, (string) $this->order->currency_code),
            'currency'     => strtoupper((string) $this->order->currency_code),
            'checkout_url' => app(PaypalBTransactionService::class)->checkoutUrl($transaction),
            'expires_in'   => $configuration['payment_expiration_minutes'] ?? 30,
        ];
    }

    public function mobilePaymentData(array $setting = []): array
    {
        $instruction = $this->instruction($setting);

        return [
            'payment_type'  => self::CODE,
            'status'        => 'requires_redirect',
            'checkout_url'  => $instruction['checkout_url'],
            'order_number'  => $instruction['order_number'],
        ];
    }

    /**
     * 复用未过期的会话，避免重复点击在 PayPal 侧产生第二个订单。
     */
    private function reserveTransaction(array $configuration): PaypalBTransaction
    {
        $order = $this->order;

        return DB::transaction(function () use ($order, $configuration): PaypalBTransaction {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== StateMachineService::UNPAID) {
                throw ValidationException::withMessages([
                    'order' => trans('PaypalB::common.local_order_not_payable'),
                ]);
            }

            $active = PaypalBTransaction::query()
                ->where('source', PaypalBTransaction::SOURCE_LOCAL)
                ->where('order_number', (string) $locked->number)
                ->whereIn('status', ['creating', 'pending'])
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($active) {
                return $active;
            }

            PaypalBTransaction::query()
                ->where('source', PaypalBTransaction::SOURCE_LOCAL)
                ->where('order_number', (string) $locked->number)
                ->whereIn('status', ['creating', 'pending'])
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired', 'updated_at' => now()]);

            return app(PaypalBTransactionService::class)->createLocal(
                $locked,
                $this->localPayload($locked, $configuration)
            );
        });
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
