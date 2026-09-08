@php
  $setting = $plugin->getSetting() ?: [];
  $value = static fn (string $key, mixed $default = '') => old($key, $setting[$key] ?? $default);
@endphp

<form class="needs-validation" novalidate action="{{ admin_route('paypal_b.settings.update') }}" method="POST">
  @csrf
  {{ method_field('put') }}

  @if ($errors->any())
    <x-admin-alert type="danger" :msg="$errors->first()" class="mb-4"/>
  @endif

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">A/B 站连接</h6></div>
    <div class="card-body">
      <x-admin-form-input name="public_url" title="B 站公网地址" type="url" :required="true" :error="$errors->first('public_url')" value="{{ $value('public_url') }}">
        <div class="form-text text-secondary">生产环境填写 HTTPS 根地址；本地或测试环境可使用 HTTP，不带路径和查询参数。</div>
      </x-admin-form-input>
      <x-admin-form-input name="a_site_url" title="A 站公网地址" type="url" :required="true" :error="$errors->first('a_site_url')" value="{{ $value('a_site_url') }}">
        <div class="form-text text-secondary">填写安装 PaypalA 的 A 站根地址；生产环境必须使用 HTTPS，本地或测试环境可使用 HTTP。</div>
      </x-admin-form-input>
      <x-admin-form-input name="merchant_display_name" title="B 站收款主体名称" :required="true" :error="$errors->first('merchant_display_name')" value="{{ $value('merchant_display_name') }}">
        <div class="form-text text-secondary">支付页、PayPal 订单和消费者账单中使用的真实收款主体名称。</div>
      </x-admin-form-input>
      <x-admin-form-input name="customer_service_email" title="B 站客服邮箱" type="email" :required="true" :error="$errors->first('customer_service_email')" value="{{ $value('customer_service_email') }}" />
      <x-admin-form-input name="customer_service_phone" title="B 站客服电话" :required="false" :error="$errors->first('customer_service_phone')" value="{{ $value('customer_service_phone') }}" />
      <x-admin-form-input name="refund_policy_url" title="B 站退款政策地址" type="url" :required="true" :error="$errors->first('refund_policy_url')" value="{{ $value('refund_policy_url') }}">
        <div class="form-text text-secondary">生产环境必须使用 B 站同域 HTTPS 地址；本地或测试环境可使用 HTTP。</div>
      </x-admin-form-input>
      <x-admin-form-input name="privacy_policy_url" title="B 站隐私政策地址" type="url" :required="true" :error="$errors->first('privacy_policy_url')" value="{{ $value('privacy_policy_url') }}">
        <div class="form-text text-secondary">生产环境必须使用 B 站同域 HTTPS 地址；本地或测试环境可使用 HTTP。</div>
      </x-admin-form-input>
      <x-admin-form-input name="terms_url" title="B 站交易条款地址" type="url" :required="true" :error="$errors->first('terms_url')" value="{{ $value('terms_url') }}">
        <div class="form-text text-secondary">生产环境必须使用 B 站同域 HTTPS 地址；本地或测试环境可使用 HTTP。</div>
      </x-admin-form-input>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">PayPal 收款账号与 API 凭证</h6></div>
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div class="text-secondary">在收款账号管理中配置 PayPal Client ID、Client Secret 和 Webhook ID。凭证按收款账号加密保存，不与 A/B 双向签名密钥混用。</div>
      <a href="{{ admin_route('paypal_b.accounts.index') }}" class="btn btn-outline-primary text-nowrap">
        <i class="bi bi-key me-1" aria-hidden="true"></i>管理收款账号与 API 凭证
      </a>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">双向签名与 PayPal 环境</h6></div>
    <div class="card-body">
      <x-admin::form.row title="A 请求验签密钥" :required="true">
        <div class="input-group">
          <input id="paypal-b-request-verification-secret" name="a_request_verification_secret" type="password" class="form-control @error('a_request_verification_secret') is-invalid @enderror" value="{{ $value('a_request_verification_secret') }}" placeholder="A 请求验签密钥" required>
          <button type="button" class="btn btn-outline-secondary" data-password-toggle="paypal-b-request-verification-secret" title="显示密钥" aria-label="显示密钥">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
          <span class="invalid-feedback" role="alert">{{ $errors->first('a_request_verification_secret', __('common.error_required', ['name' => 'A 请求验签密钥'])) }}</span>
        </div>
        <div class="form-text text-secondary">与 A 站“A 到 B 请求签名密钥”一致，至少 32 位随机字符串。留空保存时会保留已保存的密钥。</div>
      </x-admin::form.row>
      <x-admin::form.row title="A 回调签名密钥" :required="true">
        <div class="input-group">
          <input id="paypal-b-callback-signing-secret" name="a_callback_signing_secret" type="password" class="form-control @error('a_callback_signing_secret') is-invalid @enderror" value="{{ $value('a_callback_signing_secret') }}" placeholder="A 回调签名密钥" required>
          <button type="button" class="btn btn-outline-secondary" data-password-toggle="paypal-b-callback-signing-secret" title="显示密钥" aria-label="显示密钥">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
          <span class="invalid-feedback" role="alert">{{ $errors->first('a_callback_signing_secret', __('common.error_required', ['name' => 'A 回调签名密钥'])) }}</span>
        </div>
        <div class="form-text text-secondary">与 A 站“B 到 A 回调验签密钥”一致，不能与上一项复用。留空保存时会保留已保存的密钥。</div>
      </x-admin::form.row>
      <x-admin-form-select name="sandbox_mode" title="PayPal 环境" :options="[['value' => '1', 'label' => '沙盒'], ['value' => '0', 'label' => '正式环境']]" value="{{ $value('sandbox_mode', 1) }}" />
      <x-admin-form-switch name="card_enabled" title="允许信用卡/借记卡收款" :value="(int) $value('card_enabled', 0)" />
      <div class="alert alert-light border">启用后，账号池中开启信用卡能力的企业或个人卖家 API 账号都必须在对应 PayPal 环境中开通 Advanced Card Payments。</div>
      <x-admin-form-switch name="wallet_enabled" title="允许 PayPal 钱包收款" :value="(int) $value('wallet_enabled', 1)" />
      <x-admin-form-input name="request_timeout_seconds" title="网络请求超时" type="number" min="3" max="30" step="1" groupRight="秒" :required="true" :error="$errors->first('request_timeout_seconds')" value="{{ $value('request_timeout_seconds', 10) }}" />
      <x-admin-form-input name="account_cooldown_minutes" title="可重试故障冷却时间" type="number" min="1" max="1440" step="1" groupRight="分钟" :required="true" :error="$errors->first('account_cooldown_minutes')" value="{{ $value('account_cooldown_minutes', 10) }}">
        <div class="form-text text-secondary">账号发生临时故障时会暂停加入新会话轮询；已创建或待对账的会话始终固定使用原账号。</div>
      </x-admin-form-input>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <x-admin-form-switch name="status" title="启用 PaypalB" :value="(int) $value('status', 0)" />
      <div class="alert alert-warning mb-0">支付固定采用 B 站顶层托管模式。B 站是消费者看到的收款、退款、客服和争议处理主体；请确保上述名称与政策和 PayPal 审核资料一致。</div>
    </div>
  </div>

  <x-admin::form.row title="">
    <button type="submit" class="btn btn-primary btn-lg">保存配置</button>
  </x-admin::form.row>
</form>

<script>
  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.passwordToggle);
      if (!input) return;

      const showPassword = input.type === 'password';
      const label = showPassword ? '隐藏密钥' : '显示密钥';
      input.type = showPassword ? 'text' : 'password';
      button.setAttribute('title', label);
      button.setAttribute('aria-label', label);
      button.querySelector('i')?.classList.toggle('bi-eye', !showPassword);
      button.querySelector('i')?.classList.toggle('bi-eye-slash', showPassword);
    });
  });
</script>
