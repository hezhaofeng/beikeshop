<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Validation\ValidationException;
use Plugin\PaypalB\Models\PaypalBAccount;

/**
 * 用收款账号自己的 API 凭据在 PayPal 注册 Webhook，并把 Webhook ID 写回账号。
 *
 * 只依赖 B 站公网地址、环境和超时三项配置，不要求插件其余配置已经填全，
 * 这样管理员可以按“先加账号、再注册 Webhook、最后补齐配置”的任意顺序完成初始化。
 */
final class PaypalBWebhookRegistrar
{
    /**
     * 插件会处理的事件；缺少第一项时支付成功后的状态同步会断，必须存在。
     * PENDING/DENIED 用于审核、电子支票等待确认的捕获：没有它们，退票的付款会永远停在待确认。
     */
    public const EVENT_TYPES = [
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.PENDING',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.CAPTURE.REVERSED',
        'CUSTOMER.DISPUTE.CREATED',
        'CUSTOMER.DISPUTE.UPDATED',
        'CUSTOMER.DISPUTE.RESOLVED',
        'CUSTOMER.DISPUTE.CANCELLED',
    ];

    public const REQUIRED_EVENT_TYPES = ['PAYMENT.CAPTURE.COMPLETED'];

    private const WEBHOOK_PATH = '/api/paypal/webhooks/';

    /**
     * 旧版本对外路径；已在 PayPal 登记的旧地址仍然有效，注册时直接复用而不是再建一个。
     */
    private const LEGACY_WEBHOOK_PATH = '/api/paypal-b/webhooks/';

    /**
     * @param array{public_url: string, sandbox_mode: bool, request_timeout_seconds: int} $configuration
     */
    public function __construct(private readonly array $configuration)
    {
    }

    public static function fromSetting(mixed $setting): self
    {
        $setting = is_array($setting) ? $setting : [];
        if (trim((string) ($setting['public_url'] ?? '')) === '') {
            throw ValidationException::withMessages(['public_url' => '请先在插件配置中保存 B 站公网地址。']);
        }

        return new self([
            'public_url'              => BridgeUrl::baseUrl($setting['public_url'], 'public_url'),
            'sandbox_mode'            => PaypalBConfiguration::booleanValue($setting['sandbox_mode'] ?? null, true),
            'request_timeout_seconds' => max(3, min(30, (int) ($setting['request_timeout_seconds'] ?? 10))),
        ]);
    }

    public static function webhookUrl(string $publicUrl, int $accountId): string
    {
        return BridgeUrl::join($publicUrl, self::WEBHOOK_PATH . $accountId);
    }

    /**
     * 允许复用的地址：当前路径与旧路径都指向同一个账号的接收入口。
     *
     * @return array<int, string>
     */
    public static function acceptedUrls(string $publicUrl, int $accountId): array
    {
        return [
            self::webhookUrl($publicUrl, $accountId),
            BridgeUrl::join($publicUrl, self::LEGACY_WEBHOOK_PATH . $accountId),
        ];
    }

    /**
     * 创建或复用 Webhook，返回结果摘要供后台提示；账号上的 webhook_id 已保存。
     *
     * @return array{webhook_id: string, url: string, reused: bool, event_types: array<int, string>, unavailable: array<int, string>}
     */
    public function register(PaypalBAccount $account): array
    {
        if (! $account->usesApi() || trim((string) $account->client_id) === '') {
            throw ValidationException::withMessages([
                'webhook_id' => '只有已填写 API 凭据的企业或个人卖家账号才能自动注册 Webhook。',
            ]);
        }

        $publicUrl = (string) $this->configuration['public_url'];
        if (strtolower((string) parse_url($publicUrl, PHP_URL_SCHEME)) !== 'https') {
            throw ValidationException::withMessages([
                'public_url' => 'PayPal 只接受 HTTPS 的 Webhook 地址，当前 B 站公网地址是 HTTP；请改为 HTTPS，或在 PayPal 后台手动创建后填写 Webhook ID。',
            ]);
        }

        $url    = self::webhookUrl($publicUrl, (int) $account->id);
        $client = new PaypalBApiClient(
            $account,
            (bool) $this->configuration['sandbox_mode'],
            (int) $this->configuration['request_timeout_seconds']
        );

        try {
            $available   = self::availableEventTypes($client->listWebhookEventTypes());
            $eventTypes  = array_values(array_intersect(self::EVENT_TYPES, $available));
            $unavailable = array_values(array_diff(self::EVENT_TYPES, $available));
            $missing     = array_values(array_diff(self::REQUIRED_EVENT_TYPES, $eventTypes));
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'webhook_id' => '当前 PayPal 应用不支持必需的事件：' . implode('、', $missing) . '，请检查应用权限或环境。',
                ]);
            }

            $existing = self::findExisting(
                (array) ($client->listWebhooks()['webhooks'] ?? []),
                self::acceptedUrls($publicUrl, (int) $account->id)
            );
            if ($existing !== null) {
                $webhook = $existing;
                if (self::missingEventTypes($existing, $eventTypes) !== []) {
                    $webhook = $client->replaceWebhookEventTypes(
                        (string) $existing['id'],
                        array_values(array_unique(array_merge(self::currentEventTypes($existing), $eventTypes)))
                    );
                    // PATCH 响应缺字段时仍以原记录为准，ID 不会变化。
                    $webhook = is_array($webhook) && ! empty($webhook['id']) ? $webhook : $existing;
                }
            } else {
                $webhook = $client->createWebhook($url, $eventTypes);
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (PaypalBApiException $exception) {
            throw ValidationException::withMessages(['webhook_id' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['webhook_id' => 'PayPal Webhook 注册过程中出现异常，请稍后重试。']);
        }

        $webhookId = trim((string) ($webhook['id'] ?? ''));
        if ($webhookId === '') {
            throw ValidationException::withMessages(['webhook_id' => 'PayPal 未返回 Webhook ID。']);
        }

        $account->forceFill(['webhook_id' => $webhookId])->saveOrFail();

        return [
            'webhook_id'  => $webhookId,
            'url'         => (string) ($webhook['url'] ?? $url),
            'reused'      => $existing !== null,
            'event_types' => $eventTypes,
            'unavailable' => $unavailable,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $webhooks
     * @param array<int, string>               $acceptedUrls
     */
    public static function findExisting(array $webhooks, array $acceptedUrls): ?array
    {
        $accepted = array_map([self::class, 'normalizeUrl'], $acceptedUrls);
        foreach ($webhooks as $webhook) {
            if (! is_array($webhook) || trim((string) ($webhook['id'] ?? '')) === '') {
                continue;
            }
            if (in_array(self::normalizeUrl((string) ($webhook['url'] ?? '')), $accepted, true)) {
                return $webhook;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $eventTypes
     * @return array<int, string>
     */
    public static function missingEventTypes(array $webhook, array $eventTypes): array
    {
        return array_values(array_diff($eventTypes, self::currentEventTypes($webhook)));
    }

    /**
     * @return array<int, string>
     */
    public static function availableEventTypes(array $payload): array
    {
        $names = [];
        foreach ((array) ($payload['event_types'] ?? []) as $eventType) {
            $name = is_array($eventType) ? trim((string) ($eventType['name'] ?? '')) : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<int, string>
     */
    private static function currentEventTypes(array $webhook): array
    {
        return self::availableEventTypes($webhook);
    }

    /**
     * 协议和主机不区分大小写，末尾斜杠忽略；路径本身保持原样比较。
     */
    private static function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        return strtolower((string) ($parts['scheme'] ?? 'https')) . '://'
            . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . rtrim((string) ($parts['path'] ?? ''), '/');
    }
}
