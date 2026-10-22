@php
  $setting = $plugin->getSetting() ?: [];
  $value = static fn (string $key, mixed $default = '') => old($key, $setting[$key] ?? $default);
  // 高级选项默认折叠；校验出错或已改过默认值时展开，避免报错字段藏在折叠区里。
  $advancedKeys = [
    'customer_service_phone', 'refund_policy_url', 'privacy_policy_url', 'terms_url',
    'embedded_enabled', 'mirror_order_timing', 'mirror_order_deduct_stock',
    'card_enabled', 'wallet_enabled', 'native_payload_mode', 'request_timeout_seconds', 'account_cooldown_minutes',
  ];
  $advancedOpen = $errors->hasAny($advancedKeys)
    || (int) $value('embedded_enabled', 0) === 1
    || (int) $value('card_enabled', 0) === 1
    || (int) $value('wallet_enabled', 1) !== 1
    || (int) $value('native_payload_mode', 0) === 1
    || (string) $value('mirror_order_timing', 'on_completed') !== 'on_completed';
@endphp

<form class="needs-validation" novalidate action="{{ admin_route('paypal_b.settings.update') }}" method="POST">
  @csrf
  {{ method_field('put') }}

  @if ($errors->any())
    <x-admin-alert type="danger" :msg="$errors->first()" class="mb-4"/>
  @endif

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">基础配置</h6></div>
    <div class="card-body">
      <x-admin-form-input name="public_url" title="本站公网地址" type="url" :required="true" :error="$errors->first('public_url')" value="{{ $value('public_url') }}">
        <div class="form-text text-secondary">本站根地址。生产环境填写 HTTPS，不带路径和查询参数。</div>
      </x-admin-form-input>
      <x-admin-form-input name="merchant_display_name" title="收款主体名称" :required="true" :error="$errors->first('merchant_display_name')" value="{{ $value('merchant_display_name') }}">
        <div class="form-text text-secondary">PayPal 订单和消费者账单中显示的真实收款主体名称，须与 PayPal 审核资料一致。</div>
      </x-admin-form-input>
      <x-admin-form-input name="customer_service_email" title="客服邮箱" type="email" :required="true" :error="$errors->first('customer_service_email')" value="{{ $value('customer_service_email') }}" />
      <x-admin-form-select name="sandbox_mode" title="PayPal 环境" :options="[['value' => '1', 'label' => '沙盒'], ['value' => '0', 'label' => '正式环境']]" value="{{ $value('sandbox_mode', 1) }}" />
      <x-admin-form-switch name="status" title="启用 PaypalB" :value="(int) $value('status', 0)" />
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">PayPal 收款账号与 API 凭证</h6></div>
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div class="text-secondary">在收款账号管理中配置 PayPal Client ID、Client Secret 和 Webhook ID。凭证按收款账号加密保存。</div>
      <a href="{{ admin_route('paypal_b.accounts.index') }}" class="btn btn-outline-primary text-nowrap">
        <i class="bi bi-key me-1" aria-hidden="true"></i>管理收款账号与 API 凭证
      </a>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">跨站收款（为下单站收款）</h6></div>
    <div class="card-body">
      <x-admin-form-switch name="bridge_enabled" title="启用跨站收款" :value="(int) $value('bridge_enabled', !empty($setting['a_site_url']))" />
      <div class="form-text text-secondary mb-3">关闭时本插件仅作为本站的 PayPal 支付方式；开启后需填写下单站地址和两组密钥，与下单站 PaypalA 的配置一一对应。</div>
      <x-admin-form-input name="a_site_url" title="下单站地址" type="url" :required="false" :error="$errors->first('a_site_url')" value="{{ $value('a_site_url') }}">
        <div class="form-text text-secondary">安装 PaypalA 的下单站根地址，必须与本站不同域名或端口。</div>
      </x-admin-form-input>
      <x-admin::form.row title="请求验签密钥" :required="false">
        <div class="input-group">
          <input id="paypal-b-request-verification-secret" name="a_request_verification_secret" type="password" class="form-control @error('a_request_verification_secret') is-invalid @enderror" value="{{ $value('a_request_verification_secret') }}" placeholder="请求验签密钥">
          <button type="button" class="btn btn-outline-secondary" data-password-toggle="paypal-b-request-verification-secret" title="显示密钥" aria-label="显示密钥">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
          <span class="invalid-feedback" role="alert">{{ $errors->first('a_request_verification_secret', __('common.error_required', ['name' => '请求验签密钥'])) }}</span>
        </div>
        <div class="form-text text-secondary">与下单站 PaypalA 的“请求签名密钥”一致，至少 32 位随机字符串。留空保存时保留已保存的密钥。</div>
      </x-admin::form.row>
      <x-admin::form.row title="回调签名密钥" :required="false">
        <div class="input-group">
          <input id="paypal-b-callback-signing-secret" name="a_callback_signing_secret" type="password" class="form-control @error('a_callback_signing_secret') is-invalid @enderror" value="{{ $value('a_callback_signing_secret') }}" placeholder="回调签名密钥">
          <button type="button" class="btn btn-outline-secondary" data-password-toggle="paypal-b-callback-signing-secret" title="显示密钥" aria-label="显示密钥">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
          <span class="invalid-feedback" role="alert">{{ $errors->first('a_callback_signing_secret', __('common.error_required', ['name' => '回调签名密钥'])) }}</span>
        </div>
        <div class="form-text text-secondary">与下单站 PaypalA 的“回调验签密钥”一致，不能与上一项相同。留空保存时保留已保存的密钥。</div>
      </x-admin::form.row>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h6 class="card-title mb-0">高级选项</h6>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#paypal-b-advanced" aria-expanded="{{ $advancedOpen ? 'true' : 'false' }}" aria-controls="paypal-b-advanced">
        {{ $advancedOpen ? '收起' : '展开' }}
      </button>
    </div>
    <div id="paypal-b-advanced" class="collapse {{ $advancedOpen ? 'show' : '' }}">
      <div class="card-body">
        <div class="alert alert-light border">以下选项保持默认即可正常使用：仅 PayPal 钱包、完整明细提交、收款成功后生成本站订单、内置政策页。</div>

        <h6 class="text-secondary mt-2 mb-3">客服与政策页</h6>
        <x-admin-form-input name="customer_service_phone" title="客服电话" :required="false" :error="$errors->first('customer_service_phone')" value="{{ $value('customer_service_phone') }}" />
        <x-admin-form-input name="refund_policy_url" title="退款政策地址" type="url" :required="false" :error="$errors->first('refund_policy_url')" value="{{ $value('refund_policy_url') }}">
          <div class="form-text text-secondary">留空使用插件内置政策页；填写后必须是本站同域地址。隐私政策、交易条款同理。</div>
        </x-admin-form-input>
        <x-admin-form-input name="privacy_policy_url" title="隐私政策地址" type="url" :required="false" :error="$errors->first('privacy_policy_url')" value="{{ $value('privacy_policy_url') }}" />
        <x-admin-form-input name="terms_url" title="交易条款地址" type="url" :required="false" :error="$errors->first('terms_url')" value="{{ $value('terms_url') }}" />

        <h6 class="text-secondary mt-4 mb-3">付款方式与提交格式</h6>
        <x-admin-form-switch name="wallet_enabled" title="允许 PayPal 钱包收款" :value="(int) $value('wallet_enabled', 1)" />
        <x-admin-form-switch name="card_enabled" title="允许信用卡/借记卡收款" :value="(int) $value('card_enabled', 0)" />
        <div class="form-text text-secondary mb-3">启用信用卡后，账号池中开启信用卡能力的 API 账号必须在对应 PayPal 环境开通 Advanced Card Payments。</div>
        <x-admin-form-switch name="native_payload_mode" title="兼容模式：精简提交" :value="(int) $value('native_payload_mode', 0)" />
        <div class="form-text text-secondary mb-3">开启后向 PayPal 建单只提交订单号、金额和商品名称，不提交收货地址；实物订单由买家在 PayPal 页面自选地址，PayPal 记录的地址与订单不一致时会写入订单备注。修改只影响之后新建的支付会话。</div>

        <h6 class="text-secondary mt-4 mb-3">跨站订单</h6>
        <x-admin-form-select
          name="mirror_order_timing"
          title="本站订单生成时机"
          :options="[
            ['value' => 'on_completed', 'label' => '收款成功后（已支付）'],
            ['value' => 'on_session', 'label' => '下单站发起支付时（待支付）'],
          ]"
          value="{{ $value('mirror_order_timing', 'on_completed') }}">
          <div class="form-text text-secondary">跨站收款会在本站生成一条订单供后台查看。商品行按本站目录匹配（映射 SKU → 原 SKU → 商品名称）并采用本站售价，差额记为“价格调整”费用行，订单总额等于 PayPal 实收。</div>
        </x-admin-form-select>
        <x-admin-form-switch name="mirror_order_deduct_stock" title="跨站订单扣减本站库存" :value="(int) $value('mirror_order_deduct_stock', 0)" />
        <div class="form-text text-secondary mb-3">默认关闭：跨站订单转已支付时不扣减本站库存、不累计销量、不发送本站邮件。</div>
        <x-admin-form-switch name="embedded_enabled" title="允许下单站内嵌支付页" :value="(int) $value('embedded_enabled', 0)" />
        <div class="form-text text-secondary mb-3">开启后下单站可在自己的支付页内以 iframe 加载本站结账页；只对上方“下单站地址”这一个来源放开嵌入，且地址必须与买家浏览器中的地址完全一致。</div>

        <h6 class="text-secondary mt-4 mb-3">网络</h6>
        <x-admin-form-input name="request_timeout_seconds" title="网络请求超时" type="number" min="3" max="30" step="1" groupRight="秒" :required="true" :error="$errors->first('request_timeout_seconds')" value="{{ $value('request_timeout_seconds', 10) }}" />
        <x-admin-form-input name="account_cooldown_minutes" title="可重试故障冷却时间" type="number" min="1" max="1440" step="1" groupRight="分钟" :required="true" :error="$errors->first('account_cooldown_minutes')" value="{{ $value('account_cooldown_minutes', 10) }}">
          <div class="form-text text-secondary">账号发生临时故障时暂停加入新会话轮询；已创建或待对账的会话始终固定使用原账号。</div>
        </x-admin-form-input>
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
