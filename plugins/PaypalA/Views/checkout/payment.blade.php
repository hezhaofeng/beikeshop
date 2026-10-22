<div class="card mt-4" id="paypal-a-payment">
  <div class="card-body">
    <h5 class="card-title">{{ trans('PaypalA::common.title') }}</h5>
    <p class="text-secondary mb-4">{{ trans('PaypalA::common.choose_method') }}</p>

    @if($errors->any())
      {{-- 支付页模板不渲染全局 $errors，跳转失败时必须在此展示原因，否则表现为“点击无反应”。 --}}
      <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <dl class="row mb-4">
      <dt class="col-sm-4">{{ trans('PaypalA::common.order_number') }}</dt>
      <dd class="col-sm-8"><code>{{ $paypal_a_instruction['order_number'] }}</code></dd>
      <dt class="col-sm-4">{{ trans('PaypalA::common.amount_payable') }}</dt>
      <dd class="col-sm-8"><strong>{{ $paypal_a_instruction['amount'] }} {{ $paypal_a_instruction['currency'] }}</strong></dd>
    </dl>

    <div class="alert alert-light border">
      {{ trans('PaypalA::common.session_notice', ['minutes' => $paypal_a_instruction['expires_in']]) }}
    </div>

    @if(!empty($paypal_a_instruction['capture_pending']))
      {{-- 买家已在 PayPal 付款、资金待确认：只提示等待，不再显示付款按钮，避免重复付款。 --}}
      <div class="alert alert-warning mb-0" role="status">
        {{ trans('PaypalA::common.capture_pending_notice') }}
      </div>
    @elseif(($paypal_a_instruction['checkout_mode'] ?? 'redirect') === 'embedded')
      @include('PaypalA::checkout.embedded')
    @else
    @php
      // 钱包已启用且本次进入支付页没有错误时自动前往 PayPal，买家无需再点一次付款按钮。
      $autoStartWallet = $paypal_a_instruction['wallet_enabled'] && ! $errors->any();
    @endphp
    @if($autoStartWallet)
      <div class="alert alert-info mb-3" role="status" id="paypal-a-auto-redirect">
        {{ trans('PaypalA::common.embedded_redirecting') }}
      </div>
    @endif
    <div class="d-flex flex-column flex-sm-row gap-3">
      @if($paypal_a_instruction['wallet_enabled'])
        {{-- form 没有 referrerpolicy 属性，HTML 规范里只认 rel 上的 noreferrer 关键字。 --}}
        <form method="POST" action="{{ $paypal_a_instruction['start_url'] }}" rel="noreferrer" @if($autoStartWallet) data-auto-submit="1" @endif>
          @csrf
          <input type="hidden" name="email" value="{{ $paypal_a_instruction['guest_email'] }}">
          <input type="hidden" name="method" value="wallet">
          <button class="btn btn-primary" type="submit">{{ trans('PaypalA::common.pay_with_wallet') }}</button>
        </form>
      @endif

      @if($paypal_a_instruction['card_enabled'])
        <form method="POST" action="{{ $paypal_a_instruction['start_url'] }}" rel="noreferrer">
          @csrf
          <input type="hidden" name="email" value="{{ $paypal_a_instruction['guest_email'] }}">
          <input type="hidden" name="method" value="card">
          <button class="btn btn-outline-primary" type="submit">{{ trans('PaypalA::common.pay_with_card') }}</button>
        </form>
      @endif
    </div>
    @endif
  </div>
</div>

<script>
  (() => {
    document.querySelectorAll('#paypal-a-payment form').forEach((form) => {
      form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        if (button) {
          button.disabled = true;
          button.setAttribute('aria-busy', 'true');
        }
      });
    });
    // 自动跳转只在首次进入支付页时触发；从 PayPal 返回或浏览器回退时不再自动提交，按钮仍可手动点击。
    const autoForm = document.querySelector('#paypal-a-payment form[data-auto-submit]');
    if (autoForm) {
      const key = 'paypal-a-auto-' + autoForm.action;
      if (!window.sessionStorage.getItem(key)) {
        window.sessionStorage.setItem(key, '1');
        window.setTimeout(() => (autoForm.requestSubmit ? autoForm.requestSubmit() : autoForm.submit()), 300);
      } else {
        const notice = document.getElementById('paypal-a-auto-redirect');
        if (notice) notice.remove();
      }
    }
  })();
</script>
