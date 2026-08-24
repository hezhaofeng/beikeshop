@php
  $guestOrderNumbers = array_map('strval', session('guest_order_numbers', []));
  $needGuestEmail = ! $order->customer_id && ! in_array((string) $order->number, $guestOrderNumbers, true);
@endphp

<div class="card mt-4" id="offline-transfer-payment-instruction">
  <div class="card-body">
    <h5 class="card-title">{{ __('OfflineTransfer::common.checkout_title') }}</h5>
    <p class="text-secondary mb-4">{{ __('OfflineTransfer::common.checkout_description') }}</p>

    <dl class="row mb-4">
      <dt class="col-sm-4">{{ __('OfflineTransfer::common.payment_amount') }}</dt>
      <dd class="col-sm-8"><strong>{{ $offline_transfer_instruction['amount'] }} {{ $offline_transfer_instruction['currency'] }}</strong></dd>
      <dt class="col-sm-4">{{ __('order.number') }}</dt>
      <dd class="col-sm-8"><code>{{ $offline_transfer_instruction['order_number'] }}</code></dd>
    </dl>

    <div class="alert alert-light border mb-4" style="white-space: pre-line">{{ $offline_transfer_instruction['transfer_instruction'] }}</div>
    <div class="alert alert-warning">{{ __('OfflineTransfer::common.pending_verification_hint') }}</div>

    <form id="offline-transfer-declaration-form" action="{{ shop_route('offline-transfer.orders.declare', ['number' => $order->number]) }}" method="POST" enctype="multipart/form-data">
      @csrf
      @if ($needGuestEmail)
        <div class="mb-3">
          <label class="form-label" for="offline-transfer-email">{{ __('OfflineTransfer::common.order_email') }}</label>
          <input id="offline-transfer-email" class="form-control" type="email" name="email" required>
        </div>
      @endif
      <div class="mb-3">
        <label class="form-label" for="offline-transfer-transaction">{{ __('OfflineTransfer::common.transaction_id') }}</label>
        <input id="offline-transfer-transaction" class="form-control" name="transaction_id" maxlength="128">
      </div>
      <div class="mb-3">
        <label class="form-label" for="offline-transfer-payer">{{ __('OfflineTransfer::common.payer_name') }}</label>
        <input id="offline-transfer-payer" class="form-control" name="payer_name" maxlength="255">
      </div>
      <div class="mb-3">
        <label class="form-label" for="offline-transfer-paid-at">{{ __('OfflineTransfer::common.paid_at') }}</label>
        <input id="offline-transfer-paid-at" class="form-control" type="datetime-local" name="paid_at">
      </div>
      <div class="mb-3">
        <label class="form-label" for="offline-transfer-receipt">{{ __('OfflineTransfer::common.receipt') }}</label>
        <input id="offline-transfer-receipt" class="form-control" type="file" name="receipt" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf" @if ($offline_transfer_instruction['receipt_required']) required @endif>
        <div class="form-text">{{ __('OfflineTransfer::common.receipt_help') }}</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="offline-transfer-declaration-note">{{ __('OfflineTransfer::common.note') }}</label>
        <textarea id="offline-transfer-declaration-note" class="form-control" name="note" rows="2" maxlength="500"></textarea>
      </div>
      <button class="btn btn-primary" type="submit">{{ __('OfflineTransfer::common.submit_declaration') }}</button>
      <div class="small mt-3" id="offline-transfer-declaration-result" aria-live="polite"></div>
    </form>
    <div class="mt-3">
      <a class="btn btn-outline-secondary" href="{{ shop_route('orders.show', ['number' => $order->number, 'email' => $order->email]) }}">
        {{ __('OfflineTransfer::common.view_order') }}
      </a>
    </div>
  </div>
</div>

<script>
  // 凭证申报只写入待审核记录，前端不会直接把订单标记为已支付。
  (function () {
    const form = document.getElementById('offline-transfer-declaration-form');
    if (!form) {
      return;
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      const submitButton = form.querySelector('button[type="submit"]');
      const result = document.getElementById('offline-transfer-declaration-result');
      submitButton.disabled = true;
      result.className = 'small mt-3 text-secondary';
      result.textContent = '{{ __('OfflineTransfer::common.submitting') }}';

      fetch(form.action, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value
        },
        body: new FormData(form)
      }).then(function (response) {
        return response.json().then(function (payload) {
          return {ok: response.ok, payload: payload};
        });
      }).then(function (resultData) {
        if (!resultData.ok) {
          throw new Error(resultData.payload.message || '{{ __('OfflineTransfer::common.declaration_failed') }}');
        }

        result.className = 'small mt-3 text-success';
        result.textContent = resultData.payload.message || '{{ __('OfflineTransfer::common.declaration_saved') }}';
      }).catch(function (error) {
        result.className = 'small mt-3 text-danger';
        result.textContent = error.message;
      }).finally(function () {
        submitButton.disabled = false;
      });
    });
  })();
</script>
