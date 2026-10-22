@extends('PaypalB::layout.checkout')

@php
  $wallet = [
      'client_id'       => $account->client_id,
      'currency'        => $transaction->currency,
      'paypal_locale'   => $paypal_locale,
      'paypal_order_id' => $transaction->paypal_order_id,
      'capture_url'     => $capture_url,
      'status_url'      => $status_url,
      'capture_token'   => $capture_token,
      'return_url'      => $return_url,
  ];
@endphp

@section('title', trans('PaypalB::common.wallet_title'))

@section('content')
  {{-- 与原生 PayPal 插件支付页一致：只展示订单号、金额和 PayPal 按钮，没有站点标题和商品清单。 --}}
  @include('PaypalB::checkout.summary')

  {{-- 按钮、SDK 与捕获脚本统一放在共用片段，与本地支付页保持同一套行为。 --}}
  @include('PaypalB::checkout.partials.wallet_buttons', ['wallet' => $wallet])
@endsection
