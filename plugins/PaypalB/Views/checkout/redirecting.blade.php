{{--
  跨站会话的本站中转页：支付成功 / 返回订单。
  支付成功时页面渲染后立即跳到 go_url（本站 finish 的 302），再由 302 带到 A 站，不做停留；返回订单同样几乎立即跳转；
  页面里不出现 A 站地址。
--}}
@extends('PaypalB::layout.theme')

@section('title', trans($success ? 'PaypalB::common.success_title' : 'PaypalB::common.redirect_title'))
@section('paypal_title', trans($success ? 'PaypalB::common.success_title' : 'PaypalB::common.redirect_title'))

@section('paypal_content')
  <div class="text-center py-3">
    @if($success)
      <div class="text-success mb-3"><i class="bi bi-check-circle" style="font-size: 3rem"></i></div>
      <p class="fs-5 mb-3">{{ trans('PaypalB::common.success_returning') }}</p>
    @else
      <p class="fs-5 mb-3">{{ trans('PaypalB::common.redirect_returning') }}</p>
    @endif
    <a class="btn btn-outline-secondary btn-sm" href="{{ $go_url }}" rel="noreferrer">{{ trans('PaypalB::common.redirect_now') }}</a>
  </div>
@endsection

@push('scripts')
  <script>
    // 支付成功不停留：中转页渲染后立即跳到本站 go 地址，由服务端 302 带回 A 站成功页；返回订单页几乎立即跳转。
    @if($success)
      window.location.replace(@json($go_url));
    @else
      window.setTimeout(() => window.location.replace(@json($go_url)), 300);
    @endif
  </script>
@endpush
