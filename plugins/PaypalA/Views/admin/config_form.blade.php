@php
  $setting = $plugin->getSetting() ?: [];
  $value = static fn (string $key, mixed $default = '') => old($key, $setting[$key] ?? $default);
  $fallbackSummary = ['total' => 0, 'reasons' => []];
  try {
    $fallbackSummary = \Plugin\PaypalA\Services\PaypalAPaymentService::embeddedFallbackSummary();
  } catch (\Throwable) {
    // 缓存表未迁移时不影响配置页打开。
  }
  // 高级选项默认折叠；校验出错或已改过默认值时展开，避免报错字段藏在折叠区里。
  $advancedKeys = ['payment_expiration_minutes', 'request_timeout_seconds', 'wallet_enabled', 'card_enabled', 'checkout_mode', 'embedded_fallback', 'embedded_ready_timeout_seconds'];
  $advancedOpen = $errors->hasAny($advancedKeys)
    || (string) $value('checkout_mode', 'redirect') !== 'redirect'
    || (int) $value('card_enabled', 0) === 1
    || (int) $value('wallet_enabled', 1) !== 1;
@endphp

<form class="needs-validation" novalidate action="{{ admin_route('paypal_a.settings.update') }}" method="POST">
  @csrf
  {{ method_field('put') }}

  @if ($errors->any())
    <x-admin-alert type="danger" :msg="$errors->first()" class="mb-4"/>
  @endif

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">站点连接</h6></div>
    <div class="card-body">
      <x-admin-form-input name="public_url" title="本站公网地址" type="url" :required="true" :error="$errors->first('public_url')" value="{{ $value('public_url') }}">
        <div class="form-text text-secondary">本站根地址，用于支付回跳与服务端通知。生产环境填写 HTTPS，不带路径和查询参数。</div>
      </x-admin-form-input>
      <x-admin-form-input name="b_site_url" title="收款站地址" type="url" :required="true" :error="$errors->first('b_site_url')" value="{{ $value('b_site_url') }}">
        <div class="form-text text-secondary">安装 PaypalB 的收款站根地址，必须与本站不同域名或端口。</div>
      </x-admin-form-input>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">签名密钥</h6></div>
    <div class="card-body">
      <x-admin::form.row title="请求签名密钥" :required="true">
        <div class="input-group">
          <input id="paypal-a-request-signing-secret" name="request_signing_secret" type="password" class="form-control @error('request_signing_secret') is-invalid @enderror" value="{{ $value('request_signing_secret') }}" placeholder="请求签名密钥" required>
          <button type="button" class="btn btn-outline-secondary" data-password-toggle="paypal-a-request-signing-secret" title="显示密钥" aria-label="显示密钥">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
          <span class="invalid-feedback" role="alert">{{ $errors->first('request_signing_secret', __('common.error_required', ['name' => '请求签名密钥'])) }}</span>
        </div>
        <div class="form-text text-secondary">与收款站 PaypalB 的“请求验签密钥”一致，至少 32 位随机字符串。留空保存时保留已保存的密钥。</div>
      </x-admin::form.row>
      <x-admin::form.row title="回调验签密钥" :required="true">
        <div class="input-group">
          <input id="paypal-a-callback-verification-secret" name="callback_verification_secret" type="password" class="form-control @error('callback_verification_secret') is-invalid @enderror" value="{{ $value('callback_verification_secret') }}" placeholder="回调验签密钥" required>
          <button type="button" class="btn btn-outline-secondary" data-password-toggle="paypal-a-callback-verification-secret" title="显示密钥" aria-label="显示密钥">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
          <span class="invalid-feedback" role="alert">{{ $errors->first('callback_verification_secret', __('common.error_required', ['name' => '回调验签密钥'])) }}</span>
        </div>
        <div class="form-text text-secondary">与收款站 PaypalB 的“回调签名密钥”一致，不能与上一项相同。留空保存时保留已保存的密钥。</div>
      </x-admin::form.row>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">支付设置</h6></div>
    <div class="card-body">
      <x-admin-form-select
        name="bridge_item_source"
        title="提交给 PayPal 的商品明细"
        :options="[
          ['value' => 'a_order', 'label' => '原商品明细'],
          ['value' => 'mapped', 'label' => '映射商品明细'],
        ]"
        value="{{ $value('bridge_item_source', 'a_order') }}">
        <div class="form-text text-secondary">映射模式使用 CyberCloak 已发布、已确认的 SKU 映射替换提交给 PayPal 的商品名称和 SKU；本站订单、数量、价格不变。映射不可用时自动回退为原商品明细并写入日志，不影响买家付款。</div>
      </x-admin-form-select>
      <x-admin-form-switch name="status" title="启用 PaypalA" :value="(int) $value('status', 0)" />
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h6 class="card-title mb-0">高级选项</h6>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#paypal-a-advanced" aria-expanded="{{ $advancedOpen ? 'true' : 'false' }}" aria-controls="paypal-a-advanced">
        {{ $advancedOpen ? '收起' : '展开' }}
      </button>
    </div>
    <div id="paypal-a-advanced" class="collapse {{ $advancedOpen ? 'show' : '' }}">
      <div class="card-body">
        <div class="alert alert-light border">以下选项保持默认即可正常使用：跳转到收款站付款、仅 PayPal 钱包、会话 30 分钟、请求超时 10 秒。</div>
        <x-admin-form-input name="payment_expiration_minutes" title="支付会话有效期" type="number" min="5" max="120" step="1" groupRight="分钟" :required="true" :error="$errors->first('payment_expiration_minutes')" value="{{ $value('payment_expiration_minutes', 30) }}" />
        <x-admin-form-input name="request_timeout_seconds" title="收款站请求超时" type="number" min="3" max="30" step="1" groupRight="秒" :required="true" :error="$errors->first('request_timeout_seconds')" value="{{ $value('request_timeout_seconds', 10) }}" />
        <x-admin-form-switch name="wallet_enabled" title="启用 PayPal 钱包付款" :value="(int) $value('wallet_enabled', 1)" />
        <x-admin-form-switch name="card_enabled" title="启用信用卡/借记卡付款" :value="(int) $value('card_enabled', 0)" />
        <div class="form-text text-secondary mb-3">信用卡付款使用收款站的 PayPal Card Fields；两站都需开启，且收款账号必须具备 Advanced Card Payments 资格。</div>
        <x-admin-form-select
          name="checkout_mode"
          title="支付页形态"
          :options="[
            ['value' => 'redirect', 'label' => '跳转到收款站支付页（推荐）'],
            ['value' => 'embedded', 'label' => '在本站支付页内嵌收款站结账页'],
          ]"
          value="{{ $value('checkout_mode', 'redirect') }}">
          <div class="form-text text-secondary">内嵌模式需要收款站开启“允许内嵌支付页”，且收款站配置的本站地址与买家浏览器中的地址完全一致。已创建的会话按创建时的形态走完。</div>
        </x-admin-form-select>
        <x-admin-form-switch name="embedded_fallback" title="内嵌失败时自动降级为跳转" :value="(int) $value('embedded_fallback', 1)" />
        <x-admin-form-input name="embedded_ready_timeout_seconds" title="内嵌就绪超时" type="number" min="5" max="20" step="1" groupRight="秒" :required="false" :error="$errors->first('embedded_ready_timeout_seconds')" value="{{ $value('embedded_ready_timeout_seconds', 8) }}" />
        <x-admin::form.row title="最近 24 小时降级次数">
          @if($fallbackSummary['total'] > 0)
            <div class="fw-bold text-warning fs-5">{{ $fallbackSummary['total'] }}</div>
            <div class="small text-secondary">
              @foreach($fallbackSummary['reasons'] as $reason => $count)
                <span class="me-3">{{ $reason }}：{{ $count }}</span>
              @endforeach
            </div>
            <div class="small text-secondary mt-1">ready_timeout 持续出现通常意味着收款站配置的本站地址与实际访问地址不一致；b_site_rejected 表示收款站未开启内嵌。</div>
          @else
            <div class="text-secondary">0</div>
          @endif
        </x-admin::form.row>
      </div>
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
  document.querySelectorAll('[data-bs-toggle="collapse"][data-bs-target^="#paypal-"]').forEach((button) => {
    const target = document.querySelector(button.dataset.bsTarget);
    if (!target) return;
    target.addEventListener('shown.bs.collapse', () => { button.textContent = '收起'; });
    target.addEventListener('hidden.bs.collapse', () => { button.textContent = '展开'; });
  });
</script>
