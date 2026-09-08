<?php

namespace Plugin\PaypalA\Services;

use Beike\Models\Order;
use Beike\Repositories\SettingRepo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Plugin\CyberCloak\Services\SkuMappingService;
use Plugin\PaypalA\Models\PaypalATransaction;

class PaypalABridgeClient
{
    public function __construct(private readonly array $configuration)
    {
    }

    /**
     * 创建 B 站支付会话。PayPal 收款账号和 API 凭据不会离开 B 站。
     */
    public function createSession(PaypalATransaction $transaction, Order $order): array
    {
        $snapshot    = PaypalAOrderSnapshot::fromOrder($order);
        $items       = $snapshot['items'];
        $paypalItems = $this->paypalItems($items);
        $payload     = [
            'reference'          => (string) $transaction->reference,
            'order_number'       => (string) $order->number,
            'amount'             => PaypalAMoney::normalize($transaction->amount, (string) $transaction->currency),
            'currency'           => strtoupper((string) $transaction->currency),
            // B 站没有买家会话，结账页只能按 A 站下单时的语言渲染。
            'locale'             => locale(),
            'expires_at'         => $transaction->expires_at?->toIso8601String(),
            'callback_url'       => BridgeUrl::join($this->configuration['public_url'], '/api/paypal/bridge/callback'),
            'allowed_methods'    => [(string) $transaction->payment_method],
            'buyer'              => $snapshot['buyer'],
            'order_items'        => $items,
            'paypal_item_source' => $this->configuration['bridge_item_source'],
            'paypal_order_items' => $paypalItems,
            'order_totals'       => $snapshot['totals'],
            'shipping_address'   => $snapshot['shipping_address'],
            'fulfillment'        => $snapshot['fulfillment'],
            'order_snapshot'     => $snapshot['order'],
        ];

        return $this->post('/api/paypal/bridge/sessions', $payload, 'create');
    }

    /**
     * 真实订单快照始终保留；映射模式只生成发送给 PayPal 的名称和 SKU 投影。
     */
    private function paypalItems(array $items): array
    {
        $source = $this->configuration['bridge_item_source'] ?? PaypalAPaymentService::ITEM_SOURCE_A_ORDER;
        if ($source === PaypalAPaymentService::ITEM_SOURCE_A_ORDER) {
            return $items;
        }
        if ($source !== PaypalAPaymentService::ITEM_SOURCE_MAPPING) {
            throw ValidationException::withMessages([
                'payment' => '订单商品明细模式无效，请在 PaypalA 配置中重新选择。',
            ]);
        }

        if (! SettingRepo::getPluginStatus('cyber_cloak')) {
            throw ValidationException::withMessages([
                'payment' => '映射商品明细需要先安装并启用 CyberCloak 插件。',
            ]);
        }

        try {
            $mapped = app(SkuMappingService::class)->resolvePaymentItems($items, app()->getLocale());
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'payment' => '无法生成映射商品明细：' . $exception->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'payment' => '映射商品明细暂时不可用，请稍后重试。',
            ]);
        }

        $this->assertMappedItemsPreserveOrderAmounts($items, $mapped);

        return $mapped;
    }

    private function assertMappedItemsPreserveOrderAmounts(array $orderItems, array $mappedItems): void
    {
        if (count($orderItems) !== count($mappedItems)) {
            throw ValidationException::withMessages(['payment' => '映射商品数量与原订单不一致。']);
        }

        foreach ($orderItems as $index => $item) {
            $mapped = $mappedItems[$index] ?? null;
            if (! is_array($mapped)
                || (int) ($mapped['source_product_id'] ?? 0) !== (int) ($item['source_product_id'] ?? 0)
                || (int) ($mapped['quantity'] ?? 0)          !== (int) ($item['quantity'] ?? 0)
                || (string) ($mapped['unit_price'] ?? '')    !== (string) ($item['unit_price'] ?? '')
                || (string) ($mapped['line_total'] ?? '')    !== (string) ($item['line_total'] ?? '')
                || trim((string) ($mapped['name'] ?? '')) === ''
                || trim((string) ($mapped['sku'] ?? ''))  === '') {
                throw ValidationException::withMessages([
                    'payment' => "第 {$index} 个映射商品未保持原订单金额、数量或必要商品字段。",
                ]);
            }
        }
    }

    public function syncFulfillment(PaypalATransaction $transaction, Order $order): array
    {
        if (! $transaction->b_transaction_id) {
            throw ValidationException::withMessages(['payment' => 'B 站支付会话尚未创建完成。']);
        }

        $snapshot = PaypalAOrderSnapshot::fromOrder($order);
        $payload  = [
            'transaction_id' => (string) $transaction->b_transaction_id,
            'reference'      => (string) $transaction->reference,
            'order_number'   => (string) $order->number,
            'fulfillment'    => $snapshot['fulfillment'],
        ];

        return $this->post(
            '/api/paypal/bridge/transactions/' . rawurlencode((string) $transaction->b_transaction_id) . '/fulfillment',
            $payload,
            'fulfillment'
        );
    }

    /**
     * 买家从 B 或 PayPal 返回 A 时主动拉取一次状态，补偿网络失败的 B 到 A 通知。
     */
    public function transactionStatus(PaypalATransaction $transaction): array
    {
        if (! $transaction->b_transaction_id) {
            throw ValidationException::withMessages(['payment' => 'B 站支付会话尚未创建完成。']);
        }

        $payload = [
            'transaction_id' => (string) $transaction->b_transaction_id,
            'reference'      => (string) $transaction->reference,
        ];
        $query = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);

        return $this->get('/api/paypal/bridge/transactions/' . rawurlencode((string) $transaction->b_transaction_id) . '?' . $query, $payload, 'status');
    }

    private function post(string $path, array $payload, string $scope): array
    {
        $headers = BridgeSignature::headers($payload, $this->configuration['request_signing_secret']);

        try {
            $response = Http::timeout($this->configuration['request_timeout_seconds'])
                ->acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->post(BridgeUrl::join($this->configuration['b_site_url'], $path), $payload);
        } catch (ConnectionException $exception) {
            Log::warning('PaypalA 无法连接 B 站桥接接口。', [
                'scope' => $scope,
                'url'   => BridgeUrl::join($this->configuration['b_site_url'], $path),
                'error' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['payment' => trans('PaypalA::common.gateway_unreachable')]);
        }

        return $this->validatedResponse($response, $payload, $scope);
    }

    private function get(string $path, array $payload, string $scope): array
    {
        $headers = BridgeSignature::headers($payload, $this->configuration['request_signing_secret']);

        try {
            $response = Http::timeout($this->configuration['request_timeout_seconds'])
                ->acceptJson()
                ->withHeaders($headers)
                ->get(BridgeUrl::join($this->configuration['b_site_url'], $path));
        } catch (ConnectionException $exception) {
            Log::warning('PaypalA 无法读取 B 站桥接接口。', [
                'scope' => $scope,
                'url'   => BridgeUrl::join($this->configuration['b_site_url'], $path),
                'error' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['payment' => trans('PaypalA::common.gateway_unreadable')]);
        }

        return $this->validatedResponse($response, $payload, $scope);
    }

    private function validatedResponse($response, array $requestPayload, string $scope): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            Log::warning('PaypalA 桥接响应格式无效。', [
                'scope'  => $scope,
                'status' => $response->status(),
                // PSR-7 UriInterface 没有 toString() 方法，使用其标准字符串转换。
                'url'    => (string) ($response->effectiveUri() ?? BridgeUrl::join($this->configuration['b_site_url'], '/api/paypal/bridge')),
            ]);
            throw ValidationException::withMessages(['payment' => 'B 站支付网关返回了无效数据。']);
        }

        if (! $response->successful()) {
            $message = trim((string) ($payload['message'] ?? 'B 站支付网关拒绝了本次请求。'));
            Log::warning('PaypalA 桥接请求被 B 站拒绝。', [
                'scope'  => $scope,
                'status' => $response->status(),
                'url'    => (string) ($response->effectiveUri() ?? BridgeUrl::join($this->configuration['b_site_url'], '/api/paypal/bridge')),
                'message' => $message,
                'errors' => is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
            ]);

            throw ValidationException::withMessages(['payment' => $message]);
        }

        $timestamp = (string) $response->header(BridgeSignature::HEADER_TIMESTAMP, '');
        $nonce     = (string) $response->header(BridgeSignature::HEADER_NONCE, '');
        $signature = (string) $response->header(BridgeSignature::HEADER_SIGNATURE, '');

        BridgeSignature::verify(
            $payload,
            $timestamp,
            $nonce,
            $signature,
            $this->configuration['callback_verification_secret'],
            'paypal_a:b_response:' . $scope
        );

        if (($payload['reference'] ?? null) !== ($requestPayload['reference'] ?? null)) {
            throw ValidationException::withMessages(['payment' => 'B 站返回的支付引用与本地订单不一致。']);
        }

        return $payload;
    }
}
