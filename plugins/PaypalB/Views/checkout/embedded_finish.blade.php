@extends('PaypalB::layout.checkout')

@section('title', trans('PaypalB::common.finish_title'))

@section('content')
  {{-- 内嵌会话的收口页：只负责把结果通知 A 站父页面，由 A 站拉取真实状态后顶层跳转。 --}}
  <div class="text-center py-3">
    <div class="text-secondary">{{ trans('PaypalB::common.finish_returning') }}</div>
    <a class="btn btn-outline-secondary btn-sm mt-3" href="{{ $return_url }}" target="_top" rel="noreferrer">{{ trans('PaypalB::common.back_to_order') }}</a>
  </div>
@endsection

@push('scripts')
  <script>
    (() => {
      const returnUrl = @json($return_url);
      const event = @json((string) ($embed_event ?? 'ended'));
      const embed = window.PaypalBEmbed;
      if (embed && embed.active) {
        embed.send(event);
        // A 站父页面收到消息后会顶层跳转；若它没有反应（消息被丢弃、脚本异常），由本页兜底把顶层带回 A 站。
        window.setTimeout(() => {
          try { window.top.location.assign(returnUrl); } catch (error) { window.location.assign(returnUrl); }
        }, 4000);
        return;
      }
      // 以顶层方式打开（例如 A 站已降级为跳转模式）时，行为与 redirect 会话的 302 一致。
      window.location.replace(returnUrl);
    })();
  </script>
@endpush
