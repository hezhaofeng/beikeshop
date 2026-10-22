<?php

namespace Plugin\PaypalB\Services;

use Beike\Models\Country;
use Beike\Models\Order;
use Beike\Models\OrderHistory;
use Beike\Models\OrderPayment;
use Beike\Models\OrderProduct;
use Beike\Models\OrderTotal;
use Beike\Repositories\CurrencyRepo;
use Beike\Repositories\OrderPaymentRepo;
use Beike\Services\StateMachineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\PaypalB\Models\PaypalBTransaction;

/**
 * 把 A 站跨站订单落成一条 B 站镜像订单，供后台在订单模块直接查看。
 *
 * 两种创建时机（配置 mirror_order_timing）：
 * - on_completed：收款成功后直接以已支付写入，不经过 StateMachineService（历史行为）；
 * - on_session：A 站建会话时以待支付写入，收款后走状态机 UNPAID → PAID，会话失效则 CANCELLED。
 *
 * 商品行按 B 站目录分配：命中的 SKU 用真实 product_id 与 B 站售价，未命中的沿用 A 站明细；
 * B 站商品小计与 A 站实收之间的差额写成一条 paypal_b_adjustment 费用行，订单总额始终等于 PayPal 实收。
 *
 * 镜像订单默认不扣 B 站库存、不发买家邮件：库存与通知由 Bootstrap 的状态机钩子按配置移除。
 */
class PaypalBShopOrderService
{
    public const PAYMENT_METHOD_CODE = 'paypal_b';

    public const ADJUSTMENT_CODE = 'paypal_b_adjustment';

    public const RISK_FLAG_UNMAPPED = 'mirror_unmapped';

    /**
     * 会话建成（status=pending）时调用。仅 on_session 模式生效；失败只记日志，不影响会话创建。
     */
    public function createPendingFromTransaction(PaypalBTransaction $transaction): ?int
    {
        if ($transaction->isLocal() || (string) $transaction->status !== 'pending') {
            return null;
        }
        if (! $this->createsOnSession()) {
            return null;
        }

        return $this->createMirrorOrder($transaction, StateMachineService::UNPAID);
    }

    /**
     * 收款成功后调用。已有待支付镜像订单则推进到已支付，否则按历史行为直接建已支付订单。
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
            $this->markPaid($transaction);

            return (int) $transaction->shop_order_id;
        }

        return $this->createMirrorOrder($transaction, StateMachineService::PAID);
    }

    /**
     * 会话终结（过期、取消、被拒）时把待支付镜像订单取消；已支付或已取消的不动。
     */
    public function cancelFromTransaction(PaypalBTransaction $transaction, string $reason): void
    {
        if ($transaction->isLocal() || ! $transaction->shop_order_id) {
            return;
        }

        try {
            DB::transaction(function () use ($transaction, $reason): void {
                $order = Order::query()->lockForUpdate()->find($transaction->shop_order_id);
                if (! $order || $order->status !== StateMachineService::UNPAID) {
                    return;
                }

                StateMachineService::getInstance($order)->changeStatus(
                    StateMachineService::CANCELLED,
                    sprintf('PayPal 支付会话已结束（%s）。', $reason),
                    false
                );
            });
        } catch (\Throwable $exception) {
            Log::error('PaypalB 取消待支付镜像订单失败。', [
                'transaction_id' => $transaction->transaction_id,
                'shop_order_id'  => $transaction->shop_order_id,
                'reason'         => $reason,
                'error'          => $exception->getMessage(),
            ]);
            report($exception);
        }
    }

    /**
     * 待支付镜像订单在收款后推进到已支付。走状态机以写入历史与支付审计，但不通知买家。
     */
    private function markPaid(PaypalBTransaction $transaction): void
    {
        try {
            DB::transaction(function () use ($transaction): void {
                $order = Order::query()->lockForUpdate()->find($transaction->shop_order_id);
                if (! $order) {
                    return;
                }

                $payment = $this->paymentAudit($transaction);
                // PayPal 记录的收货地址只有捕获后才知道，入账时再补写地址不一致说明。
                $this->refreshComment($order, $transaction);
                if ($order->status === StateMachineService::UNPAID) {
                    StateMachineService::getInstance($order)
                        ->setPayment($payment)
                        ->changeStatus(StateMachineService::PAID, $this->paidComment($transaction), false);

                    return;
                }

                // 订单已被后台改到其他状态时不强行推进，但已到账的款项必须留下审计记录。
                OrderPaymentRepo::createOrUpdatePayment($order->id, $payment);
            });
        } catch (\Throwable $exception) {
            Log::error('PaypalB 镜像订单入账失败，收款结果不受影响。', [
                'transaction_id'    => $transaction->transaction_id,
                'shop_order_id'     => $transaction->shop_order_id,
                'paypal_capture_id' => $transaction->paypal_capture_id,
                'error'             => $exception->getMessage(),
            ]);
            report($exception);
        }
    }

    private function createMirrorOrder(PaypalBTransaction $transaction, string $status): ?int
    {
        try {
            return DB::transaction(function () use ($transaction, $status): ?int {
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
                    ?: $this->buildOrder($locked, $status);

                $locked->forceFill(['shop_order_id' => $order->id])->saveOrFail();

                // 复用了此前会话建的待支付镜像订单、而本次已收款：立即推进。
                if ($status === StateMachineService::PAID && $order->status === StateMachineService::UNPAID) {
                    $this->markPaid($locked);
                }

                return (int) $order->id;
            });
        } catch (\Throwable $exception) {
            // 订单只是后台可视化的副产物，绝不能因为建单失败而让已到账的收款或会话创建回滚。
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

    private function buildOrder(PaypalBTransaction $transaction, string $status): Order
    {
        $buyer    = (array) $transaction->buyer_snapshot;
        $shipping = (array) $transaction->shipping_address;
        $snapshot = (array) data_get($transaction->bridge_request, 'order_snapshot', []);
        $currency = strtoupper((string) $transaction->currency);
        $lines    = $this->assignProducts($transaction);

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
            'comment'                => $transaction->shippingMismatchNote(),
            'status'                 => $status,
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

        $this->createProducts($order, $lines);
        $this->createTotals($order, $transaction, $lines);
        $this->createHistory($order, $transaction, $status);
        if ($status === StateMachineService::PAID) {
            $this->createPayment($order, $transaction);
        }
        $this->flagUnmapped($transaction, $lines);

        return $order;
    }

    /**
     * 镜像订单默认与 A 站共用订单号，这是后台按同一个号对账的前提。
     * 但两站订单号互不知情，撞号时必须另起编号：
     * 宁可镜像订单号与 A 站不一致（真实号仍记在交易记录里），
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
     * 按 B 站目录分配商品行。匹配顺序：发给 PayPal 的映射 SKU → A 站原 SKU → 商品名称精确匹配。
     * 命中时用 B 站售价；未命中时沿用发给 PayPal 的明细与 A 站单价，并在返回值里标记。
     *
     * @return array<int, array{product_id:int, sku:string, name:string, image:string, quantity:int, price:string, mapped:bool}>
     */
    private function assignProducts(PaypalBTransaction $transaction): array
    {
        $paypalItems = array_values((array) $transaction->paypal_order_items);
        $orderItems  = array_values((array) $transaction->order_items);
        $currency    = strtoupper((string) $transaction->currency);
        $lines       = [];

        foreach ($paypalItems as $index => $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 0));
            $matched  = $this->matchCatalog(
                (string) ($item['sku'] ?? ''),
                (string) ($orderItems[$index]['sku'] ?? ''),
                (string) ($item['name'] ?? '')
            );

            if ($matched !== null) {
                $lines[] = [
                    'product_id' => (int) $matched['product_id'],
                    'sku'        => (string) $matched['sku'],
                    'name'       => (string) ($matched['name'] !== '' ? $matched['name'] : ($item['name'] ?? '')),
                    'image'      => (string) $matched['image'],
                    'quantity'   => $quantity,
                    'price'      => PaypalBMoney::normalize((string) $matched['price'], $currency),
                    'mapped'     => true,
                ];

                continue;
            }

            $lines[] = [
                // 目录里没有对应商品：product_id 固定为 0，避免误指向本站商品。
                'product_id' => 0,
                'sku'        => (string) ($item['sku'] ?? ''),
                'name'       => (string) ($item['name'] ?? ''),
                'image'      => '',
                'quantity'   => $quantity,
                'price'      => PaypalBMoney::normalize((string) ($item['unit_price'] ?? '0'), $currency),
                'mapped'     => false,
            ];
        }

        return $lines;
    }

    /**
     * @return array{product_id:int, sku:string, name:string, image:string, price:string}|null
     */
    private function matchCatalog(string $mappedSku, string $sourceSku, string $name): ?array
    {
        foreach (array_unique(array_filter([trim($mappedSku), trim($sourceSku)])) as $sku) {
            $row = DB::table('product_skus')
                ->join('products', 'products.id', '=', 'product_skus.product_id')
                ->where('product_skus.sku', $sku)
                ->where('products.active', 1)
                ->orderBy('product_skus.id')
                ->first(['product_skus.product_id', 'product_skus.sku', 'product_skus.price', 'product_skus.images', 'products.images as product_images']);
            if ($row) {
                return $this->catalogRow($row);
            }
        }

        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $row = DB::table('product_descriptions')
            ->join('products', 'products.id', '=', 'product_descriptions.product_id')
            ->join('product_skus', function ($join): void {
                $join->on('product_skus.product_id', '=', 'products.id')->where('product_skus.is_default', 1);
            })
            ->where('product_descriptions.name', $name)
            ->where('products.active', 1)
            ->orderByRaw('product_descriptions.locale = ? desc', [locale()])
            ->orderBy('products.id')
            ->first(['product_skus.product_id', 'product_skus.sku', 'product_skus.price', 'product_skus.images', 'products.images as product_images']);

        return $row ? $this->catalogRow($row) : null;
    }

    private function catalogRow(object $row): array
    {
        $name = (string) (DB::table('product_descriptions')
            ->where('product_id', $row->product_id)
            ->orderByRaw('locale = ? desc', [locale()])
            ->value('name') ?? '');
        $images = json_decode((string) ($row->images ?: $row->product_images ?: '[]'), true);

        return [
            'product_id' => (int) $row->product_id,
            'sku'        => (string) $row->sku,
            'name'       => $name,
            'image'      => is_array($images) && isset($images[0]) && is_string($images[0]) ? $images[0] : '',
            'price'      => (string) $row->price,
        ];
    }

    private function createProducts(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            OrderProduct::query()->create([
                'order_id'     => $order->id,
                'product_id'   => $line['product_id'],
                'order_number' => (string) $order->number,
                'product_sku'  => $line['sku'],
                'name'         => $line['name'],
                'image'        => $line['image'],
                'quantity'     => $line['quantity'],
                'price'        => $line['price'],
            ]);
        }
    }

    /**
     * 商品小计按 B 站分配后的行重算；运费等费用行原样复制；差额写成调整行，保证总额等于实收。
     * 金额均按交易币种的最小单位做整数运算，避免浮点误差。
     */
    private function createTotals(Order $order, PaypalBTransaction $transaction, array $lines): void
    {
        $currency  = strtoupper((string) $transaction->currency);
        $totals    = (array) $transaction->order_totals;
        $subMinor  = 0;
        foreach ($lines as $line) {
            $subMinor += PaypalBMoney::toMinor(PaypalBMoney::multiply($line['price'], $line['quantity'], $currency), $currency);
        }

        $feeMinor = 0;
        $fees     = [];
        foreach ($totals as $total) {
            $code = strtolower(trim((string) ($total['code'] ?? '')));
            if (in_array($code, ['sub_total', 'order_total', self::ADJUSTMENT_CODE], true)) {
                continue;
            }
            $fees[]   = $total;
            $feeMinor += PaypalBMoney::signedToMinor((string) ($total['value'] ?? '0'), $currency);
        }

        $amountMinor     = PaypalBMoney::toMinor((string) $transaction->amount, $currency);
        $adjustmentMinor = $amountMinor - $subMinor - $feeMinor;

        $rows = [['code' => 'sub_total', 'title' => '商品小计', 'value' => PaypalBMoney::fromMinor($subMinor, $currency)]];
        foreach ($fees as $fee) {
            $rows[] = ['code' => (string) ($fee['code'] ?? ''), 'title' => (string) ($fee['title'] ?? ''), 'value' => (string) ($fee['value'] ?? '0')];
        }
        if ($adjustmentMinor !== 0) {
            $rows[] = [
                'code'  => self::ADJUSTMENT_CODE,
                'title' => '价格调整',
                'value' => ($adjustmentMinor < 0 ? '-' : '') . PaypalBMoney::fromMinor(abs($adjustmentMinor), $currency),
            ];
        }
        $rows[] = ['code' => 'order_total', 'title' => '订单总额', 'value' => PaypalBMoney::normalize((string) $transaction->amount, $currency)];

        foreach ($rows as $row) {
            OrderTotal::query()->create([
                'order_id'  => $order->id,
                'code'      => $row['code'],
                'title'     => $row['title'],
                'value'     => $row['value'],
                // OrderTotal 模型没有 array cast，与核心 OrderTotalRepo 一致手工编码。
                'reference' => json_encode([], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    private function createHistory(Order $order, PaypalBTransaction $transaction, string $status): void
    {
        $comment = $status === StateMachineService::PAID
            ? $this->paidComment($transaction)
            : 'PayPal 支付会话已创建，等待收款。';

        OrderHistory::query()->create([
            'order_id' => $order->id,
            'status'   => $status,
            // B 站不再向买家发送任何通知，避免与 A 站的订单邮件重复。
            'notify'   => 0,
            'comment'  => $comment,
        ]);
    }

    private function paidComment(PaypalBTransaction $transaction): string
    {
        return $transaction->paidComment(sprintf(
            'PayPal 收款成功，捕获号 %s。',
            (string) $transaction->paypal_capture_id
        ));
    }

    private function createPayment(Order $order, PaypalBTransaction $transaction): void
    {
        $audit = $this->paymentAudit($transaction);
        OrderPayment::query()->create([
            'order_id'       => $order->id,
            'transaction_id' => $audit['transaction_id'],
            'request'        => json_encode($audit['request'], JSON_UNESCAPED_UNICODE),
            'response'       => json_encode($audit['response'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function paymentAudit(PaypalBTransaction $transaction): array
    {
        return [
            'transaction_id' => (string) $transaction->paypal_capture_id,
            'request'        => [
                'bridge_transaction_id' => $transaction->transaction_id,
                'reference'             => $transaction->reference,
                'paypal_order_id'       => $transaction->paypal_order_id,
                'account_id'            => $transaction->account_id,
                'amount'                => $transaction->amount,
                'currency'              => $transaction->currency,
            ],
            'response'       => [
                'provider_status' => $transaction->provider_status,
                'paid_at'         => $transaction->paid_at?->toIso8601String(),
            ],
            'callback'       => [],
        ];
    }

    /**
     * 有商品行没能匹配到 B 站目录时在交易上打标，后台交易列表据此筛查。
     */
    private function flagUnmapped(PaypalBTransaction $transaction, array $lines): void
    {
        $unmapped = array_values(array_filter($lines, static fn (array $line): bool => ! $line['mapped']));
        if ($unmapped === []) {
            return;
        }

        $flags = (array) $transaction->risk_flags;
        if (! in_array(self::RISK_FLAG_UNMAPPED, $flags, true)) {
            $flags[] = self::RISK_FLAG_UNMAPPED;
            $transaction->forceFill(['risk_flags' => array_values($flags)])->saveOrFail();
        }
    }

    /**
     * 镜像订单备注只用于收货地址提示，不写买家留言、真实商品名或来源站信息。
     * PayPal 记录的收货地址与订单收货地址不一致时在此说明，供发货核对。
     */
    private function refreshComment(Order $order, PaypalBTransaction $transaction): void
    {
        $note = $transaction->shippingMismatchNote();
        if ($note !== '' && $note !== (string) $order->comment) {
            $order->forceFill(['comment' => $note])->saveOrFail();
        }
    }

    private function createsOnSession(): bool
    {
        try {
            return PaypalBConfiguration::all()['mirror_order_timing'] === PaypalBConfiguration::MIRROR_ON_SESSION;
        } catch (\Throwable) {
            return false;
        }
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
