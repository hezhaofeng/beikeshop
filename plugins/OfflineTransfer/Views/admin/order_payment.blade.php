@php
  $declaration = [];
  $verification = [];
  if ($payment) {
    $declaration = json_decode((string) $payment->request, true) ?: [];
    $verification = json_decode((string) $payment->response, true) ?: [];
  }
@endphp

<div class="card mb-4">
  <div class="card-header"><h6 class="card-title mb-0">{{ __('OfflineTransfer::common.verification_title') }}</h6></div>
  <div class="card-body">
    <div class="row g-3 mb-4">
      <div class="col-md-4"><span class="text-secondary">{{ __('order.number') }}:</span> {{ $order->number }}</div>
      <div class="col-md-4"><span class="text-secondary">{{ __('OfflineTransfer::common.expected_amount') }}:</span> {{ number_format((float) $order->total, 2, '.', '') }} {{ $order->currency_code }}</div>
      <div class="col-md-4"><span class="text-secondary">{{ __('OfflineTransfer::common.order_status') }}:</span> {{ $order->status_format }}</div>
    </div>

    @if ($declaration)
      <div class="alert alert-light border mb-4">
        <div class="fw-semibold mb-2">{{ __('OfflineTransfer::common.customer_declaration') }}</div>
        <div>{{ __('OfflineTransfer::common.transaction_id') }}: {{ $declaration['transaction_id'] ?? '-' }}</div>
        <div>{{ __('OfflineTransfer::common.payer_name') }}: {{ $declaration['payer_name'] ?? '-' }}</div>
        <div>{{ __('OfflineTransfer::common.paid_at') }}: {{ $declaration['paid_at'] ?? '-' }}</div>
        <div>{{ __('OfflineTransfer::common.declared_at') }}: {{ $declaration['declared_at'] ?? '-' }}</div>
        @if ($payment?->receipt)
          <a class="btn btn-outline-secondary btn-sm mt-3" href="{{ admin_route('offline-transfer.orders.receipt', $order) }}" target="_blank" rel="noopener">{{ __('OfflineTransfer::common.view_receipt') }}</a>
        @endif
      </div>
    @endif

    @if ($verification)
      <div class="alert alert-success mb-0">
        <div class="fw-semibold mb-2">{{ __('OfflineTransfer::common.payment_verified') }}</div>
        <div>{{ __('OfflineTransfer::common.transaction_id') }}: {{ $payment->transaction_id ?: '-' }}</div>
        <div>{{ __('OfflineTransfer::common.received_amount') }}: {{ $verification['received_amount'] ?? '-' }} {{ $verification['currency'] ?? $order->currency_code }}</div>
        <div>{{ __('OfflineTransfer::common.received_at') }}: {{ $verification['received_at'] ?? '-' }}</div>
      </div>
    @elseif ($order->status === 'unpaid')
      @can('orders_update_status')
        <form method="POST" action="{{ admin_route('offline-transfer.orders.confirm', $order) }}">
          @csrf
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="offline-transfer-transaction-id">{{ __('OfflineTransfer::common.transaction_id') }}</label>
              <input id="offline-transfer-transaction-id" class="form-control" name="transaction_id" required value="{{ old('transaction_id', $declaration['transaction_id'] ?? '') }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="offline-transfer-received-amount">{{ __('OfflineTransfer::common.received_amount') }}</label>
              <input id="offline-transfer-received-amount" class="form-control" name="received_amount" required inputmode="decimal" value="{{ old('received_amount', number_format((float) $order->total, 2, '.', '')) }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="offline-transfer-payer-name">{{ __('OfflineTransfer::common.payer_name') }}</label>
              <input id="offline-transfer-payer-name" class="form-control" name="payer_name" value="{{ old('payer_name', $declaration['payer_name'] ?? '') }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="offline-transfer-received-at">{{ __('OfflineTransfer::common.received_at') }}</label>
              <input id="offline-transfer-received-at" class="form-control" type="datetime-local" name="received_at" value="{{ old('received_at') }}">
            </div>
            <div class="col-12">
              <label class="form-label" for="offline-transfer-note">{{ __('OfflineTransfer::common.note') }}</label>
              <textarea id="offline-transfer-note" class="form-control" name="note" rows="2">{{ old('note') }}</textarea>
            </div>
          </div>
          <button class="btn btn-primary mt-4" type="submit">{{ __('OfflineTransfer::common.confirm_payment') }}</button>
        </form>
      @endcan
    @endif
  </div>
</div>
