@guest('web_shop')
  <li class="nav-item">
    <a href="{{ shop_route('offline-transfer.orders.lookup') }}" class="nav-link" title="{{ __('OfflineTransfer::common.order_lookup') }}" aria-label="{{ __('OfflineTransfer::common.order_lookup') }}">
      <i class="bi bi-search"></i>
    </a>
  </li>
@endguest
