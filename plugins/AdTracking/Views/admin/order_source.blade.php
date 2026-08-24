<div class="card mb-4">
  <div class="card-header"><h6 class="card-title mb-0">{{ __('AdTracking::common.order_source') }}</h6></div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-3">
        <div class="text-secondary small">{{ __('AdTracking::common.source') }}</div>
        <div class="fw-semibold">{{ $order->ad_tracking_source ?: '-' }}</div>
      </div>
      <div class="col-md-3">
        <div class="text-secondary small">{{ __('AdTracking::common.medium') }}</div>
        <div class="fw-semibold">{{ $order->ad_tracking_medium ?: '-' }}</div>
      </div>
      <div class="col-md-3">
        <div class="text-secondary small">{{ __('AdTracking::common.campaign') }}</div>
        <div class="fw-semibold">{{ $order->ad_tracking_campaign ?: '-' }}</div>
      </div>
      <div class="col-md-3">
        <div class="text-secondary small">{{ __('AdTracking::common.click_id') }}</div>
        <div class="fw-semibold text-break">{{ $order->ad_tracking_click_id ?: '-' }}</div>
      </div>
    </div>
    @if ($order->ad_tracking_landing_url || $order->ad_tracking_referrer)
      <div class="border-top mt-3 pt-3 small">
        @if ($order->ad_tracking_landing_url)
          <div class="text-break"><span class="text-secondary">{{ __('AdTracking::common.landing_url') }}：</span>{{ $order->ad_tracking_landing_url }}</div>
        @endif
        @if ($order->ad_tracking_referrer)
          <div class="text-break mt-1"><span class="text-secondary">{{ __('AdTracking::common.referrer') }}：</span>{{ $order->ad_tracking_referrer }}</div>
        @endif
      </div>
    @endif
  </div>
</div>
