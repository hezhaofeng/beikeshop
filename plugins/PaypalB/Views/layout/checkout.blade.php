@php
  // 支付页只复用主题的视觉资源，不加载导航、购物车和商城业务脚本：
  // 一是避免买家在支付中途被导航带走，二是减少与 PayPal SDK 无关的加载开销。
  $themeCode = system_setting('base.theme', 'default');
  $themeCss  = [];
  foreach (['bootstrap', 'app'] as $cssName) {
      try {
          $themeCss[] = mix("/build/beike/shop/{$themeCode}/css/{$cssName}.css");
      } catch (\Throwable) {
          // 主题资源未构建时降级为内置基础样式，支付页不能因为缺少 CSS 而打不开。
      }
  }
  $logo = system_setting('base.logo');
@endphp
<!doctype html>
<html lang="{{ $html_lang ?? str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <meta name="robots" content="noindex, nofollow">
  <title>@yield('title', $merchant['display_name'] ?? 'PayPal')</title>
  @if($logo)
    <link rel="shortcut icon" href="{{ image_origin(system_setting('base.favicon')) }}">
  @endif
  @foreach($themeCss as $css)
    <link rel="stylesheet" type="text/css" href="{{ $css }}">
  @endforeach
  <style>
    /* 主题资源缺失时的最小可用排版，主题存在时这些规则会被主题覆盖。 */
    body { margin: 0; background: #f7f8fa; color: #202124; font: 15px/1.5 Arial, "PingFang SC", "Microsoft YaHei", sans-serif; }
    .pb-shell { width: min(100%, 720px); margin: 0 auto; padding: 24px 16px 48px; }
    .pb-brand { display: flex; align-items: center; gap: 10px; justify-content: center; margin-bottom: 20px; }
    .pb-brand img { max-height: 34px; }
    .pb-brand-name { font-size: 17px; font-weight: 700; }
    .pb-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 28px 24px; }
    .pb-foot { margin-top: 20px; text-align: center; font-size: 13px; color: #667085; }
    .pb-foot a { color: #667085; margin: 0 8px; }
    /* PayPal 托管卡片字段是 iframe，需要固定高度才能与输入框对齐。 */
    .paypal-hosted-field { height: calc(1.5em + 1rem + 2px); overflow: hidden; }
    .paypal-hosted-field > iframe { height: 100% !important; }
    @media (min-width: 768px) { .pb-panel { padding: 36px 40px; } }
  </style>
  @stack('head')
</head>
<body class="@yield('body-class')">
  <div class="pb-shell">
    <div class="pb-brand">
      @if($logo)
        <img src="{{ image_origin($logo) }}" alt="{{ $merchant['display_name'] ?? '' }}">
      @endif
      <span class="pb-brand-name">{{ $merchant['display_name'] ?? '' }}</span>
    </div>

    <div class="pb-panel">
      @yield('content')
    </div>

    @isset($merchant)
      <div class="pb-foot">
        <a href="{{ $merchant['contact_url'] }}" rel="noreferrer">{{ trans('PaypalB::common.contact_us') }}</a>
        <a href="{{ $merchant['refund_url'] }}" target="_blank" rel="noreferrer noopener">{{ trans('PaypalB::common.refund_policy') }}</a>
        <a href="{{ $merchant['privacy_url'] }}" target="_blank" rel="noreferrer noopener">{{ trans('PaypalB::common.privacy_policy') }}</a>
        <a href="{{ $merchant['terms_url'] }}" target="_blank" rel="noreferrer noopener">{{ trans('PaypalB::common.terms_policy') }}</a>
      </div>
    @endisset
  </div>

  @stack('scripts')
</body>
</html>
