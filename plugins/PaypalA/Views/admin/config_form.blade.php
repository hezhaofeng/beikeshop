@php
  $setting = $plugin->getSetting() ?: [];
  $value = static fn (string $key, mixed $default = '') => old($key, $setting[$key] ?? $default);
@endphp

<form class="needs-validation" novalidate action="{{ admin_route('paypal_a.settings.update') }}" method="POST">
  @csrf
  {{ method_field('put') }}

  @if ($errors->any())
    <x-admin-alert type="danger" :msg="$errors->first()" class="mb-4"/>
  @endif

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">A/B 站连接</h6></div>
    <div class="card-body">
      <x-admin-form-input name="public_url" title="A 站公网地址" type="url" :required="true" :error="$errors->first('public_url')" value="{{ $value('public_url') }}">
        <div class="form-text text-secondary">生产环境填写 HTTPS 根地址；本地或测试环境可使用 HTTP，不带路径和查询参数。</div>
      </x-admin-form-input>
      <x-admin-form-input name="b_site_url" title="B 站网关地址" type="url" :required="true" :error="$errors->first('b_site_url')" value="{{ $value('b_site_url') }}">
        <div class="form-text text-secondary">填写安装 PaypalB 的 B 站根地址；生产环境必须使用 HTTPS，本地或测试环境可使用 HTTP。</div>
      </x-admin-form-input>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">双向签名</h6></div>
    <div class="card-body">
      <x-admin::form.row title="A 到 B 请求签名密钥" :required="true">
        <div class="input-group">
          <input id="paypal-a-request-signing-secret" name="request_signing_secret" type="password" class="form-control @error('request_signing_secret') is-invalid @enderror" value="{{ $value('request_signing_secret') }}" placeholder="A 到 B 请求签名密钥" required>
          <button type="button" class="btn btn-outline-secondary" data-password-toggle="paypal-a-request-signing-secret" title="显示密钥" aria-label="显示密钥">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
          <span class="invalid-feedback" role="alert">{{ $errors->first('request_signing_secret', __('common.error_required', ['name' => 'A 到 B 请求签名密钥'])) }}</span>
        </div>
        <div class="form-text text-secondary">与 B 站“A 请求验签密钥”一致，至少 32 位随机字符串。留空保存时会保留已保存的密钥。</div>
      </x-admin::form.row>
      <x-admin::form.row title="B 到 A 回调验签密钥" :required="true">
        <div class="input-group">
          <input id="paypal-a-callback-verification-secret" name="callback_verification_secret" type="password" class="form-control @error('callback_verification_secret') is-invalid @enderror" value="{{ $value('callback_verification_secret') }}" placeholder="B 到 A 回调验签密钥" required>
          <button type="button" class="btn btn-outline-secondary" data-password-toggle="paypal-a-callback-verification-secret" title="显示密钥" aria-label="显示密钥">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
          <span class="invalid-feedback" role="alert">{{ $errors->first('callback_verification_secret', __('common.error_required', ['name' => 'B 到 A 回调验签密钥'])) }}</span>
        </div>
        <div class="form-text text-secondary">与 B 站“A 回调签名密钥”一致，不能与上一项复用。留空保存时会保留已保存的密钥。</div>
      </x-admin::form.row>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">支付控制</h6></div>
    <div class="card-body">
      <x-admin-form-input name="payment_expiration_minutes" title="支付会话有效期" type="number" min="5" max="120" step="1" groupRight="分钟" :required="true" :error="$errors->first('payment_expiration_minutes')" value="{{ $value('payment_expiration_minutes', 30) }}" />
      <x-admin-form-input name="request_timeout_seconds" title="B 站请求超时" type="number" min="3" max="30" step="1" groupRight="秒" :required="true" :error="$errors->first('request_timeout_seconds')" value="{{ $value('request_timeout_seconds', 10) }}" />
      <x-admin-form-select
        name="bridge_item_source"
        title="订单商品明细模式"
        :options="[
          ['value' => 'a_order', 'label' => '原商品明细'],
          ['value' => 'mapped', 'label' => '映射商品明细'],
        ]"
        value="{{ $value('bridge_item_source', 'a_order') }}">
        <div class="form-text text-secondary">映射模式需要启用 CyberCloak 并存在当前已发布、已确认的 SKU 映射。仅向 B/PayPal 替换商品名称和 SKU；A 站订单、数量、价格、金额与履约信息不变。</div>
      </x-admin-form-select>
      <x-admin-form-switch name="wallet_enabled" title="启用 PayPal 钱包付款" :value="(int) $value('wallet_enabled', 1)" />
      <x-admin-form-switch name="card_enabled" title="启用信用卡/借记卡付款" :value="(int) $value('card_enabled', 0)" />
      <div class="alert alert-light border">固定使用 B 站顶层托管支付。信用卡付款使用 B 站 PayPal Card Fields；A/B 两站都需开启，且被轮询到的 PayPal 账号必须具备 Advanced Card Payments 资格。</div>
      <x-admin-form-switch name="status" title="启用 PaypalA" :value="(int) $value('status', 0)" />
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
