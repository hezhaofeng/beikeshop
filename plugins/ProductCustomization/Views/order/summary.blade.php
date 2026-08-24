<div class="card mb-lg-4 mb-2">
  <div class="card-header"><h6 class="card-title">{{ __('ProductCustomization::common.order_summary_title') }}</h6></div>
  <div class="card-body">
    @foreach($rows as $row)
      <div class="mb-2">
        <div class="fw-bold">{{ $row['name'] }}</div>
        <div class="text-muted">{{ $row['summary'] }}</div>
      </div>
    @endforeach
  </div>
</div>
