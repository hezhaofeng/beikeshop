<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Plugin\PaypalB\Models\PaypalBAccount;
use Plugin\PaypalB\Models\PaypalBTransaction;

class PaypalBApiClient
{
    private string $baseUrl;

    private ?string $accessTokenCacheKey = null;

    public function __construct(private readonly PaypalBAccount $account, private readonly bool $sandbox, private readonly int $timeout)
    {
        $this->baseUrl = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    public function createOrder(
        PaypalBTransaction $transaction,
        string $returnUrl,
        string $cancelUrl,
        string $brandName,
        array $orderItems = null
    ): array {
        try {
            $headers  = ['PayPal-Request-Id' => substr(hash('sha256', (string) $transaction->reference . '-create'), 0, 38)];
            $payload  = PaypalBOrderPayload::build($transaction, $returnUrl, $cancelUrl, $brandName, $orderItems);
            $response = $this->requestWithToken(fn (string $token): Response => Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->withToken($token)
                ->withHeaders($headers)
                ->post($this->baseUrl . '/v2/checkout/orders', $payload));
        } catch (ConnectionException $exception) {
            throw PaypalBApiException::connection('连接 PayPal 创建订单接口失败。', $exception);
        }

        return $this->response($response, 'PayPal 创建订单失败。', true);
    }

    public function captureOrder(string $paypalOrderId, string $reference, string $clientMetadataId = null): array
    {
        try {
            $headers = [
                'PayPal-Request-Id' => substr(hash('sha256', $reference . '-capture'), 0, 38),
            ];
            if ($clientMetadataId !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $clientMetadataId)) {
                // PayPal 官方风险信号 ID，不包含卡号等支付敏感数据。
                $headers['PayPal-Client-Metadata-Id'] = $clientMetadataId;
            }
            $response = $this->requestWithToken(fn (string $token): Response => Http::timeout($this->timeout)
                ->acceptJson()
                // 捕获接口不需要请求体；Laravel 默认会把空数组编码成 []，PayPal 视为非法 JSON。
                ->withBody('{}', 'application/json')
                ->withToken($token)
                ->withHeaders($headers)
                ->post($this->baseUrl . '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture'));
        } catch (ConnectionException $exception) {
            throw PaypalBApiException::connection('连接 PayPal 捕获订单接口失败。', $exception);
        }

        return $this->response($response, 'PayPal 捕获订单失败。', true);
    }

    public function refundCapture(
        string $captureId,
        string $requestId,
        string $reference,
        string $amount,
        string $currency,
        string $noteToPayer = null
    ): array {
        $headers = [
            // 退款重试必须复用同一个请求号，避免网络超时后产生第二笔退款。
            'PayPal-Request-Id' => $requestId,
        ];
        $payload = [
            'amount'     => [
                'value'         => PaypalBMoney::normalize($amount, $currency),
                'currency_code' => strtoupper($currency),
            ],
            'invoice_id' => $reference,
        ];
        if ($noteToPayer !== null && trim($noteToPayer) !== '') {
            $payload['note_to_payer'] = mb_substr(trim($noteToPayer), 0, 255);
        }

        try {
            $response = $this->requestWithToken(fn (string $token): Response => Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->withToken($token)
                ->withHeaders($headers)
                ->post($this->baseUrl . '/v2/payments/captures/' . rawurlencode($captureId) . '/refund', $payload));
        } catch (ConnectionException $exception) {
            throw PaypalBApiException::connection('连接 PayPal 退款接口失败。', $exception);
        }

        return $this->response($response, 'PayPal 退款请求失败。', true);
    }

    /**
     * 生成 Card Fields 所需的短期 client token；原始卡数据始终只在 PayPal 托管 iframe 中处理。
     */
    public function clientToken(): string
    {
        $response = $this->requestWithToken(fn (string $token): Response => Http::timeout($this->timeout)
            ->acceptJson()
            // 同样不能让 Laravel 把空请求体编码成 []。
            ->withBody('{}', 'application/json')
            ->withToken($token)
            ->post($this->baseUrl . '/v1/identity/generate-token'));

        $payload     = $this->response($response, 'PayPal 信用卡组件令牌生成失败。');
        $clientToken = trim((string) ($payload['client_token'] ?? ''));
        if ($clientToken === '') {
            throw new \RuntimeException('PayPal 未返回信用卡组件令牌。');
        }

        return $clientToken;
    }

    /**
     * 回跳重试或 Webhook 先到时，读取 PayPal 订单恢复已经完成的捕获结果。
     */
    public function getOrder(string $paypalOrderId): array
    {
        try {
            $response = $this->requestWithToken(fn (string $token): Response => Http::timeout($this->timeout)
                ->acceptJson()
                ->withToken($token)
                ->get($this->baseUrl . '/v2/checkout/orders/' . rawurlencode($paypalOrderId)));
        } catch (ConnectionException $exception) {
            throw PaypalBApiException::connection('连接 PayPal 查询订单接口失败。', $exception);
        }

        return $this->response($response, 'PayPal 查询订单失败。');
    }

    public function verifyWebhookSignature(array $headers, array $event): bool
    {
        if (trim((string) $this->account->webhook_id) === '') {
            throw new \RuntimeException('该 PayPal 账号未配置 Webhook ID。');
        }

        try {
            $response = $this->requestWithToken(fn (string $token): Response => Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->withToken($token)
                ->post($this->baseUrl . '/v1/notifications/verify-webhook-signature', [
                    'auth_algo'         => (string) ($headers['auth_algo'] ?? ''),
                    'cert_url'          => (string) ($headers['cert_url'] ?? ''),
                    'transmission_id'   => (string) ($headers['transmission_id'] ?? ''),
                    'transmission_sig'  => (string) ($headers['transmission_sig'] ?? ''),
                    'transmission_time' => (string) ($headers['transmission_time'] ?? ''),
                    'webhook_id'        => (string) $this->account->webhook_id,
                    'webhook_event'     => $event,
                ]));
        } catch (ConnectionException $exception) {
            throw PaypalBApiException::connection('连接 PayPal Webhook 验签接口失败。', $exception);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('PayPal Webhook 签名验证请求失败。');
        }

        return $response->json('verification_status') === 'SUCCESS';
    }

    private function requestWithToken(callable $request): Response
    {
        $response = $request($this->accessToken());
        if ($response->status() !== 401) {
            return $response;
        }

        // PayPal 可能在 expires_in 到期前撤销 Token；只刷新一次，避免异常响应形成重试风暴。
        if ($this->accessTokenCacheKey !== null) {
            Cache::store('database')->forget($this->accessTokenCacheKey);
        }

        return $request($this->accessToken(true));
    }

    private function accessToken(bool $forceRefresh = false): string
    {
        if (! $this->account->usesApi() || trim((string) $this->account->client_id) === '') {
            throw PaypalBApiException::configuration('该 PayPal 账号未配置 REST API 凭据。');
        }

        try {
            $secret = $this->account->decryptedClientSecret();
        } catch (\Throwable $exception) {
            throw PaypalBApiException::configuration(
                'PayPal 账号密钥无法读取，请在后台重新保存该账号凭据。'
            );
        }
        $cacheKey = 'paypal_b:oauth:' . ($this->sandbox ? 'sandbox' : 'live') . ':'
            . (string) $this->account->id . ':'
            . hash('sha256', (string) $this->account->client_id . ':' . $secret);
        $this->accessTokenCacheKey = $cacheKey;
        $cached                    = $forceRefresh ? null : Cache::store('database')->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->asForm()
                ->withBasicAuth((string) $this->account->client_id, $secret)
                ->post($this->baseUrl . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException $exception) {
            throw PaypalBApiException::connection('连接 PayPal OAuth 接口失败。', $exception);
        }

        if (! $response->successful() || ! $response->json('access_token')) {
            throw PaypalBApiException::fromResponse($response, 'PayPal OAuth 令牌获取失败。');
        }

        $token     = (string) $response->json('access_token');
        $expiresIn = max(60, min(3600, (int) $response->json('expires_in', 900) - 60));
        Cache::store('database')->put($cacheKey, $token, $expiresIn);

        return $token;
    }

    private function response(Response $response, string $message, bool $unknownOnMalformed = false): array
    {
        $payload = $response->json();
        if (! $response->successful()) {
            throw PaypalBApiException::fromResponse($response, $message);
        }
        if (! is_array($payload)) {
            if ($unknownOnMalformed) {
                throw PaypalBApiException::malformedResponse($message, $response->status());
            }

            throw PaypalBApiException::fromResponse($response, $message);
        }

        return $payload;
    }
}
