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
        $data = $this->validateAccount($request);
        if (PaypalBAccount::isAutomaticApiAccountType($data['account_type']) && trim((string) ($data['webhook_id'] ?? '')) === '') {
            $data['active'] = false;
        }
        $this->assertCredentialMode($data);
        $account = new PaypalBAccount;
        $this->fillAccount($account, $data);
        $account->saveOrFail();

        return back()->with('success', 'PayPal 收款账号已添加。');
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

    public function retryCallback(PaypalBTransaction $transaction): RedirectResponse
    {
        if (! in_array($transaction->status, ['completed', 'cancelled', 'failed', 'expired'], true)) {
            return back()->withErrors(['transaction' => '只有最终状态的会话可以重试回调。']);
        }

        if (! app(PaypalBCallbackService::class)->send($transaction, true)) {
            return back()->withErrors(['transaction' => 'A 站回调仍未成功，请检查 A 站地址、签名密钥和网络。']);
        }

        return back()->with('success', 'A 站回调已重试。');
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
