<?php

namespace Plugin\PaypalA\Controllers;

use Beike\Repositories\SettingRepo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Plugin\PaypalA\Services\PaypalAPaymentService;

class PaypalAAdminController
{
    /**
     * 保存 A 站配置。密钥输入留空时保留已保存值，避免后台编辑普通字段时清空桥接密钥。
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $fields  = $request->except(['_token', '_method']);
        $setting = plugin_setting('paypal_a', []);
        $setting = is_array($setting) ? $setting : [];

        foreach (['request_signing_secret', 'callback_verification_secret'] as $secret) {
            if (trim((string) ($fields[$secret] ?? '')) === '' && isset($setting[$secret])) {
                $fields[$secret] = $setting[$secret];
            }
        }
        $plugin = plugin('paypal_a');
        $plugin?->validate($fields)->validate();
        PaypalAPaymentService::bridgeConfiguration($fields);
        SettingRepo::update('plugin', 'paypal_a', $fields);

        return redirect(admin_route('plugins.edit', ['code' => 'paypal_a']))
            ->with('success', trans('common.updated_success'));
    }
}
