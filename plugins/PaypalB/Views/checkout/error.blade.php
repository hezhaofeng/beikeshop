@extends('PaypalB::layout.checkout')

@section('title', trans('PaypalB::common.error_title'))

@section('content')
  <div class="text-center">
    <h1 class="h4 mb-3">{{ trans('PaypalB::common.error_title') }}</h1>
    <p class="text-secondary">{{ $message }}</p>

    <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
      @if(!empty($fallback_url))
        <a class="btn btn-primary" href="{{ $fallback_url }}">{{ trans('PaypalB::common.use_wallet') }}</a>
      @endif
      @if(!empty($return_url))
        <a class="btn btn-outline-secondary" href="{{ $return_url }}">{{ trans('PaypalB::common.back_to_order') }}</a>
      @endif
    </div>

    <div class="small text-secondary mt-4 pt-3 border-top">
      {{ trans('PaypalB::common.customer_service') }}：
      <a href="{{ $merchant['contact_url'] }}" class="link-secondary">{{ $merchant['support_email'] }}</a>
    </div>
  </div>
@endsection
