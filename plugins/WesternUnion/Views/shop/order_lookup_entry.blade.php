@guest('web_shop')
  <li class="nav-item">
    <a href="{{ shop_route('western-union.orders.lookup') }}" class="nav-link" title="{{ __('WesternUnion::common.order_lookup') }}" aria-label="{{ __('WesternUnion::common.order_lookup') }}">
      <i class="bi bi-search"></i>
    </a>
  </li>
@endguest
