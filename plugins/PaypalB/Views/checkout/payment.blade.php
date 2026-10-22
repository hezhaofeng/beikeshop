<div class="card mt-4" id="paypal-b-payment">
  <div class="card-body">
    <h5 class="card-title">{{ trans('PaypalB::common.local_title') }}</h5>
    <p class="text-secondary mb-4">{{ trans('PaypalB::common.local_subtitle') }}</p>

    @if(!empty($paypal_b_error))
      <div class="alert alert-danger" role="alert">
        {{ $paypal_b_error }}
      </div>
    @elseif($errors->any())
      {{-- 主题的支付页模板不渲染全局 $errors，失败原因必须在此展示。 --}}
      <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    @if(empty($paypal_b_error))
      <dl class="row mb-4">
        <dt class="col-sm-4">{{ trans('PaypalB::common.order_number') }}</dt>
        <dd class="col-sm-8"><code>{{ $paypal_b_instruction['order_number'] }}</code></dd>
        <dt class="col-sm-4">{{ trans('PaypalB::common.amount_payable') }}</dt>
        <dd class="col-sm-8"><strong>{{ $paypal_b_instruction['amount'] }} {{ $paypal_b_instruction['currency'] }}</strong></dd>
      </dl>

      <div class="alert alert-light border">
        {{ trans('PaypalB::common.local_session_notice', ['minutes' => $paypal_b_instruction['expires_in']]) }}
      </div>

      @if($paypal_b_instruction['wallet_enabled'])
        @php
          $wallet = [
              'client_id'       => $paypal_b_instruction['client_id'],
              'currency'        => $paypal_b_instruction['currency'],
              'paypal_locale'   => $paypal_b_instruction['paypal_locale'],
              'paypal_order_id' => $paypal_b_instruction['paypal_order_id'],
              'capture_url'     => $paypal_b_instruction['capture_url'],
              'status_url'      => $paypal_b_instruction['status_url'],
              'capture_token'   => $paypal_b_instruction['capture_token'],
              'return_url'      => $paypal_b_instruction['return_url'],
              // 商城会话内轮询，复用 cookie 以免每次状态查询都新建一条 session 记录。
              'credentials'     => 'same-origin',
          ];
        @endphp
        {{-- 与 B 站托管钱包页共用同一份按钮与捕获脚本，行为不允许各自漂移。 --}}
        @include('PaypalB::checkout.partials.wallet_buttons', ['wallet' => $wallet])
      @elseif(!empty($paypal_b_instruction['card_checkout_url']))
        {{-- 钱包未启用时，信用卡仍需在 B 站顶层托管页用 Card Fields 完成。 --}}
        <a class="btn btn-primary" href="{{ $paypal_b_instruction['card_checkout_url'] }}" rel="noreferrer">
          {{ trans('PaypalB::common.local_pay_with_card') }}
        </a>
      @endif
    @endif
  </div>
</div>
