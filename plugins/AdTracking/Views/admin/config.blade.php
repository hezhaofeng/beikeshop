@extends('admin::layouts.master')

@section('title', $plugin->getLocaleName())
@section('content-area-class', 'w-max-1200')
@section('page-title-back', admin_route('plugins.index', http_build_query(request()->query())))

@php
  $payload = $adTracking ?? [];
  $settings = array_merge(
    \Plugin\AdTracking\Services\SettingsService::all(),
    is_array($payload['settings'] ?? null) ? $payload['settings'] : []
  );
  $platforms = is_array($payload['platforms'] ?? null)
    ? $payload['platforms']
    : \Plugin\AdTracking\Services\SettingsService::platforms();
  $events = is_array($payload['events'] ?? null)
    ? $payload['events']
    : \Plugin\AdTracking\Services\SettingsService::EVENTS;
  $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : [];

  $enabledEvents = old('enabled_events', $settings['enabled_events'] ?? $events);
  if (is_string($enabledEvents)) {
    $enabledEvents = preg_split('/[\s,;]+/', $enabledEvents, -1, PREG_SPLIT_NO_EMPTY);
  }
  $enabledEvents = is_array($enabledEvents) ? array_values(array_filter($enabledEvents)) : $events;

  $value = static fn (string $name, mixed $default = '') => old($name, $settings[$name] ?? $default);
  $boolValue = static fn (string $name, int $default = 0) => (string) old($name, (string) (($settings[$name] ?? $default) ? 1 : 0));
  $platformEnabledCount = collect($platforms)->filter(fn (array $platform, string $code) => (bool) ($settings[$code . '_enabled'] ?? false))->count();
  $serverHealthy = ($stats['failed_events'] ?? 0) > 0
    ? __('AdTracking::common.server_attention')
    : (($stats['success_events'] ?? 0) > 0 ? __('AdTracking::common.server_healthy') : __('AdTracking::common.no_data'));

  $navigation = [
    ['id' => 'overview', 'label' => __('AdTracking::common.nav_overview')],
    ['id' => 'facebook', 'label' => __('AdTracking::common.facebook')],
    ['id' => 'google_ads', 'label' => __('AdTracking::common.google_ads')],
    ['id' => 'ga4', 'label' => __('AdTracking::common.ga4')],
    ['id' => 'tiktok', 'label' => 'TikTok'],
    ['id' => 'twitter', 'label' => __('AdTracking::common.twitter')],
    ['id' => 'pinterest', 'label' => __('AdTracking::common.pinterest')],
    ['id' => 'postback', 'label' => __('AdTracking::common.postback_settings')],
    ['id' => 'advanced', 'label' => __('AdTracking::common.nav_advanced')],
  ];

  $platformPanels = [
    [
      'code' => 'facebook',
      'title' => __('AdTracking::common.facebook'),
      'description' => __('AdTracking::common.facebook_desc'),
      'events' => ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase', 'Search'],
      'supports_browser' => true,
      'supports_server' => true,
      'fields' => [
        [
          'name' => 'facebook_dispatch_mode',
          'title' => __('AdTracking::common.dispatch_mode'),
          'type' => 'select',
          'options' => [
            ['value' => 'single', 'label' => __('AdTracking::common.dispatch_single')],
            ['value' => 'broadcast', 'label' => __('AdTracking::common.dispatch_broadcast')],
            ['value' => 'failover', 'label' => __('AdTracking::common.dispatch_failover')],
          ],
          'description' => __('AdTracking::common.dispatch_mode_help'),
        ],
        [
          'name' => 'facebook_pixel_ids',
          'title' => __('AdTracking::common.pixel_ids'),
          'type' => 'textarea',
          'help_text' => __('AdTracking::common.get_pixel_ids'),
          'help_url' => 'https://developers.facebook.com/docs/meta-pixel/get-started/',
          'description' => __('AdTracking::common.pixel_ids_help'),
        ],
        [
          'name' => 'facebook_access_token',
          'title' => __('AdTracking::common.access_token'),
          'type' => 'password',
          'help_text' => __('AdTracking::common.get_access_token'),
          'help_url' => 'https://developers.facebook.com/docs/marketing-api/conversions-api/',
        ],
      ],
    ],
    [
      'code' => 'google_ads',
      'title' => __('AdTracking::common.google_ads'),
      'description' => __('AdTracking::common.google_ads_desc'),
      'events' => ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase', 'Search'],
      'supports_browser' => true,
      'supports_server' => false,
      'fields' => [
        [
          'name' => 'google_ads_id',
          'title' => __('AdTracking::common.google_ads_id'),
          'help_text' => __('AdTracking::common.get_conversion_id'),
          'help_url' => 'https://support.google.com/google-ads/answer/1722022?hl=zh-Hans',
        ],
        [
          'name' => 'google_ads_label',
          'title' => __('AdTracking::common.google_ads_label'),
          'help_text' => __('AdTracking::common.get_conversion_label'),
          'help_url' => 'https://support.google.com/google-ads/answer/1722022?hl=zh-Hans',
        ],
        [
          'name' => 'google_tag_manager_id',
          'title' => __('AdTracking::common.gtm_id'),
          'help_text' => __('AdTracking::common.get_gtm_id'),
          'help_url' => 'https://tagmanager.google.com/',
        ],
      ],
    ],
    [
      'code' => 'ga4',
      'title' => __('AdTracking::common.ga4'),
      'description' => __('AdTracking::common.ga4_desc'),
      'events' => ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase', 'Search'],
      'supports_browser' => true,
      'supports_server' => true,
      'fields' => [
        [
          'name' => 'ga4_measurement_id',
          'title' => __('AdTracking::common.measurement_id'),
          'help_text' => __('AdTracking::common.get_measurement_id'),
          'help_url' => 'https://analytics.google.com/analytics/web/',
        ],
        [
          'name' => 'ga4_api_secret',
          'title' => __('AdTracking::common.api_secret'),
          'type' => 'password',
          'help_text' => __('AdTracking::common.get_api_secret'),
          'help_url' => 'https://developers.google.com/analytics/devguides/collection/protocol/ga4',
        ],
      ],
    ],
    [
      'code' => 'tiktok',
      'title' => 'TikTok Pixel',
      'description' => __('AdTracking::common.tiktok_desc'),
      'events' => ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase', 'Search'],
      'supports_browser' => true,
      'supports_server' => true,
      'fields' => [
        [
          'name' => 'tiktok_pixel_id',
          'title' => __('AdTracking::common.tiktok_pixel_id'),
          'help_text' => __('AdTracking::common.get_tiktok_pixel_id'),
          'help_url' => 'https://ads.tiktok.com/help/article/tiktok-pixel',
        ],
        [
          'name' => 'tiktok_access_token',
          'title' => __('AdTracking::common.tiktok_access_token'),
          'type' => 'password',
          'help_text' => __('AdTracking::common.get_tiktok_access_token'),
          'help_url' => 'https://ads.tiktok.com/help/article/events-api',
        ],
      ],
    ],
    [
      'code' => 'twitter',
      'title' => __('AdTracking::common.twitter'),
      'description' => __('AdTracking::common.twitter_desc'),
      'events' => ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase'],
      'supports_browser' => true,
      'supports_server' => false,
      'fields' => [
        [
          'name' => 'twitter_pixel_id',
          'title' => __('AdTracking::common.twitter_pixel_id'),
          'help_text' => __('AdTracking::common.get_twitter_pixel_id'),
          'help_url' => 'https://business.x.com/en/help/campaign-measurement-and-analytics/conversion-tracking-for-websites',
        ],
      ],
    ],
    [
      'code' => 'pinterest',
      'title' => __('AdTracking::common.pinterest'),
      'description' => __('AdTracking::common.pinterest_desc'),
      'events' => ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase', 'Search'],
      'supports_browser' => true,
      'supports_server' => false,
      'fields' => [
        [
          'name' => 'pinterest_tag_id',
          'title' => __('AdTracking::common.pinterest_tag_id'),
          'help_text' => __('AdTracking::common.get_pinterest_tag_id'),
          'help_url' => 'https://help.pinterest.com/en/business/article/install-the-pinterest-tag',
        ],
      ],
    ],
  ];
@endphp

@push('header')
  <style>
    .ad-tracking-page {
      --ad-tracking-bg: #f4f7fb;
      --ad-tracking-border: #dbe5f1;
      --ad-tracking-primary: #2563eb;
      --ad-tracking-primary-soft: #e8f0ff;
      --ad-tracking-success: #16a34a;
      --ad-tracking-danger: #dc2626;
      --ad-tracking-warning: #d97706;
      display: grid;
      grid-template-columns: 250px minmax(0, 1fr);
      gap: 24px;
      align-items: start;
    }

    .ad-tracking-sidebar {
      position: sticky;
      top: 92px;
      border: 1px solid var(--ad-tracking-border);
      border-radius: 20px;
      background: linear-gradient(180deg, #f8fbff 0%, #eef4fb 100%);
      padding: 18px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    }

    .ad-tracking-sidebar__title {
      margin-bottom: 6px;
      font-size: 18px;
      font-weight: 700;
      color: #0f172a;
    }

    .ad-tracking-sidebar__desc {
      margin-bottom: 14px;
      color: #64748b;
      font-size: 13px;
      line-height: 1.6;
    }

    .ad-tracking-nav {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .ad-tracking-nav__button {
      width: 100%;
      border: 1px solid transparent;
      border-radius: 14px;
      background: transparent;
      color: #334155;
      text-align: left;
      padding: 11px 14px;
      font-weight: 600;
      transition: all .2s ease;
    }

    .ad-tracking-nav__button:hover,
    .ad-tracking-nav__button.is-active {
      border-color: #bfdbfe;
      background: #fff;
      color: var(--ad-tracking-primary);
      box-shadow: 0 8px 24px rgba(37, 99, 235, 0.12);
    }

    .ad-tracking-main {
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    .ad-tracking-hero {
      border: 1px solid var(--ad-tracking-border);
      border-radius: 24px;
      background: radial-gradient(circle at top right, #dbeafe 0%, #eff6ff 33%, #ffffff 100%);
      padding: 26px 28px;
      box-shadow: 0 14px 40px rgba(15, 23, 42, 0.06);
    }

    .ad-tracking-hero__title {
      margin-bottom: 10px;
      font-size: 28px;
      font-weight: 700;
      color: #0f172a;
    }

    .ad-tracking-hero__desc {
      max-width: 840px;
      margin-bottom: 0;
      color: #475569;
      line-height: 1.75;
    }

    .ad-tracking-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
    }

    .ad-tracking-stat {
      border: 1px solid var(--ad-tracking-border);
      border-radius: 20px;
      background: #fff;
      padding: 20px 22px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04);
    }

    .ad-tracking-stat__label {
      color: #64748b;
      font-size: 13px;
    }

    .ad-tracking-stat__value {
      margin-top: 10px;
      font-size: 30px;
      line-height: 1;
      font-weight: 700;
      color: #0f172a;
    }

    .ad-tracking-stat__meta {
      margin-top: 10px;
      color: #64748b;
      font-size: 13px;
    }

    .ad-tracking-panel {
      display: none;
      border: 1px solid var(--ad-tracking-border);
      border-radius: 24px;
      background: var(--ad-tracking-bg);
      padding: 22px;
    }

    .ad-tracking-panel.is-active {
      display: block;
    }

    .ad-tracking-panel__head {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      align-items: flex-start;
      margin-bottom: 18px;
    }

    .ad-tracking-panel__title {
      margin-bottom: 6px;
      font-size: 24px;
      font-weight: 700;
      color: #0f172a;
    }

    .ad-tracking-panel__desc {
      margin-bottom: 0;
      color: #64748b;
      line-height: 1.75;
    }

    .ad-tracking-card {
      height: 100%;
      border: 1px solid var(--ad-tracking-border);
      border-radius: 20px;
      background: #fff;
      padding: 18px 20px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }

    .ad-tracking-card__title {
      margin-bottom: 8px;
      font-size: 16px;
      font-weight: 700;
      color: #0f172a;
    }

    .ad-tracking-card__desc {
      color: #64748b;
      line-height: 1.7;
      font-size: 13px;
    }

    .ad-tracking-field-help {
      margin: -8px 0 14px;
      font-size: 12px;
    }

    .ad-tracking-field-help a {
      color: var(--ad-tracking-primary);
      text-decoration: none;
    }

    .ad-tracking-field-help a:hover {
      text-decoration: underline;
    }

    .ad-tracking-event-grid,
    .ad-tracking-feature-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    .ad-tracking-event-item {
      position: relative;
      display: flex;
      align-items: center;
      gap: 12px;
      border: 1px solid var(--ad-tracking-border);
      border-radius: 16px;
      background: #fff;
      padding: 14px 16px;
      transition: all .2s ease;
    }

    .ad-tracking-event-item:has(input:checked) {
      border-color: #93c5fd;
      background: var(--ad-tracking-primary-soft);
      box-shadow: inset 0 0 0 1px #bfdbfe;
    }

    .ad-tracking-event-item input {
      width: 18px;
      height: 18px;
      accent-color: var(--ad-tracking-primary);
    }

    .ad-tracking-event-item__name {
      font-weight: 700;
      color: #0f172a;
    }

    .ad-tracking-event-item__desc {
      display: block;
      color: #64748b;
      font-size: 12px;
      margin-top: 3px;
    }

    .ad-tracking-platform-stats {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }

    .ad-tracking-platform-stats__item {
      border-radius: 18px;
      background: #fff;
      border: 1px solid var(--ad-tracking-border);
      padding: 16px 18px;
    }

    .ad-tracking-platform-stats__value {
      font-size: 24px;
      font-weight: 700;
      color: #0f172a;
    }

    .ad-tracking-badge-list {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .ad-tracking-badge {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 6px 12px;
      background: #eff6ff;
      color: #1d4ed8;
      font-size: 12px;
      font-weight: 700;
    }

    .ad-tracking-table {
      width: 100%;
      border-collapse: collapse;
    }

    .ad-tracking-table th,
    .ad-tracking-table td {
      padding: 12px 14px;
      border-bottom: 1px solid #e2e8f0;
      font-size: 13px;
      color: #334155;
      vertical-align: top;
    }

    .ad-tracking-table th {
      color: #64748b;
      font-weight: 700;
      background: #f8fafc;
    }

    .ad-tracking-table tr:last-child td {
      border-bottom: 0;
    }

    .ad-tracking-status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 700;
    }

    .ad-tracking-status::before {
      content: '';
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: #94a3b8;
    }

    .ad-tracking-status.is-success::before { background: var(--ad-tracking-success); }
    .ad-tracking-status.is-danger::before { background: var(--ad-tracking-danger); }
    .ad-tracking-status.is-warning::before { background: var(--ad-tracking-warning); }

    .ad-tracking-empty {
      padding: 30px 16px;
      text-align: center;
      color: #94a3b8;
    }

    @media (max-width: 1199px) {
      .ad-tracking-page {
        grid-template-columns: 1fr;
      }

      .ad-tracking-sidebar {
        position: static;
      }

      .ad-tracking-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 767px) {
      .ad-tracking-grid,
      .ad-tracking-event-grid,
      .ad-tracking-feature-grid,
      .ad-tracking-platform-stats {
        grid-template-columns: 1fr;
      }

      .ad-tracking-hero {
        padding: 22px 18px;
      }

      .ad-tracking-panel {
        padding: 18px 14px;
      }

      .ad-tracking-panel__head {
        flex-direction: column;
      }
    }
  </style>
@endpush

@section('page-bottom-btns')
  <button type="submit" form="ad-tracking-config-form" class="btn btn-primary btn-lg">{{ __('common.submit') }}</button>
@endsection

@section('content')
  <form class="needs-validation" novalidate action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST" id="ad-tracking-config-form">
    @csrf
    {{ method_field('put') }}
    <input type="hidden" name="facebook_pixel_id" value="">

    @if ($errors->any())
      <x-admin-alert type="danger" :msg="$errors->first()" class="mb-4"/>
    @endif

    <div class="ad-tracking-page">
      <aside class="ad-tracking-sidebar">
        <div class="ad-tracking-sidebar__title">{{ $plugin->getLocaleName() }}</div>
        <div class="ad-tracking-sidebar__desc">{{ $plugin->getLocaleDescription() }}</div>
        <div class="ad-tracking-nav">
          @foreach ($navigation as $index => $item)
            <button
              type="button"
              class="ad-tracking-nav__button {{ $index === 0 ? 'is-active' : '' }}"
              data-ad-tracking-nav="{{ $item['id'] }}">
              {{ $item['label'] }}
            </button>
          @endforeach
        </div>
      </aside>

      <div class="ad-tracking-main">
        <section class="ad-tracking-hero">
          <div class="ad-tracking-hero__title">{{ __('AdTracking::common.hero_title') }}</div>
          <p class="ad-tracking-hero__desc">{{ __('AdTracking::common.hero_desc') }}</p>
        </section>

        <section class="ad-tracking-panel is-active" data-ad-tracking-panel="overview">
          <div class="ad-tracking-panel__head">
            <div>
              <div class="ad-tracking-panel__title">{{ __('AdTracking::common.nav_overview') }}</div>
              <p class="ad-tracking-panel__desc">{{ __('AdTracking::common.overview_desc') }}</p>
            </div>
            <span class="ad-tracking-badge">{{ __('AdTracking::common.last_days', ['days' => $stats['period_days'] ?? 7]) }}</span>
          </div>

          <div class="ad-tracking-grid mb-4">
            <div class="ad-tracking-stat">
              <div class="ad-tracking-stat__label">{{ __('AdTracking::common.enabled_platforms') }}</div>
              <div class="ad-tracking-stat__value">{{ $platformEnabledCount }}</div>
              <div class="ad-tracking-stat__meta">{{ __('AdTracking::common.platforms_configured_meta') }}</div>
            </div>
            <div class="ad-tracking-stat">
              <div class="ad-tracking-stat__label">{{ __('AdTracking::common.active_events') }}</div>
              <div class="ad-tracking-stat__value">{{ count($enabledEvents) }}</div>
              <div class="ad-tracking-stat__meta">{{ implode(' / ', $enabledEvents ?: [__('AdTracking::common.none_enabled')]) }}</div>
            </div>
            <div class="ad-tracking-stat">
              <div class="ad-tracking-stat__label">{{ __('AdTracking::common.server_status') }}</div>
              <div class="ad-tracking-stat__value">{{ $serverHealthy }}</div>
              <div class="ad-tracking-stat__meta">{{ __('AdTracking::common.server_status_meta', ['success' => $stats['success_events'] ?? 0, 'failed' => $stats['failed_events'] ?? 0]) }}</div>
            </div>
            <div class="ad-tracking-stat">
              <div class="ad-tracking-stat__label">{{ __('AdTracking::common.success_rate') }}</div>
              <div class="ad-tracking-stat__value">{{ number_format((float) ($stats['success_rate'] ?? 0), 1, '.', '') }}%</div>
              <div class="ad-tracking-stat__meta">{{ __('AdTracking::common.total_events_meta', ['count' => $stats['total_events'] ?? 0]) }}</div>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-xl-6">
              <div class="ad-tracking-card">
                <div class="ad-tracking-card__title">{{ __('AdTracking::common.basic_settings') }}</div>
                <div class="ad-tracking-card__desc mb-3">{{ __('AdTracking::common.basic_settings_desc') }}</div>
                <x-admin-form-switch name="status" :title="__('AdTracking::common.enable_plugin')" :value="$boolValue('status', 0)" />
                <x-admin-form-select
                  name="consent_mode"
                  :title="__('AdTracking::common.consent_mode')"
                  :options="[
                    ['value' => 'opt_in', 'label' => __('AdTracking::common.consent_opt_in')],
                    ['value' => 'always', 'label' => __('AdTracking::common.consent_always')],
                  ]"
                  :value="$value('consent_mode', 'opt_in')">
                  <div class="help-text font-size-12 lh-base">{{ __('AdTracking::common.consent_help') }}</div>
                </x-admin-form-select>
              </div>
            </div>
            <div class="col-xl-6">
              <div class="ad-tracking-card">
                <div class="ad-tracking-card__title">{{ __('AdTracking::common.advanced_capabilities') }}</div>
                <div class="ad-tracking-feature-grid">
                  <div class="ad-tracking-event-item">
                    <div>
                      <div class="ad-tracking-event-item__name">{{ __('AdTracking::common.feature_server') }}</div>
                      <span class="ad-tracking-event-item__desc">{{ __('AdTracking::common.feature_server_desc') }}</span>
                    </div>
                  </div>
                  <div class="ad-tracking-event-item">
                    <div>
                      <div class="ad-tracking-event-item__name">{{ __('AdTracking::common.feature_postback') }}</div>
                      <span class="ad-tracking-event-item__desc">{{ __('AdTracking::common.feature_postback_desc') }}</span>
                    </div>
                  </div>
                  <div class="ad-tracking-event-item">
                    <div>
                      <div class="ad-tracking-event-item__name">{{ __('AdTracking::common.feature_url_capture') }}</div>
                      <span class="ad-tracking-event-item__desc">{{ __('AdTracking::common.feature_url_capture_desc') }}</span>
                    </div>
                  </div>
                  <div class="ad-tracking-event-item">
                    <div>
                      <div class="ad-tracking-event-item__name">{{ __('AdTracking::common.feature_order_source') }}</div>
                      <span class="ad-tracking-event-item__desc">{{ __('AdTracking::common.feature_order_source_desc') }}</span>
                    </div>
                  </div>
                  <div class="ad-tracking-event-item">
                    <div>
                      <div class="ad-tracking-event-item__name">{{ __('AdTracking::common.feature_multilingual') }}</div>
                      <span class="ad-tracking-event-item__desc">{{ __('AdTracking::common.feature_multilingual_desc') }}</span>
                    </div>
                  </div>
                  <div class="ad-tracking-event-item">
                    <div>
                      <div class="ad-tracking-event-item__name">{{ __('AdTracking::common.feature_gdpr') }}</div>
                      <span class="ad-tracking-event-item__desc">{{ __('AdTracking::common.feature_gdpr_desc') }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-xl-7">
              <div class="ad-tracking-card">
                <div class="ad-tracking-card__title">{{ __('AdTracking::common.supported_events') }}</div>
                <div class="ad-tracking-card__desc mb-3">{{ __('AdTracking::common.platform_help') }}</div>
                <input type="hidden" name="enabled_events[]" value="">
                <div class="ad-tracking-event-grid">
                  @foreach ($events as $event)
                    <label class="ad-tracking-event-item">
                      <input type="checkbox" name="enabled_events[]" value="{{ $event }}" {{ in_array($event, $enabledEvents, true) ? 'checked' : '' }}>
                      <div>
                        <div class="ad-tracking-event-item__name">{{ $event }}</div>
                        <span class="ad-tracking-event-item__desc">{{ __('AdTracking::common.event_desc_' . strtolower($event)) }}</span>
                      </div>
                    </label>
                  @endforeach
                </div>
                <div class="mt-3 pt-3 border-top">
                  <x-admin-form-switch name="order_status_events" :title="__('AdTracking::common.order_status_events')" :value="$boolValue('order_status_events', 1)" />
                  <div class="ad-tracking-card__desc">{{ __('AdTracking::common.order_status_events_help') }}</div>
                </div>
              </div>
            </div>
            <div class="col-xl-5">
              <div class="ad-tracking-card h-100">
                <div class="ad-tracking-card__title">{{ __('AdTracking::common.recent_events') }}</div>
                @if (collect($stats['recent_events'] ?? [])->isEmpty())
                  <div class="ad-tracking-empty">{{ __('AdTracking::common.no_event_logs') }}</div>
                @else
                  <div class="table-responsive">
                    <table class="ad-tracking-table">
                      <thead>
                        <tr>
                          <th>{{ __('AdTracking::common.event_name') }}</th>
                          <th>{{ __('AdTracking::common.platform') }}</th>
                          <th>{{ __('common.status') }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        @foreach ($stats['recent_events'] as $event)
                          @php
                            $statusClass = match ((string) $event->status) {
                              'success' => 'is-success',
                              'failed' => 'is-danger',
                              'sending' => 'is-warning',
                              default => '',
                            };
                          @endphp
                          <tr>
                            <td>
                              {{ $event->event_name }}
                              @if (($event->event_name ?? '') === \Plugin\AdTracking\Services\ConversionService::ORDER_STATUS_EVENT && data_get($event->request, 'order_status'))
                                <span class="ad-tracking-badge">{{ data_get($event->request, 'order_status') }}</span>
                              @endif
                            </td>
                            <td>{{ strtoupper((string) $event->platform) }}</td>
                            <td>
                              <span class="ad-tracking-status {{ $statusClass }}">
                                {{ $event->status }}
                                @if ($event->http_status)
                                  (HTTP {{ $event->http_status }})
                                @endif
                              </span>
                            </td>
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                @endif
              </div>
            </div>
          </div>

          <div class="ad-tracking-card">
            <div class="ad-tracking-card__title">{{ __('AdTracking::common.platform_performance') }}</div>
            <div class="table-responsive">
              <table class="ad-tracking-table">
                <thead>
                  <tr>
                    <th>{{ __('AdTracking::common.platform') }}</th>
                    <th>{{ __('AdTracking::common.total_sent') }}</th>
                    <th>{{ __('AdTracking::common.success_sent') }}</th>
                    <th>{{ __('AdTracking::common.failed_sent') }}</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach ($platformPanels as $panel)
                    @php($panelStats = $stats['platforms'][$panel['code']] ?? ['total' => 0, 'success' => 0, 'failed' => 0])
                    <tr>
                      <td>{{ $panel['title'] }}</td>
                      <td>{{ $panelStats['total'] }}</td>
                      <td>{{ $panelStats['success'] }}</td>
                      <td>{{ $panelStats['failed'] }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        </section>

        @foreach ($platformPanels as $panel)
          @php($panelStats = $stats['platforms'][$panel['code']] ?? ['total' => 0, 'success' => 0, 'failed' => 0])
          <section class="ad-tracking-panel" data-ad-tracking-panel="{{ $panel['code'] }}">
            <div class="ad-tracking-panel__head">
              <div>
                <div class="ad-tracking-panel__title">{{ $panel['title'] }}</div>
                <p class="ad-tracking-panel__desc">{{ $panel['description'] }}</p>
              </div>
              <div class="ad-tracking-badge-list">
                @foreach ($panel['events'] as $event)
                  <span class="ad-tracking-badge">{{ $event }}</span>
                @endforeach
              </div>
            </div>

            <div class="ad-tracking-platform-stats">
              <div class="ad-tracking-platform-stats__item">
                <div class="ad-tracking-stat__label">{{ __('AdTracking::common.total_sent') }}</div>
                <div class="ad-tracking-platform-stats__value">{{ $panelStats['total'] }}</div>
              </div>
              <div class="ad-tracking-platform-stats__item">
                <div class="ad-tracking-stat__label">{{ __('AdTracking::common.success_sent') }}</div>
                <div class="ad-tracking-platform-stats__value">{{ $panelStats['success'] }}</div>
              </div>
              <div class="ad-tracking-platform-stats__item">
                <div class="ad-tracking-stat__label">{{ __('AdTracking::common.failed_sent') }}</div>
                <div class="ad-tracking-platform-stats__value">{{ $panelStats['failed'] }}</div>
              </div>
            </div>

            <div class="row g-3">
              <div class="col-xl-5">
                <div class="ad-tracking-card h-100">
                  <div class="ad-tracking-card__title">{{ __('AdTracking::common.channel_settings') }}</div>
                  <x-admin-form-switch name="{{ $panel['code'] }}_enabled" :title="__('AdTracking::common.enable_platform')" :value="$boolValue($panel['code'] . '_enabled', 0)" />
                  @if ($panel['supports_browser'])
                    <x-admin-form-switch name="{{ $panel['code'] }}_browser" :title="__('AdTracking::common.browser_events')" :value="$boolValue($panel['code'] . '_browser', 1)" />
                  @endif
                  @if ($panel['supports_server'])
                    <x-admin-form-switch name="{{ $panel['code'] }}_server" :title="__('AdTracking::common.server_events')" :value="$boolValue($panel['code'] . '_server', 1)" />
                  @endif
                </div>
              </div>

              <div class="col-xl-7">
                <div class="ad-tracking-card h-100">
                  <div class="ad-tracking-card__title">{{ __('AdTracking::common.platform_credentials') }}</div>
                  @foreach ($panel['fields'] as $field)
                    @if (($field['type'] ?? 'text') === 'textarea')
                      <x-admin-form-textarea
                        :name="$field['name']"
                        :title="$field['title']"
                        :value="$value($field['name'])" />
                    @elseif (($field['type'] ?? 'text') === 'select')
                      <x-admin-form-select
                        :name="$field['name']"
                        :title="$field['title']"
                        :options="$field['options'] ?? []"
                        :value="$value($field['name'])" />
                    @else
                      <x-admin-form-input
                        :name="$field['name']"
                        :type="$field['type'] ?? 'text'"
                        :title="$field['title']"
                        :value="$value($field['name'])"
                        autocomplete="new-password" />
                    @endif
                    @if (!empty($field['description']))
                      <div class="help-text font-size-12 lh-base mb-2">{{ $field['description'] }}</div>
                    @endif
                    @if (!empty($field['help_url']))
                      <div class="ad-tracking-field-help">
                        <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                        <a href="{{ $field['help_url'] }}" target="_blank" rel="noopener noreferrer">
                          {{ $field['help_text'] }}
                        </a>
                      </div>
                    @endif
                  @endforeach
                </div>
              </div>
            </div>
          </section>
        @endforeach

        <section class="ad-tracking-panel" data-ad-tracking-panel="postback">
          <div class="ad-tracking-panel__head">
            <div>
              <div class="ad-tracking-panel__title">{{ __('AdTracking::common.postback_settings') }}</div>
              <p class="ad-tracking-panel__desc">{{ __('AdTracking::common.postback_desc') }}</p>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-xl-4">
              <div class="ad-tracking-card h-100">
                <div class="ad-tracking-card__title">{{ __('AdTracking::common.channel_settings') }}</div>
                <x-admin-form-switch name="postback_enabled" :title="__('AdTracking::common.enable_platform')" :value="$boolValue('postback_enabled', 0)" />
                <x-admin-form-switch name="postback_server" :title="__('AdTracking::common.server_events')" :value="$boolValue('postback_server', 1)" />
                <div class="ad-tracking-card__desc">{{ __('AdTracking::common.postback_channel_help') }}</div>
              </div>
            </div>
            <div class="col-xl-8">
              <div class="ad-tracking-card h-100">
                <div class="ad-tracking-card__title">{{ __('AdTracking::common.platform_credentials') }}</div>
                <x-admin-form-input name="postback_url" :title="__('AdTracking::common.postback_url')" :value="$value('postback_url')" />
                <x-admin-form-select
                  name="postback_method"
                  :title="__('AdTracking::common.postback_method')"
                  :options="[['value' => 'POST', 'label' => 'POST'], ['value' => 'GET', 'label' => 'GET']]"
                  :value="$value('postback_method', 'POST')" />
                <x-admin-form-input name="postback_events" :title="__('AdTracking::common.postback_events')" :value="$value('postback_events', 'Purchase')" />
                <x-admin-form-textarea name="postback_headers" :title="__('AdTracking::common.postback_headers')" :value="$value('postback_headers')">
                  <div class="help-text font-size-12 lh-base">{{ __('AdTracking::common.postback_headers_help') }}</div>
                </x-admin-form-textarea>
              </div>
            </div>
          </div>
        </section>

        <section class="ad-tracking-panel" data-ad-tracking-panel="advanced">
          <div class="ad-tracking-panel__head">
            <div>
              <div class="ad-tracking-panel__title">{{ __('AdTracking::common.nav_advanced') }}</div>
              <p class="ad-tracking-panel__desc">{{ __('AdTracking::common.advanced_desc') }}</p>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-xl-4">
              <div class="ad-tracking-card h-100">
                <div class="ad-tracking-card__title">{{ __('AdTracking::common.url_capture_title') }}</div>
                <div class="ad-tracking-card__desc">{{ __('AdTracking::common.url_capture_desc') }}</div>
                <div class="ad-tracking-badge-list mt-3">
                  @foreach (['UTM', 'GCLID', 'FBCLID', 'TTCLID'] as $item)
                    <span class="ad-tracking-badge">{{ $item }}</span>
                  @endforeach
                </div>
              </div>
            </div>
            <div class="col-xl-4">
              <div class="ad-tracking-card h-100">
                <div class="ad-tracking-card__title">{{ __('AdTracking::common.order_source_title') }}</div>
                <div class="ad-tracking-card__desc">{{ __('AdTracking::common.order_source_desc') }}</div>
              </div>
            </div>
            <div class="col-xl-4">
              <div class="ad-tracking-card h-100">
                <div class="ad-tracking-card__title">{{ __('AdTracking::common.gdpr_title') }}</div>
                <div class="ad-tracking-card__desc">{{ __('AdTracking::common.gdpr_desc') }}</div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  </form>
@endsection

@push('footer')
  <script>
    $(function () {
      // 后台配置页采用左侧导航切面板，避免多平台字段全部堆叠在一个长表单里。
      const activatePanel = function (panelId) {
        $('[data-ad-tracking-nav]').removeClass('is-active');
        $('[data-ad-tracking-nav="' + panelId + '"]').addClass('is-active');
        $('[data-ad-tracking-panel]').removeClass('is-active');
        $('[data-ad-tracking-panel="' + panelId + '"]').addClass('is-active');
      };

      $(document).on('click', '[data-ad-tracking-nav]', function () {
        activatePanel($(this).attr('data-ad-tracking-nav'));
      });

      const hashPanel = window.location.hash.replace('#', '');
      if (hashPanel && $('[data-ad-tracking-panel="' + hashPanel + '"]').length) {
        activatePanel(hashPanel);
      }
    });
  </script>
@endpush
