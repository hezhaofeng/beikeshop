@guest('web_shop')
  <div class="accordion-item">
    <div class="nav-item-text">
      <a class="nav-link" href="{{ shop_route('western-union.orders.lookup') }}">
        <i class="bi bi-search me-1"></i>{{ __('WesternUnion::common.order_lookup') }}
      </a>
    </div>
  </div>
@endguest
