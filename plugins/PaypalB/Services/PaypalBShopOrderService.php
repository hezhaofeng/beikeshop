<?php

namespace Plugin\PaypalB\Services;

use Beike\Models\Country;
use Beike\Models\Order;
use Beike\Models\OrderHistory;
use Beike\Models\OrderPayment;
use Beike\Models\OrderProduct;
use Beike\Models\OrderTotal;
use Beike\Repositories\CurrencyRepo;
use Beike\Services\StateMachineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\PaypalB\Models\PaypalBTransaction;

/**
 * 把 B 站收到的 PayPal 款项落成一条本站订单，供后台在订单模块直接查看。
 *
 * B 站没有真实商品和库存，因此订单直接以已支付状态写入，不经过 StateMachineService：
 * unpaid → paid 会触发 subStock、updateSales 和 notifyUpdateOrder，
 * 在 B 站分别意味着扣减不存在商品的库存、污染销量统计，以及向买家重复发送邮件。
 */
class PaypalBShopOrderService
{
    public const PAYMENT_METHOD_CODE = 'paypal_b';

    /**
     * 仅在收款成功后调用。返回订单 ID；已建过或建单失败都不会影响收款结果。
     */
    public function createFromTransaction(PaypalBTransaction $transaction): ?int
    {
        if ((string) $transaction->status !== 'completed') {
            return null;
        }
        // 自营订单本来就有真实订单记录，绝不能再生成一条影子订单。
        if ($transaction->isLocal()) {
            return null;
        }
        if ($transaction->shop_order_id) {
            return (int) $transaction->shop_order_id;
        }

        try {
            return DB::transaction(function () use ($transaction): ?int {
                $locked = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
                if ($locked->shop_order_id) {
                    return (int) $locked->shop_order_id;
                }

                // 复用必须限定在此前由桥接交易建过的镜像订单上。
                // 两站订单号都是 OrderRepo::generateOrderNumber() 的 Ymd + 5 位随机数，
                // 且各自只查本库，撞号无法避免；按 orders.number 直接复用会把跨站收款
                // 挂到一条无关的本站自营订单上，而且不报错。
                // 前提：一个 B 站只服务一个 A 站（a_site_url 是单值配置），故订单号在桥接侧唯一。
                $mirrorOrderId = PaypalBTransaction::query()
                    ->where('order_number', (string) $locked->order_number)
                    ->where('source', '<>', PaypalBTransaction::SOURCE_LOCAL)
                    ->whereNotNull('shop_order_id')
                    ->value('shop_order_id');

                $order = ($mirrorOrderId ? Order::query()->find($mirrorOrderId) : null)
                    ?: $this->buildOrder($locked);

                $locked->forceFill(['shop_order_id' => $order->id])->saveOrFail();

                return (int) $order->id;
            });
        } catch (\Throwable $exception) {
            // 订单只是后台可视化的副产物，绝不能因为建单失败而让已到账的收款回滚。
            Log::error('PaypalB 生成本站订单失败，收款结果不受影响。', [
                'transaction_id'    => $transaction->transaction_id,
                'order_number'      => $transaction->order_number,
                'paypal_capture_id' => $transaction->paypal_capture_id,
                'error'             => $exception->getMessage(),
            ]);
            report($exception);

            return null;
        }
    }

    private function buildOrder(PaypalBTransaction $transaction): Order
    {
        $buyer    = (array) $transaction->buyer_snapshot;
        $shipping = (array) $transaction->shipping_address;
        $snapshot = (array) data_get($transaction->bridge_request, 'order_snapshot', []);
        $currency = strtoupper((string) $transaction->currency);

        $order = new Order([
            'number'                 => $this->availableOrderNumber($transaction),
            'customer_id'            => 0,
            'customer_group_id'      => 0,
            'shipping_address_id'    => 0,
            'payment_address_id'     => 0,
            'customer_name'          => (string) ($buyer['name'] ?? ''),
            'email'                  => (string) ($buyer['email'] ?? ''),
            'calling_code'           => (int) ($buyer['calling_code'] ?? 0),
            'telephone'              => (string) ($buyer['telephone'] ?? ''),
            'total'                  => $transaction->amount,
            'locale'                 => locale(),
            'currency_code'          => $currency,
            'currency_value'         => $this->currencyValue($currency),
            'ip'                     => (string) ($buyer['ip'] ?? ''),
            'user_agent'             => (string) ($buyer['user_agent'] ?? ''),
            'comment'                => $this->orderComment($transaction, $buyer),
            'status'                 => StateMachineService::PAID,
            'shipping_method_code'   => (string) ($snapshot['shipping_method_code'] ?? ''),
            'shipping_method_name'   => (string) ($snapshot['shipping_method_name'] ?? ''),
            'shipping_customer_name' => (string) ($shipping['name'] ?? ''),
            'shipping_calling_code'  => (int) ($shipping['calling_code'] ?? 0),
            'shipping_telephone'     => (string) ($shipping['telephone'] ?? ''),
            'shipping_country'       => (string) ($shipping['country'] ?? ''),
            'shipping_country_id'    => $this->countryId((string) ($shipping['country_code'] ?? '')),
            'shipping_zone'          => (string) ($shipping['zone'] ?? ''),
            'shipping_zone_id'       => 0,
            'shipping_city'          => (string) ($shipping['city'] ?? ''),
            'shipping_address_1'     => (string) ($shipping['address_1'] ?? ''),
            'shipping_address_2'     => (string) ($shipping['address_2'] ?? ''),
            'shipping_zipcode'       => (string) ($shipping['postal_code'] ?? ''),
            'payment_method_code'    => self::PAYMENT_METHOD_CODE,
            'payment_method_name'    => 'PayPal',
            'payment_customer_name'  => (string) ($buyer['name'] ?? ''),
            'payment_calling_code'   => (int) ($buyer['calling_code'] ?? 0),
            'payment_telephone'      => (string) ($buyer['telephone'] ?? ''),
            'payment_country'        => (string) ($shipping['country'] ?? ''),
            'payment_country_id'     => $this->countryId((string) ($shipping['country_code'] ?? '')),
            'payment_zone'           => (string) ($shipping['zone'] ?? ''),
            'payment_zone_id'        => 0,
            'payment_city'           => (string) ($shipping['city'] ?? ''),
            'payment_address_1'      => (string) ($shipping['address_1'] ?? ''),
            'payment_address_2'      => (string) ($shipping['address_2'] ?? ''),
            'payment_zipcode'        => (string) ($shipping['postal_code'] ?? ''),
        ]);
        $order->saveOrFail();

        $this->createProducts($order, $transaction);
        $this->createTotals($order, $transaction);
        $this->createHistory($order, $transaction);
        $this->createPayment($order, $transaction);

        return $order;
    }

    /**
     * 镜像订单默认与 A 站共用订单号，这是后台按同一个号对账的前提。
     * 但两站订单号互不知情，撞号时必须另起编号：
     * 宁可镜像订单号与 A 站不一致（真实号仍记在订单备注和交易记录里），
     * 也绝不能让跨站收款复用或覆盖一条本站自营订单。
     */
    private function availableOrderNumber(PaypalBTransaction $transaction): string
    {
        $number = (string) $transaction->order_number;
        if (! Order::query()->where('number', $number)->exists()) {
            return $number;
        }

        for ($suffix = 2; $suffix <= 99; $suffix++) {
            $candidate = $number . '-B' . $suffix;
            if (Order::query()->where('number', $candidate)->exists()) {
                continue;
            }

            Log::warning('PaypalB 镜像订单号与本站已有订单冲突，已改用备用编号。', [
                'transaction_id' => $transaction->transaction_id,
                'a_order_number' => $number,
                'used_number'    => $candidate,
            ]);

            return $candidate;
        }

        throw new \RuntimeException('镜像订单号冲突且备用编号已用尽：' . $number);
    }

    /**
     * 商品行使用发给 PayPal 的明细，保证后台、PayPal 交易和买家账单三处口径一致。
     */
    private function createProducts(Order $order, PaypalBTransaction $transaction): void
    {
        foreach ((array) $transaction->paypal_order_items as $item) {
            OrderProduct::query()->create([
                'order_id'     => $order->id,
                // B 站不存在真实商品，product_id 固定为 0，避免误指向本站商品。
                'product_id'   => 0,
                'order_number' => (string) $order->number,
                'product_sku'  => (string) ($item['sku'] ?? ''),
                'name'         => (string) ($item['name'] ?? ''),
                'image'        => '',
                'quantity'     => (int) ($item['quantity'] ?? 0),
                'price'        => (string) ($item['unit_price'] ?? '0'),
            ]);
        }
    }

    private function createTotals(Order $order, PaypalBTransaction $transaction): void
    {
        $totals = (array) $transaction->order_totals;
        if ($totals === []) {
            $totals = [
                ['code' => 'sub_total', 'title' => '商品小计', 'value' => (string) $transaction->amount],
                ['code' => 'order_total', 'title' => '订单总额', 'value' => (string) $transaction->amount],
            ];
        }

        foreach ($totals as $total) {
            OrderTotal::query()->create([
                'order_id'  => $order->id,
                'code'      => (string) ($total['code'] ?? ''),
                'title'     => (string) ($total['title'] ?? ''),
                'value'     => (string) ($total['value'] ?? '0'),
                // OrderTotal 模型没有 array cast，与核心 OrderTotalRepo 一致手工编码。
                'reference' => json_encode([], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    private function createHistory(Order $order, PaypalBTransaction $transaction): void
    {
        OrderHistory::query()->create([
            'order_id' => $order->id,
            'status'   => StateMachineService::PAID,
            // B 站不再向买家发送任何通知，避免与 A 站的订单邮件重复。
            'notify'   => 0,
            'comment'  => sprintf(
                'PayPal 收款成功，跨站交易号 %s，PayPal 捕获号 %s。',
                (string) $transaction->transaction_id,
                (string) $transaction->paypal_capture_id
            ),
        ]);
    }

    private function createPayment(Order $order, PaypalBTransaction $transaction): void
    {
        OrderPayment::query()->create([
            'order_id'       => $order->id,
            'transaction_id' => (string) $transaction->paypal_capture_id,
            'request'        => json_encode([
                'bridge_transaction_id' => $transaction->transaction_id,
                'reference'             => $transaction->reference,
                'paypal_order_id'       => $transaction->paypal_order_id,
                'account_id'            => $transaction->account_id,
                'amount'                => $transaction->amount,
                'currency'              => $transaction->currency,
            ], JSON_UNESCAPED_UNICODE),
            'response'       => json_encode([
                'provider_status' => $transaction->provider_status,
                'paid_at'         => $transaction->paid_at?->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 商品行对外用映射后的名称，真实商品名只保留在订单备注里供内部核对。
     */
    private function orderComment(PaypalBTransaction $transaction, array $buyer): string
    {
        $lines = [];
        if (trim((string) ($buyer['comment'] ?? '')) !== '') {
            $lines[] = '买家留言：' . trim((string) $buyer['comment']);
        }

        $realNames = [];
        foreach ((array) $transaction->order_items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $realNames[] = $name . ' × ' . (int) ($item['quantity'] ?? 0);
            }
        }
        if ($realNames !== [] && (string) $transaction->paypal_item_source === 'mapped') {
            $lines[] = 'A 站真实商品：' . implode('；', $realNames);
        }

        $lines[] = 'A 站订单号：' . (string) $transaction->order_number;

        return mb_substr(implode("\n", $lines), 0, 2000);
    }

    private function currencyValue(string $currencyCode): string
    {
        try {
            return (string) (CurrencyRepo::findByCode($currencyCode)->value ?? 1);
        } catch (\Throwable) {
            return '1';
        }
    }

    private function countryId(string $countryCode): int
    {
        if (! preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return 0;
        }

        try {
            return (int) (Country::query()->where('code', $countryCode)->value('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
