@php
  $declaration = [];
  $verification = [];
  if ($payment) {
    $declaration = json_decode((string) $payment->request, true) ?: [];
    $verification = json_decode((string) $payment->response, true) ?: [];
  }
@endphp

<div class="card mb-4">
  <div class="card-header"><h6 class="card-title mb-0">{{ __('WesternUnion::common.verification_title') }}</h6></div>
  <div class="card-body">
    <div class="row g-3 mb-4">
      <div class="col-md-4"><span class="text-secondary">{{ __('order.number') }}:</span> {{ $order->number }}</div>
      <div class="col-md-4"><span class="text-secondary">{{ __('WesternUnion::common.expected_amount') }}:</span> {{ number_format((float) $order->total, 2, '.', '') }} {{ $order->currency_code }}</div>
      <div class="col-md-4"><span class="text-secondary">{{ __('WesternUnion::common.order_status') }}:</span> {{ $order->status_format }}</div>
    </div>

    @if ($declaration)
      <div class="alert alert-light border mb-4">
        <div class="fw-semibold mb-2">{{ __('WesternUnion::common.customer_declaration') }}</div>
        <div>{{ __('WesternUnion::common.transaction_id') }}: {{ $declaration['transaction_id'] ?? '-' }}</div>
        <div>{{ __('WesternUnion::common.payer_name') }}: {{ $declaration['payer_name'] ?? '-' }}</div>
        <div>{{ __('WesternUnion::common.paid_at') }}: {{ $declaration['paid_at'] ?? '-' }}</div>
        <div>{{ __('WesternUnion::common.declared_at') }}: {{ $declaration['declared_at'] ?? '-' }}</div>
        @if ($payment?->receipt)
          <a class="btn btn-outline-secondary btn-sm mt-3" href="{{ admin_route('western-union.orders.receipt', $order) }}" target="_blank" rel="noopener">{{ __('WesternUnion::common.view_receipt') }}</a>
        @endif
      </div>
    @endif

    @if ($verification)
      <div class="alert alert-success mb-0">
        <div class="fw-semibold mb-2">{{ __('WesternUnion::common.payment_verified') }}</div>
        <div>{{ __('WesternUnion::common.transaction_id') }}: {{ $payment->transaction_id ?: '-' }}</div>
        <div>{{ __('WesternUnion::common.received_amount') }}: {{ $verification['received_amount'] ?? '-' }} {{ $verification['currency'] ?? $order->currency_code }}</div>
        <div>{{ __('WesternUnion::common.received_at') }}: {{ $verification['received_at'] ?? '-' }}</div>
      </div>
    @elseif ($order->status === 'unpaid')
      @can('orders_update_status')
        <form method="POST" action="{{ admin_route('western-union.orders.confirm', $order) }}">
          @csrf
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="western-union-transaction-id">{{ __('WesternUnion::common.transaction_id') }}</label>
              <input id="western-union-transaction-id" class="form-control" name="transaction_id" required value="{{ old('transaction_id', $declaration['transaction_id'] ?? '') }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="western-union-received-amount">{{ __('WesternUnion::common.received_amount') }}</label>
              <input id="western-union-received-amount" class="form-control" name="received_amount" required inputmode="decimal" value="{{ old('received_amount', number_format((float) $order->total, 2, '.', '')) }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="western-union-payer-name">{{ __('WesternUnion::common.payer_name') }}</label>
              <input id="western-union-payer-name" class="form-control" name="payer_name" value="{{ old('payer_name', $declaration['payer_name'] ?? '') }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="western-union-received-at">{{ __('WesternUnion::common.received_at') }}</label>
              <input id="western-union-received-at" class="form-control" type="datetime-local" name="received_at" value="{{ old('received_at') }}">
            </div>
            <div class="col-12">
              <label class="form-label" for="western-union-note">{{ __('WesternUnion::common.note') }}</label>
              <textarea id="western-union-note" class="form-control" name="note" rows="2">{{ old('note') }}</textarea>
            </div>
          </div>
          <button class="btn btn-primary mt-4" type="submit">{{ __('WesternUnion::common.confirm_payment') }}</button>
        </form>
      @endcan
    @endif
  </div>
</div>
