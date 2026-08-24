<td>
  @if ($order && $order->ad_tracking_source)
    <span class="badge text-bg-light">{{ $order->ad_tracking_source }}</span>
    @if ($order->ad_tracking_campaign)
      <div class="small text-secondary mt-1">{{ $order->ad_tracking_campaign }}</div>
    @endif
  @else
    <span class="text-secondary">-</span>
  @endif
</td>
