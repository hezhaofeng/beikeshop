<?php

namespace Plugin\PaypalB\Controllers;

use Beike\Repositories\SettingRepo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Plugin\PaypalB\Models\PaypalBAccount;
use Plugin\PaypalB\Models\PaypalBRefund;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBCallbackService;
use Plugin\PaypalB\Services\PaypalBConfiguration;
use Plugin\PaypalB\Services\PaypalBRefundService;
use Plugin\PaypalB\Services\PaypalBTransactionService;
use Plugin\PaypalB\Services\PaypalBWebhookRegistrar;

class PaypalBAdminController
{
    /**
     * 保存 B 站配置。密钥输入留空时保留已保存值，避免后台编辑普通字段时清空桥接密钥。
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $fields  = $request->except(['_token', '_method']);
        $setting = plugin_setting('paypal_b', []);
        $setting = is_array($setting) ? $setting : [];

        foreach (['a_request_verification_secret', 'a_callback_signing_secret'] as $secret) {
            if (trim((string) ($fields[$secret] ?? '')) === '' && isset($setting[$secret])) {
                $fields[$secret] = $setting[$secret];
            }
        }
        $plugin = plugin('paypal_b');
        $plugin?->validate($fields)->validate();
        PaypalBConfiguration::from($fields);
        SettingRepo::update('plugin', 'paypal_b', $fields);

        return redirect(admin_route('plugins.edit', ['code' => 'paypal_b']))
            ->with('success', trans('common.updated_success'));
    }

    public function accounts()
    {
        $setting   = plugin_setting('paypal_b', []);
        $setting   = is_array($setting) ? $setting : [];
        $publicUrl = trim((string) ($setting['public_url'] ?? ''));

        return view('PaypalB::admin.accounts', [
            'accounts'         => PaypalBAccount::query()->orderBy('priority')->orderBy('id')->get(),
            'transactions'     => PaypalBTransaction::query()->with(['account', 'refunds'])->latest('id')->paginate(30),
            'webhook_base_url' => $publicUrl === '' ? null : rtrim($publicUrl, '/') . '/api/paypal/webhooks/',
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data         = $this->validateAccount($request);
        $wantsActive  = ! empty($data['active']);
        $autoRegister = $request->boolean('register_webhook');
        if (PaypalBAccount::isAutomaticApiAccountType($data['account_type']) && trim((string) ($data['webhook_id'] ?? '')) === '') {
            $data['active'] = false;
        }
        $this->assertCredentialMode($data);
        $account = new PaypalBAccount;
        $this->fillAccount($account, $data);
        $account->saveOrFail();

        if (! $autoRegister || trim((string) $account->webhook_id) !== '') {
            return back()->with('success', 'PayPal 收款账号已添加。');
        }

        // Webhook 地址里带账号 ID，所以只能先落库再注册；注册失败也保留账号，管理员修正后可在列表重试。
        try {
            $result = app(PaypalBWebhookRegistrar::class)->register($account);
        } catch (ValidationException $exception) {
            return back()->withErrors([
                'webhook_id' => '账号已以停用状态保存，但自动注册 Webhook 失败：' . $exception->getMessage() . ' 修正后可在账号列表中点击“自动注册 Webhook”重试。',
            ]);
        }
        if ($wantsActive) {
            // 新增时被迫停用的唯一原因是缺少 Webhook ID，现在已补齐，按管理员原意启用。
            $account->forceFill(['active' => true])->saveOrFail();
        }

        return back()->with('success', 'PayPal 收款账号已添加。' . $this->webhookRegisteredMessage($account->fresh(), $result));
    }

    public function updateAccount(Request $request, PaypalBAccount $account): RedirectResponse
    {
        $data = $this->validateAccount($request, $account);
        $this->assertCredentialMode($data, $account);
        $this->fillAccount($account, $data);
        $account->saveOrFail();

        return back()->with('success', 'PayPal 收款账号已保存。');
    }

    public function deleteAccount(PaypalBAccount $account): RedirectResponse
    {
        if (PaypalBTransaction::query()->where('account_id', $account->id)->exists()) {
            return back()->withErrors(['account' => '该账号已有支付交易记录，不能删除；请编辑账号并停用。']);
        }

        $account->delete();

        return back()->with('success', 'PayPal 收款账号已删除。');
    }

    /**
     * 用账号自己的凭据在 PayPal 创建或复用 Webhook，并把 ID 写回；不改变账号启用状态。
     */
    public function registerWebhook(PaypalBAccount $account): RedirectResponse
    {
        try {
            $result = app(PaypalBWebhookRegistrar::class)->register($account);
        } catch (ValidationException $exception) {
            return back()->withErrors(['webhook_id' => '自动注册 Webhook 失败：' . $exception->getMessage()]);
        }

        return back()->with('success', $this->webhookRegisteredMessage($account->fresh(), $result));
    }

    /**
     * @param array{webhook_id: string, url: string, reused: bool, event_types: array<int, string>, unavailable: array<int, string>} $result
     */
    private function webhookRegisteredMessage(PaypalBAccount $account, array $result): string
    {
        $message = sprintf(
            'Webhook 已%s（ID：%s，地址：%s，订阅事件 %d 个）。',
            $result['reused'] ? '复用 PayPal 上已有的登记' : '在 PayPal 创建',
            $result['webhook_id'],
            $result['url'],
            count($result['event_types'])
        );
        if ($result['unavailable'] !== []) {
            $message .= '当前 PayPal 应用不提供以下事件，已跳过：' . implode('、', $result['unavailable']) . '。';
        }
        if (! $account->active) {
            $message .= '账号仍处于停用状态，编辑账号并勾选“启用账号”后即可加入轮询。';
        }

        return $message;
    }

    public function retryCallback(PaypalBTransaction $transaction): RedirectResponse
    {
        if ($transaction->isLocal()) {
            return back()->withErrors(['transaction' => '本站订单没有 A 站回调目标，收款结果已直接写入本站订单。']);
        }
        if (! in_array($transaction->status, ['completed', 'cancelled', 'failed', 'expired'], true) && ! $transaction->hasPendingCapture()) {
            return back()->withErrors(['transaction' => '只有最终状态或已捕获待确认的会话可以重试回调。']);
        }

        if (! app(PaypalBCallbackService::class)->send($transaction, true)) {
            return back()->withErrors(['transaction' => 'A 站回调仍未成功，请检查 A 站地址、签名密钥和网络。']);
        }

        return back()->with('success', 'A 站回调已重试。');
    }

    /**
     * 向 PayPal 查询待确认捕获的最新结果。Webhook 丢失或延迟时，后台不必等半小时一次的补偿命令。
     */
    public function syncPaypalStatus(PaypalBTransaction $transaction): RedirectResponse
    {
        if (! $transaction->paypal_order_id || ! in_array($transaction->status, ['pending', 'creating'], true)) {
            return back()->withErrors(['transaction' => '只有已创建 PayPal 订单且尚未完成的会话可以同步状态。']);
        }

        try {
            $synced = app(PaypalBTransactionService::class)->syncWithPaypal($transaction);
        } catch (ValidationException $exception) {
            return back()->withErrors(['transaction' => 'PayPal 状态已同步：' . $exception->getMessage()]);
        }

        $message = match ((string) $synced->status) {
            'completed' => 'PayPal 已确认到账，收款已入账。',
            'failed'    => 'PayPal 已拒绝该笔付款。',
            default     => 'PayPal 仍在确认中' . ($synced->pendingReason() !== '' ? '（' . $synced->pendingReason() . '）' : '') . '。',
        };

        return back()->with('success', $message);
    }

    public function requestRefund(Request $request, PaypalBTransaction $transaction): RedirectResponse
    {
        try {
            $data = $request->validate([
                'amount'          => ['required', 'regex:/^\d{1,12}(?:\.\d{1,4})?$/'],
                'note_to_payer'   => ['nullable', 'string', 'max:255'],
                'idempotency_key' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            ]);
            app(PaypalBRefundService::class)->request(
                $transaction,
                (string) $data['amount'],
                $data['note_to_payer'] ?? null,
                trim((string) ($request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? ''))) ?: null
            );

            return back()->with('success', '退款请求已提交，将由 PayPal 退款队列处理。');
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }
    }

    public function retryRefund(PaypalBRefund $refund): RedirectResponse
    {
        try {
            app(PaypalBRefundService::class)->retry($refund);

            return back()->with('success', '退款对账请求已重新投递。');
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }
    }

    public function updateFulfillmentEvidence(Request $request, PaypalBTransaction $transaction): RedirectResponse
    {
        $data = $request->validate([
            'delivered_at' => ['nullable', 'date'],
            'signed_by'    => ['nullable', 'string', 'max:255'],
            'proof_url'    => ['nullable', 'url', 'starts_with:https://', 'max:1000'],
            'note'         => ['nullable', 'string', 'max:2000'],
        ]);
        if (empty($data['delivered_at']) && empty($data['signed_by']) && empty($data['proof_url']) && empty($data['note'])) {
            return back()->withErrors(['fulfillment' => '至少填写一项签收或履约证据。']);
        }

        $evidence                      = (array) $transaction->fulfillment_evidence;
        $evidence['delivery_evidence'] = [
            'delivered_at' => $data['delivered_at'] ?? null,
            'signed_by'    => trim((string) ($data['signed_by'] ?? '')),
            'proof_url'    => trim((string) ($data['proof_url'] ?? '')),
            'note'         => trim((string) ($data['note'] ?? '')),
            'recorded_at'  => now()->toIso8601String(),
        ];
        $transaction->forceFill([
            'fulfillment_evidence'   => $evidence,
            'fulfillment_updated_at' => now(),
        ])->saveOrFail();

        return back()->with('success', 'B 站签收与履约证据已保存。');
    }

    private function validateAccount(Request $request, PaypalBAccount $account = null): array
    {
        return $request->validate([
            'name'                 => ['required', 'string', 'max:100', Rule::unique('paypal_b_accounts', 'name')->ignore($account?->id)],
            'account_type'         => ['required', Rule::in(PaypalBAccount::automaticApiAccountTypes())],
            'client_id'            => ['nullable', 'string', 'max:255'],
            'client_secret'        => ['nullable', 'string', 'max:1000'],
            'webhook_id'           => ['nullable', 'string', 'max:255'],
            'recipient_name'       => ['nullable', 'string', 'max:255'],
            'recipient_email'      => ['nullable', 'email', 'max:255'],
            'paypal_me_url'        => ['nullable', 'url', 'max:1000'],
            'supported_currencies' => ['required', 'string', 'max:255'],
            'wallet_enabled'       => ['nullable', 'boolean'],
            'card_enabled'         => ['nullable', 'boolean'],
            'priority'             => ['required', 'integer', 'min:0', 'max:999999'],
            'active'               => ['nullable', 'boolean'],
        ]);
    }

    private function assertCredentialMode(array $data, PaypalBAccount $account = null): void
    {
        if (! PaypalBAccount::isAutomaticApiAccountType($data['account_type'])) {
            throw ValidationException::withMessages([
                'account_type' => '自动收款只支持 PayPal 企业 API 或个人卖家 API 账号。',
            ]);
        }

        if (trim((string) ($data['client_id'] ?? ($account?->client_id ?? ''))) === '') {
            throw ValidationException::withMessages(['client_id' => '自动 API 账号必须填写 Client ID。']);
        }
        if (! $account && trim((string) ($data['client_secret'] ?? '')) === '') {
            throw ValidationException::withMessages(['client_secret' => '新增自动 API 账号必须填写 Secret。']);
        }
        if ($account && trim((string) ($data['client_secret'] ?? '')) === '' && ! $account->decryptedClientSecret()) {
            throw ValidationException::withMessages(['client_secret' => '自动 API 账号必须填写 Secret。']);
        }
        if (! empty($data['active']) && trim((string) ($data['webhook_id'] ?? ($account?->webhook_id ?? ''))) === '') {
            throw ValidationException::withMessages(['webhook_id' => '启用自动 API 账号前必须填写 Webhook ID。请先停用保存以生成账号 ID 和 Webhook 地址。']);
        }
        if (empty($data['wallet_enabled']) && empty($data['card_enabled'])) {
            throw ValidationException::withMessages(['wallet_enabled' => '账号至少要开启钱包或信用卡收款能力。']);
        }
    }

    private function fillAccount(PaypalBAccount $account, array $data): void
    {
        $account->fill([
            'name'                 => $data['name'],
            'account_type'         => $data['account_type'],
            'client_id'            => trim((string) ($data['client_id'] ?? '')) ?: null,
            'webhook_id'           => trim((string) ($data['webhook_id'] ?? '')) ?: null,
            'recipient_name'       => trim((string) ($data['recipient_name'] ?? '')) ?: null,
            'recipient_email'      => trim((string) ($data['recipient_email'] ?? '')) ?: null,
            'paypal_me_url'        => trim((string) ($data['paypal_me_url'] ?? '')) ?: null,
            'supported_currencies' => strtoupper(trim((string) $data['supported_currencies'])),
            'wallet_enabled'       => ! empty($data['wallet_enabled']),
            'card_enabled'         => ! empty($data['card_enabled']),
            'priority'             => (int) $data['priority'],
            'active'               => ! empty($data['active']),
        ]);
        if (trim((string) ($data['client_secret'] ?? '')) !== '') {
            $account->client_secret = trim((string) $data['client_secret']);
        }
    }
}
