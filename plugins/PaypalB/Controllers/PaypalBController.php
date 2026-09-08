<?php

namespace Plugin\PaypalB\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugin\PaypalB\Jobs\PaypalBWebhookJob;
use Plugin\PaypalB\Models\PaypalBAccount;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Models\PaypalBWebhookEvent;
use Plugin\PaypalB\Services\BridgeSignature;
use Plugin\PaypalB\Services\PaypalBApiClient;
use Plugin\PaypalB\Services\PaypalBConfiguration;
use Plugin\PaypalB\Services\PaypalBTransactionService;

class PaypalBController
{
    public function createSession(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            $payload = $request->all();
        }

        try {
            $configuration = PaypalBConfiguration::all();
            BridgeSignature::verify(
                $payload,
                (string) $request->header(BridgeSignature::HEADER_TIMESTAMP, ''),
                (string) $request->header(BridgeSignature::HEADER_NONCE, ''),
                (string) $request->header(BridgeSignature::HEADER_SIGNATURE, ''),
                $configuration['a_request_verification_secret'],
                'paypal_b:a_request'
            );

            $transaction = app(PaypalBTransactionService::class)->create($payload);

            return $this->signedJson(app(PaypalBTransactionService::class)->statusPayload($transaction));
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        } catch (QueryException $exception) {
            report($exception);

            return response()->json(['message' => 'B 站支付服务尚未完成数据库迁移，请执行 php artisan migrate --force。'], 503);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'B 站 PayPal 会话创建失败。'], 500);
        }
    }

    public function status(Request $request, string $transactionId): JsonResponse
    {
        $payload = [
            'transaction_id' => $transactionId,
            'reference'      => trim((string) $request->query('reference', '')),
        ];

        try {
            $configuration = PaypalBConfiguration::all();
            BridgeSignature::verify(
                $payload,
                (string) $request->header(BridgeSignature::HEADER_TIMESTAMP, ''),
                (string) $request->header(BridgeSignature::HEADER_NONCE, ''),
                (string) $request->header(BridgeSignature::HEADER_SIGNATURE, ''),
                $configuration['a_request_verification_secret'],
                'paypal_b:a_status'
            );
            $transaction = PaypalBTransaction::query()->where('transaction_id', $transactionId)->firstOrFail();
            if ($payload['reference'] !== $transaction->reference) {
                throw ValidationException::withMessages(['reference' => 'A 站支付引用与 B 站会话不一致。']);
            }

            return $this->signedJson(app(PaypalBTransactionService::class)->statusPayload($transaction));
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        } catch (QueryException $exception) {
            report($exception);

            return response()->json(['message' => 'B 站支付服务尚未完成数据库迁移，请执行 php artisan migrate --force。'], 503);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'B 站支付状态读取失败。'], 500);
        }
    }

    public function syncFulfillment(Request $request, string $transactionId): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            $payload = $request->all();
        }

        try {
            $configuration = PaypalBConfiguration::all();
            BridgeSignature::verify(
                $payload,
                (string) $request->header(BridgeSignature::HEADER_TIMESTAMP, ''),
                (string) $request->header(BridgeSignature::HEADER_NONCE, ''),
                (string) $request->header(BridgeSignature::HEADER_SIGNATURE, ''),
                $configuration['a_request_verification_secret'],
                'paypal_b:a_fulfillment'
            );
            $transaction = PaypalBTransaction::query()->where('transaction_id', $transactionId)->firstOrFail();
            app(PaypalBTransactionService::class)->syncFulfillment($transaction, $payload);

            return $this->signedJson(app(PaypalBTransactionService::class)->statusPayload($transaction->fresh()));
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        } catch (QueryException $exception) {
            report($exception);

            return response()->json(['message' => 'B 站支付服务尚未完成数据库迁移，请执行 php artisan migrate --force。'], 503);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'B 站履约证据同步失败。'], 500);
        }
    }

    public function checkout(string $token)
    {
        $transaction   = PaypalBTransaction::query()->with('account')->where('public_token', $token)->firstOrFail();
        $this->applyBuyerLocale($transaction);
        $service       = app(PaypalBTransactionService::class);
        $method        = strtolower(trim((string) request()->query('method', 'wallet')));
        $configuration = PaypalBConfiguration::all();
        $merchant      = $this->merchantPresentation($configuration);

        try {
            $service->assertActiveForView($transaction);
            $method = $service->assertCheckoutMethod($transaction, $method);
            if ($transaction->status === 'completed') {
                $response = redirect()->away($this->aReturnUrl($transaction));
                $response->headers->set('Referrer-Policy', 'no-referrer');

                return $response;
            }
            if (in_array($transaction->status, ['cancelled', 'failed', 'expired'], true)) {
                throw ValidationException::withMessages(['payment' => trans('PaypalB::common.session_ended')]);
            }
            if ($method === 'card') {
                if (! $transaction->account || ! $transaction->account->usesApi()) {
                    throw ValidationException::withMessages(['payment' => trans('PaypalB::common.card_not_supported')]);
                }

                return $this->checkoutView('PaypalB::checkout.card', [
                    'transaction'    => $transaction,
                    'account'        => $transaction->account,
                    'client_token'   => $service->cardClientToken($transaction),
                    'return_url'     => $this->finishUrl($transaction),
                    'capture_url'    => route('shop.paypal_b.capture', ['token' => $transaction->public_token], false),
                    'status_url'     => route('shop.paypal_b.status', ['token' => $transaction->public_token], false),
                    'capture_token'  => $service->captureToken($transaction),
                    'checkout_items' => $service->checkoutItems($transaction),
                    'merchant'       => $merchant,
                ]);
            }
            if ($transaction->mode !== 'automatic') {
                $service->invalidateLegacySession($transaction, 'manual_collection_disabled');

                throw ValidationException::withMessages(['payment' => trans('PaypalB::common.session_unavailable')]);
            }

            return $this->checkoutView('PaypalB::checkout.wallet', [
                'transaction'    => $transaction,
                'account'        => $transaction->account,
                'capture_url'    => route('shop.paypal_b.capture', ['token' => $transaction->public_token], false),
                'status_url'     => route('shop.paypal_b.status', ['token' => $transaction->public_token], false),
                'capture_token'  => $service->captureToken($transaction),
                'return_url'     => $this->finishUrl($transaction),
                'checkout_items' => $service->checkoutItems($transaction),
                'merchant'       => $merchant,
            ]);
        } catch (ValidationException $exception) {
            $fallbackUrl = null;
            if ($method === 'card' && ! in_array($transaction->status, ['completed', 'cancelled', 'failed', 'expired', 'reconciling'], true)) {
                try {
                    $service->assertCheckoutMethod($transaction, 'wallet');
                    $fallbackUrl = $service->checkoutUrl($transaction, 'wallet');
                } catch (ValidationException) {
                    // 钱包未启用或当前账号不支持时不显示无效的回退入口。
                }
            }

            return $this->checkoutView('PaypalB::checkout.error', [
                'message'      => $exception->getMessage(),
                'merchant'     => $merchant,
                'fallback_url' => $fallbackUrl,
                'return_url'   => $this->finishUrl($transaction),
            ]);
        }
    }

    public function contact(): Response
    {
        return $this->policyPage('contact');
    }

    public function refunds(): Response
    {
        return $this->policyPage('refunds');
    }

    public function privacy(): Response
    {
        return $this->policyPage('privacy');
    }

    public function terms(): Response
    {
        return $this->policyPage('terms');
    }

    public function captureCheckout(Request $request, string $token): JsonResponse
    {
        $transaction = PaypalBTransaction::query()->where('public_token', $token)->firstOrFail();
        $this->applyBuyerLocale($transaction);
        $service     = app(PaypalBTransactionService::class);

        try {
            $data = $request->validate([
                'method'             => ['required', 'in:wallet,card'],
                'capture_token'      => ['required', 'string', 'size:64'],
                'client_metadata_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            ]);
            $service->assertCaptureToken($transaction, (string) $data['capture_token']);
            $method = $service->assertCheckoutMethod($transaction, (string) $data['method']);
            $service->capture($transaction, $this->clientMetadataId($data['client_metadata_id'] ?? null));
            $payload = $service->statusPayload($transaction->fresh());

            $completed = $payload['status'] === 'completed';

            return response()->json([
                'status'                  => $payload['status'],
                'reference'               => $payload['reference'],
                'provider_transaction_id' => $payload['provider_transaction_id'],
                'message'                 => $completed
                    ? ($method === 'card' ? trans('PaypalB::common.capture_card_ok') : trans('PaypalB::common.capture_wallet_ok'))
                    : trans('PaypalB::common.capture_pending'),
            ], $completed ? 200 : 202);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors'  => $exception->errors(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => trans('PaypalB::common.capture_failed')], 500);
        }
    }

    public function checkoutStatus(Request $request, string $token): JsonResponse
    {
        $transaction = PaypalBTransaction::query()->where('public_token', $token)->firstOrFail();
        $this->applyBuyerLocale($transaction);
        $service     = app(PaypalBTransactionService::class);

        try {
            $data = $request->validate([
                'capture_token' => ['required', 'string', 'size:64'],
            ]);
            $service->assertCaptureToken($transaction, (string) $data['capture_token']);
            $payload = $service->statusPayload($transaction->fresh());

            return response()->json([
                'status'    => $payload['status'],
                'reference' => $payload['reference'],
                'message'   => match ($payload['status']) {
                    'completed'   => trans('PaypalB::common.status_completed'),
                    'failed'      => trans('PaypalB::common.status_failed'),
                    'cancelled'   => trans('PaypalB::common.status_cancelled'),
                    'expired'     => trans('PaypalB::common.status_expired'),
                    'reconciling' => trans('PaypalB::common.status_reconciling'),
                    default       => trans('PaypalB::common.status_pending'),
                },
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors'  => $exception->errors(),
            ], 422);
        }
    }

    public function returnFromPaypal(Request $request): RedirectResponse
    {
        $transaction = PaypalBTransaction::query()->where('public_token', trim((string) $request->query('bridge_token', $request->query('token'))))->firstOrFail();
        $this->applyBuyerLocale($transaction);
        $service     = app(PaypalBTransactionService::class);
        $method      = strtolower(trim((string) $request->query('method', 'wallet')));

        try {
            $method = $service->assertCheckoutMethod($transaction, $method);
            $service->capture($transaction);
        } catch (ValidationException $exception) {
            $response = redirect()->away($service->checkoutUrl($transaction, $method))->withErrors($exception->errors());
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        }

        $response = redirect()->away($this->aReturnUrl($transaction));
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function cancel(Request $request): RedirectResponse
    {
        $transaction = PaypalBTransaction::query()->where('public_token', trim((string) $request->query('bridge_token', $request->query('token'))))->firstOrFail();
        $this->applyBuyerLocale($transaction);
        $cancelled   = false;
        if (in_array($transaction->status, ['creating', 'pending'], true)) {
            DB::transaction(function () use ($transaction): void {
                $locked = PaypalBTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
                if (in_array($locked->status, ['creating', 'pending'], true)) {
                    $locked->forceFill(['status' => 'cancelled'])->saveOrFail();
                }
            });
            $transaction->refresh();
            $cancelled = $transaction->status === 'cancelled';
        }
        if ($cancelled) {
            app(\Plugin\PaypalB\Services\PaypalBCallbackService::class)->queue($transaction->fresh());
        }

        $response = redirect()->away($this->aReturnUrl($transaction));
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * 结账页只暴露这个 B 站地址；A 站的真实回跳地址由服务端在 302 的 Location 中给出，
     * 页面内的 PayPal SDK 与任何第三方脚本都读不到跨域 302 的目标。
     *
     * 不校验支付状态：无论成功、取消还是失败，买家都应当能回到 A 站，
     * 由 A 站的 returned() 查询真实状态并给出对应提示。
     */
    public function finish(string $token): RedirectResponse
    {
        $transaction = PaypalBTransaction::query()->where('public_token', $token)->firstOrFail();
        $this->applyBuyerLocale($transaction);

        $response = redirect()->away($this->aReturnUrl($transaction));
        $response->headers->set('Referrer-Policy', 'no-referrer');
        // 302 被浏览器或中间层缓存后，买家再次访问会跳到过期的回跳地址。
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }

    public function webhook(Request $request, int $accountId): JsonResponse
    {
        $account       = PaypalBAccount::query()->findOrFail($accountId);
        $configuration = PaypalBConfiguration::all();
        $event         = $request->json()->all();
        if (! is_array($event) || $event === []) {
            return response()->json(['message' => 'invalid event'], 422);
        }

        try {
            $client   = new PaypalBApiClient($account, $configuration['sandbox_mode'], $configuration['request_timeout_seconds']);
            $verified = $client->verifyWebhookSignature([
                'auth_algo'         => $request->header('paypal-auth-algo'),
                'cert_url'          => $request->header('paypal-cert-url'),
                'transmission_id'   => $request->header('paypal-transmission-id'),
                'transmission_sig'  => $request->header('paypal-transmission-sig'),
                'transmission_time' => $request->header('paypal-transmission-time'),
            ], $event);
            if (! $verified) {
                return response()->json(['message' => 'invalid signature'], 400);
            }

            $eventId = trim((string) ($event['id'] ?? ''));
            if ($eventId === '' || strlen($eventId) > 127) {
                return response()->json(['message' => 'invalid event id'], 422);
            }

            $webhook = PaypalBWebhookEvent::query()
                ->where('account_id', $account->id)
                ->where('paypal_event_id', $eventId)
                ->first();
            if (! $webhook) {
                try {
                    $webhook = PaypalBWebhookEvent::query()->create([
                        'account_id'      => $account->id,
                        'paypal_event_id' => $eventId,
                        'event_type'      => trim((string) ($event['event_type'] ?? '')) ?: null,
                        'status'          => 'received',
                        'payload'         => $event,
                    ]);
                } catch (\Illuminate\Database\QueryException) {
                    // 并发重复 Webhook 由唯一键收敛到同一事件记录。
                    $webhook = PaypalBWebhookEvent::query()
                        ->where('account_id', $account->id)
                        ->where('paypal_event_id', $eventId)
                        ->firstOrFail();
                }
            }

            if (in_array($webhook->status, ['processed', 'ignored'], true)) {
                return response()->json(['status' => 'ok']);
            }
            if ($webhook->status === 'processing'
                && $webhook->updated_at?->isAfter(now()->subMinutes(5))) {
                // PayPal 的重复投递不应在前一个 Worker 尚未完成时重新派发同一事件。
                return response()->json(['status' => 'accepted'], 202);
            }

            $webhook->forceFill([
                'event_type' => trim((string) ($event['event_type'] ?? '')) ?: null,
                'status'     => 'received',
                'payload'    => $event,
                'last_error' => null,
            ])->saveOrFail();
            PaypalBWebhookJob::dispatch((int) $webhook->id)->afterCommit();

            return response()->json(['status' => 'accepted'], 202);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'webhook processing failed'], 500);
        }
    }

    /**
     * B 站没有买家会话，结账与收款接口一律按 A 站下单语言渲染；
     * 该语言在 B 站未启用时静默回退到 B 站默认语言，不影响付款。
     */
    private function applyBuyerLocale(PaypalBTransaction $transaction): void
    {
        $locale = trim((string) $transaction->buyer_locale);
        if ($locale === '') {
            return;
        }

        try {
            if (in_array($locale, array_column(locales(), 'code'), true)) {
                app()->setLocale($locale);
            }
        } catch (\Throwable) {
            // 语言表不可读时保持默认语言，绝不阻断支付流程。
        }
    }

    /**
     * 政策页由结账页链接进入，语言通过 lang 参数携带；非法或未启用的语言回退到默认语言。
     */
    private function applyRequestLocale(): void
    {
        $locale = strtolower(trim((string) request()->query('lang', '')));
        if (preg_match('/^[a-z]{2}(?:_[a-z]{2})?$/', $locale) !== 1) {
            return;
        }

        try {
            if (in_array($locale, array_column(locales(), 'code'), true)) {
                app()->setLocale($locale);
            }
        } catch (\Throwable) {
            // 语言表不可读时保持默认语言。
        }
    }

    /**
     * PayPal SDK 与 HTML lang 都要求 xx-XX 形式，与站点的 zh_cn 写法不同。
     */
    private function localeViewData(): array
    {
        $locale  = app()->getLocale();
        $htmlTag = str_replace('_', '-', $locale);
        $parts   = explode('-', $htmlTag);
        $htmlTag = count($parts) === 2
            ? strtolower($parts[0]) . '-' . strtoupper($parts[1])
            : strtolower($parts[0]);

        return [
            'html_lang'     => $htmlTag,
            // PayPal 只接受带地区的语言标记，单段语言按其文档补齐常用地区。
            'paypal_locale' => count($parts) === 2 ? str_replace('-', '_', $htmlTag) : match (strtolower($parts[0])) {
                'zh'    => 'zh_CN',
                'ja'    => 'ja_JP',
                'ko'    => 'ko_KR',
                'de'    => 'de_DE',
                'fr'    => 'fr_FR',
                'es'    => 'es_ES',
                'it'    => 'it_IT',
                'ru'    => 'ru_RU',
                'id'    => 'id_ID',
                default => 'en_US',
            },
        ];
    }

    private function clientMetadataId(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) ? $value : null;
    }

    /**
     * 注入到结账页的回跳地址。使用相对路径，页面上连 B 站域名都不出现完整形式。
     */
    private function finishUrl(PaypalBTransaction $transaction): string
    {
        return route('shop.paypal_b.finish', ['token' => $transaction->public_token], false);
    }

    /**
     * 买家付款后的去向。跨站交易回 A 站，自营订单留在本站，
     * 否则本地买家会被送到一个与自己订单无关的 A 站地址。
     */
    private function aReturnUrl(PaypalBTransaction $transaction): string
    {
        if ($transaction->isLocal()) {
            return (string) $transaction->status === 'completed'
                ? shop_route('checkout.success', ['order_number' => (string) $transaction->order_number], false)
                : shop_route('orders.pay', ['number' => (string) $transaction->order_number], false);
        }

        $configuration = PaypalBConfiguration::all();

        return rtrim($configuration['a_site_url'], '/') . '/paypal/return?reference=' . rawurlencode((string) $transaction->reference);
    }

    private function checkoutView(string $view, array $data): Response
    {
        $data += $this->localeViewData();

        return response()->view($view, $data, 200, [
            'Cache-Control'           => 'no-store, private',
            'Content-Security-Policy' => "frame-ancestors 'none'",
            'Referrer-Policy'         => 'no-referrer',
        ]);
    }

    private function signedJson(array $payload): JsonResponse
    {
        $configuration = PaypalBConfiguration::all();
        $headers       = BridgeSignature::headers($payload, $configuration['a_callback_signing_secret']);

        return response()->json($payload, 200, $headers);
    }

    private function merchantPresentation(array $configuration): array
    {
        // 政策页没有支付会话可依据，语言只能随链接传递，否则买家会跳到 B 站默认语言页面。
        $lang = ['lang' => app()->getLocale()];

        return [
            'display_name'  => $configuration['merchant_display_name'],
            'support_email' => $configuration['customer_service_email'],
            'contact_url'   => route('shop.paypal_b.contact', $lang, false),
            'support_phone' => $configuration['customer_service_phone'],
            'refund_url'    => route('shop.paypal_b.refunds', $lang, false),
            'privacy_url'   => route('shop.paypal_b.privacy', $lang, false),
            'terms_url'     => route('shop.paypal_b.terms', $lang, false),
        ];
    }

    private function policyPage(string $type): Response
    {
        $this->applyRequestLocale();
        $configuration = PaypalBConfiguration::all();
        $merchant      = $this->merchantPresentation($configuration);

        $content = match ($type) {
            'contact' => [
                'title' => trans('PaypalB::common.contact_title'),
                'body'  => [
                    trans('PaypalB::common.contact_email', ['email' => $merchant['support_email']]),
                    $merchant['support_phone']
                        ? trans('PaypalB::common.contact_phone', ['phone' => $merchant['support_phone']])
                        : null,
                ],
            ],
            'refunds' => [
                'title' => trans('PaypalB::common.refund_title'),
                'body'  => [
                    trans('PaypalB::common.refund_body_1'),
                    trans('PaypalB::common.refund_body_2'),
                ],
            ],
            'privacy' => [
                'title' => trans('PaypalB::common.privacy_title'),
                'body'  => [
                    trans('PaypalB::common.privacy_body_1'),
                    trans('PaypalB::common.privacy_body_2'),
                ],
            ],
            'terms' => [
                'title' => trans('PaypalB::common.terms_title'),
                'body'  => [
                    trans('PaypalB::common.terms_body_1'),
                    trans('PaypalB::common.terms_body_2'),
                ],
            ],
            default => abort(404),
        };

        return response()->view('PaypalB::checkout.policy', [
            'merchant' => $merchant,
            'title'    => $content['title'],
            'body'     => array_values(array_filter($content['body'])),
        ] + $this->localeViewData(), 200, [
            'Cache-Control'           => 'no-store, private',
            'Content-Security-Policy' => "frame-ancestors 'none'",
            'Referrer-Policy'         => 'no-referrer',
        ]);
    }
}
