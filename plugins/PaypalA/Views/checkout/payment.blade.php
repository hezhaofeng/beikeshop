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

    <div class="d-flex flex-column flex-sm-row gap-3">
      @if($paypal_a_instruction['wallet_enabled'])
        {{-- form 没有 referrerpolicy 属性，HTML 规范里只认 rel 上的 noreferrer 关键字。 --}}
        <form method="POST" action="{{ $paypal_a_instruction['start_url'] }}" rel="noreferrer">
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
  })();
</script>
