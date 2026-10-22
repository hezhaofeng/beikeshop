{{-- 订单号与金额：与原生 PayPal 插件支付页展示的信息一致；内嵌时 A 站主题卡片已经展示同样的信息，整段隐藏。 --}}
{{-- 不展示站点名称、logo、商品清单和收货地址：商品信息已随建单提交给 PayPal，由 PayPal 页面展示。 --}}
<div class="pb-summary">
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
</div>
