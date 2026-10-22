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
        {{-- 内嵌时“返回订单”必须在顶层打开：iframe 内加载 A 站页面会被 A 站自己拒绝嵌入。 --}}
        <a class="btn btn-outline-secondary" href="{{ $return_url }}" @if(!empty($embedded)) target="_top" @endif>{{ trans('PaypalB::common.back_to_order') }}</a>
      @endif
    </div>

    <div class="small text-secondary mt-4 pt-3 border-top">
      {{ trans('PaypalB::common.customer_service') }}：
      <a href="{{ $merchant['contact_url'] }}" class="link-secondary" @if(!empty($embedded)) target="_blank" rel="noopener noreferrer" @endif>{{ $merchant['support_email'] }}</a>
    </div>
  </div>
@endsection

@push('scripts')
  <script>
    // 会话已结束或已付款待确认时立即通知父页面，A 站据此拉取真实状态并给出对应提示。
    if (window.PaypalBEmbed) window.PaypalBEmbed.send(@json((string) ($embed_event ?? 'ended')));
  </script>
@endpush
