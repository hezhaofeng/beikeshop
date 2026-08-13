@php
  $declaration = [];
  $verification = [];
  if ($payment) {
    $declaration = json_decode((string) $payment->request, true) ?: [];
    $verification = json_decode((string) $payment->response, true) ?: [];
  }
@endphp

<div class="card mb-4">
  <div class="card-header"><h6 class="card-title mb-0">{{ __('Zelle::common.verification_title') }}</h6></div>
  <div class="card-body">
    <div class="row g-3 mb-4">
      <div class="col-md-4"><span class="text-secondary">{{ __('order.number') }}:</span> {{ $order->number }}</div>
      <div class="col-md-4"><span class="text-secondary">{{ __('Zelle::common.expected_amount') }}:</span> {{ number_format((float) $order->total, 2, '.', '') }} USD</div>
      <div class="col-md-4"><span class="text-secondary">{{ __('Zelle::common.order_status') }}:</span> {{ $order->status_format }}</div>
    </div>

    @if ($declaration)
      <div class="alert alert-light border mb-4">
        <div class="fw-semibold mb-2">{{ __('Zelle::common.customer_declaration') }}</div>
        <div>{{ __('Zelle::common.transaction_id') }}: {{ $declaration['transaction_id'] ?? '-' }}</div>
        <div>{{ __('Zelle::common.payer_name') }}: {{ $declaration['payer_name'] ?? '-' }}</div>
        <div>{{ __('Zelle::common.declared_at') }}: {{ $declaration['declared_at'] ?? '-' }}</div>
      </div>
    @endif

    @if ($verification)
      <div class="alert alert-success mb-0">
        <div class="fw-semibold mb-2">{{ __('Zelle::common.payment_verified') }}</div>
        <div>{{ __('Zelle::common.transaction_id') }}: {{ $payment->transaction_id ?: '-' }}</div>
        <div>{{ __('Zelle::common.received_amount') }}: {{ $verification['received_amount'] ?? '-' }} USD</div>
        <div>{{ __('Zelle::common.received_at') }}: {{ $verification['received_at'] ?? '-' }}</div>
      </div>
    @elseif ($order->status === 'unpaid')
      @can('orders_update_status')
        <form method="POST" action="{{ admin_route('zelle.orders.confirm', $order) }}">
          @csrf
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="zelle-transaction-id">{{ __('Zelle::common.transaction_id') }}</label>
              <input id="zelle-transaction-id" class="form-control" name="transaction_id" required value="{{ old('transaction_id', $declaration['transaction_id'] ?? '') }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="zelle-received-amount">{{ __('Zelle::common.received_amount') }}</label>
              <input id="zelle-received-amount" class="form-control" name="received_amount" required inputmode="decimal" value="{{ old('received_amount', number_format((float) $order->total, 2, '.', '')) }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="zelle-payer-name">{{ __('Zelle::common.payer_name') }}</label>
              <input id="zelle-payer-name" class="form-control" name="payer_name" value="{{ old('payer_name', $declaration['payer_name'] ?? '') }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="zelle-received-at">{{ __('Zelle::common.received_at') }}</label>
              <input id="zelle-received-at" class="form-control" type="datetime-local" name="received_at" value="{{ old('received_at') }}">
            </div>
            <div class="col-12">
              <label class="form-label" for="zelle-receipt">{{ __('Zelle::common.receipt') }}</label>
              <input id="zelle-receipt" class="form-control" name="receipt" value="{{ old('receipt') }}">
            </div>
            <div class="col-12">
              <label class="form-label" for="zelle-note">{{ __('Zelle::common.note') }}</label>
              <textarea id="zelle-note" class="form-control" name="note" rows="2">{{ old('note') }}</textarea>
            </div>
          </div>
          <button class="btn btn-primary mt-4" type="submit">{{ __('Zelle::common.confirm_payment') }}</button>
        </form>
      @endcan
    @endif
  </div>
</div>
