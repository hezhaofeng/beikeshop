{{-- 收款主体披露。B 站是本次交易的 merchant of record，这一段是 PayPal 合规要求，不可省略。 --}}
<div class="pb-merchant text-secondary small border-bottom pb-3 mb-4">
  {{ trans('PaypalB::common.merchant_responsibility') }}
</div>

<table class="table table-borderless mb-4">
  <tbody>
    <tr>
      <td class="ps-0 text-secondary">{{ trans('PaypalB::common.order_number') }}</td>
      <td class="text-end pe-0 fw-bold">{{ $transaction->order_number }}</td>
    </tr>
    <tr>
      <td class="ps-0 text-secondary">{{ trans('PaypalB::common.amount_payable') }}</td>
      <td class="text-end pe-0"><span class="fw-bold fs-5">{{ $transaction->amount }} {{ $transaction->currency }}</span></td>
    </tr>
  </tbody>
</table>

<div class="mb-4">
  <div class="fw-bold mb-2">{{ trans('PaypalB::common.order_summary') }}</div>
  <table class="table align-middle mb-0">
    <tbody>
      {{-- 兜底也必须用发给 PayPal 的映射快照：order_items 是 A 站真实商品，
           页面上的脚本可读，泄露出去会绕过商品映射。 --}}
      @foreach((array) ($checkout_items ?? $transaction->paypal_order_items) as $item)
        <tr>
          <td class="ps-0">
            <div class="fw-semibold">{{ $item['name'] }}</div>
            @if($item['sku'])
              <div class="text-secondary small">SKU: {{ $item['sku'] }}</div>
            @endif
          </td>
          <td class="text-end pe-0 text-nowrap">× {{ $item['quantity'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>

@if(!empty(data_get($transaction->shipping_address, 'required')))
  <div class="alert alert-light border small mb-4">
    <span class="fw-semibold">{{ trans('PaypalB::common.ship_to') }}：</span>
    {{ data_get($transaction->shipping_address, 'name') }}，{{ data_get($transaction->shipping_address, 'address_1') }}
    {{ data_get($transaction->shipping_address, 'city') }} {{ data_get($transaction->shipping_address, 'zone') }}
    {{ data_get($transaction->shipping_address, 'postal_code') }} {{ data_get($transaction->shipping_address, 'country') }}
  </div>
@endif
