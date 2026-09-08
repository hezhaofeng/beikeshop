<div class="card mt-4" id="paypal-b-payment">
  <div class="card-body">
    <h5 class="card-title">{{ trans('PaypalB::common.local_title') }}</h5>
    <p class="text-secondary mb-4">{{ trans('PaypalB::common.local_subtitle') }}</p>

    @if($errors->any())
      {{-- 主题的支付页模板不渲染全局 $errors，失败原因必须在此展示。 --}}
      <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <dl class="row mb-4">
      <dt class="col-sm-4">{{ trans('PaypalB::common.order_number') }}</dt>
      <dd class="col-sm-8"><code>{{ $paypal_b_instruction['order_number'] }}</code></dd>
      <dt class="col-sm-4">{{ trans('PaypalB::common.amount_payable') }}</dt>
      <dd class="col-sm-8"><strong>{{ $paypal_b_instruction['amount'] }} {{ $paypal_b_instruction['currency'] }}</strong></dd>
    </dl>

    <div class="alert alert-light border">
      {{ trans('PaypalB::common.local_session_notice', ['minutes' => $paypal_b_instruction['expires_in']]) }}
    </div>

    <a class="btn btn-primary" href="{{ $paypal_b_instruction['checkout_url'] }}">
      {{ trans('PaypalB::common.local_pay_now') }}
    </a>
  </div>
</div>
