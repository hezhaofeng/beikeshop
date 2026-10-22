{{--
  B 站支付页布局：套用本站主题（头部、页脚、样式），主体复刻原生 PayPal 插件的支付页：
  步骤条 → 卡图标 + “订单提交成功，请付款” → 黄色订单信息块 → PayPal 按钮区（由子视图提供）。
--}}
@extends('layout.master')

@php
  // 主题 master 布局只有在 request('iframe') 为真时才不渲染头部与页脚：支付页不需要站点导航和购物车，
  // 借用该开关去掉头部；页脚在下方由本布局自行包含，并隐藏“公司简介”栏。
  request()->merge(['iframe' => 1]);
@endphp

@section('title', 'PayPal')
@section('body-class', 'page-payment')

@push('header')
  @stack('head')
  <style>
    /* 页脚去掉“公司简介”整栏（站点名称、域名、介绍文案、社交图标），其余页脚内容保留。 */
    body.page-payment footer .footer-content > .row > .col-md-3.me-lg-5 { display: none !important; }
  </style>
@endpush

@push('add-scripts')
  @stack('scripts')
@endpush

@section('content')
  @php
    $amountText = '';
    if (isset($transaction)) {
        try {
            // 交易金额已经是交易币种下的实付金额：汇率固定传 1，只借用本站币种的符号与小数位格式化，
            // 不能再乘一次本站汇率（currency_format 默认会按该币种汇率换算）。
            $amountText = currency_format($transaction->amount, strtoupper((string) $transaction->currency), '1');
        } catch (\Throwable) {
            // 本站未配置该币种时退回“金额 币种”写法。
            $amountText = $transaction->amount . ' ' . strtoupper((string) $transaction->currency);
        }
    }
  @endphp
  <div class="container">
    @if (!is_mobile())
    <div class="row mt-5 mb-5 justify-content-center">
      <div class="col-12 col-md-9">@include('shared.steps', ['steps' => 3])</div>
    </div>
    @endif

    <div class="col-12">
      <div class="card order-wrap border">
        <div class="card-body main-body">
          <div class="order-top">
            <div class="left">
              <i class="bi bi-credit-card-2-back"></i>
            </div>
            <div class="right">
              <h3 class="order-title">@yield('paypal_title', __('shop/checkout.payment_please_pay'))</h3>
              @isset($transaction)
              <div class="order-info mb-lg-4 mb-2">
                <table class="table table-borderless">
                  <tbody>
                    <tr>
                      <td>{{ __('shop/checkout.payment_order_number') }}：<span class="fw-bold">{{ $transaction->order_number }}</span></td>
                      <td>{{ __('shop/checkout.payment_amounts_payable') }}：<span class="fw-bold">{{ $amountText }}</span></td>
                    </tr>
                    <tr>
                      <td>{{ __('shop/checkout.payment_payment_method') }}：<span class="fw-bold">PayPal</span></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              @endisset

              @yield('paypal_content')
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- master 因 iframe 开关不再渲染页脚，这里自行包含站点页脚（公司简介栏已由上方样式隐藏）。 --}}
  @include('layout.footer')
@endsection
