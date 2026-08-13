@php
  $guestOrderNumbers = array_map('strval', session('guest_order_numbers', []));
  $needGuestEmail = ! $order->customer_id && ! in_array((string) $order->number, $guestOrderNumbers, true);
@endphp

<div class="card mt-4" id="zelle-payment-instruction">
  <div class="card-body">
    <h5 class="card-title">{{ __('Zelle::common.checkout_title') }}</h5>
    <p class="text-secondary mb-4">{{ __('Zelle::common.checkout_description') }}</p>

    <dl class="row mb-4">
      <dt class="col-sm-4">{{ __('Zelle::common.recipient_name') }}</dt>
      <dd class="col-sm-8">{{ $zelle_instruction['recipient_name'] }}</dd>
      <dt class="col-sm-4">{{ $zelle_instruction['recipient_type_label'] }}</dt>
      <dd class="col-sm-8"><code>{{ $zelle_instruction['recipient_identifier'] }}</code></dd>
      <dt class="col-sm-4">{{ __('Zelle::common.payment_amount') }}</dt>
      <dd class="col-sm-8"><strong>{{ $zelle_instruction['amount'] }} {{ $zelle_instruction['currency'] }}</strong></dd>
      <dt class="col-sm-4">{{ __('order.number') }}</dt>
      <dd class="col-sm-8"><code>{{ $zelle_instruction['order_number'] }}</code></dd>
    </dl>

    @if ($zelle_instruction['payment_note'])
      <div class="alert alert-light border">{{ $zelle_instruction['payment_note'] }}</div>
    @endif

    <div class="alert alert-warning">{{ __('Zelle::common.pending_verification_hint') }}</div>

    <form id="zelle-declaration-form" action="{{ shop_route('zelle.orders.declare', ['number' => $order->number]) }}" method="POST">
      @csrf
      @if ($needGuestEmail)
        <div class="mb-3">
          <label class="form-label" for="zelle-email">{{ __('Zelle::common.order_email') }}</label>
          <input id="zelle-email" class="form-control" type="email" name="email" required>
        </div>
      @endif
      <div class="mb-3">
        <label class="form-label" for="zelle-transaction">{{ __('Zelle::common.transaction_id') }}</label>
        <input id="zelle-transaction" class="form-control" name="transaction_id" required maxlength="128">
      </div>
      <div class="mb-3">
        <label class="form-label" for="zelle-payer">{{ __('Zelle::common.payer_name') }}</label>
        <input id="zelle-payer" class="form-control" name="payer_name" maxlength="255">
      </div>
      <div class="mb-3">
        <label class="form-label" for="zelle-paid-at">{{ __('Zelle::common.paid_at') }}</label>
        <input id="zelle-paid-at" class="form-control" type="datetime-local" name="paid_at">
      </div>
      <div class="mb-3">
        <label class="form-label" for="zelle-declaration-note">{{ __('Zelle::common.note') }}</label>
        <textarea id="zelle-declaration-note" class="form-control" name="note" rows="2" maxlength="500"></textarea>
      </div>
      <button class="btn btn-primary" type="submit">{{ __('Zelle::common.submit_declaration') }}</button>
      <div class="small mt-3" id="zelle-declaration-result" aria-live="polite"></div>
    </form>
  </div>
</div>

<script>
  // 付款声明仅写入审计记录，前端不会直接把订单标记为已支付。
  (function () {
    const form = document.getElementById('zelle-declaration-form');
    if (!form) {
      return;
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      const submitButton = form.querySelector('button[type="submit"]');
      const result = document.getElementById('zelle-declaration-result');
      submitButton.disabled = true;
      result.className = 'small mt-3 text-secondary';
      result.textContent = '{{ __('Zelle::common.submitting') }}';

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
          throw new Error(resultData.payload.message || '{{ __('Zelle::common.declaration_failed') }}');
        }

        result.className = 'small mt-3 text-success';
        result.textContent = resultData.payload.message || '{{ __('Zelle::common.declaration_saved') }}';
      }).catch(function (error) {
        result.className = 'small mt-3 text-danger';
        result.textContent = error.message;
      }).finally(function () {
        submitButton.disabled = false;
      });
    });
  })();
</script>
