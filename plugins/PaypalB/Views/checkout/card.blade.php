@extends('PaypalB::layout.checkout')

@php
  $billing = (array) data_get($transaction->buyer_snapshot, 'billing_address', []);
  $cardBillingAddress = array_filter([
    'addressLine1' => trim((string) ($billing['address_1'] ?? '')),
    'addressLine2' => trim((string) ($billing['address_2'] ?? '')),
    'adminArea2'   => trim((string) ($billing['city'] ?? '')),
    'adminArea1'   => trim((string) ($billing['zone'] ?? '')),
    'postalCode'   => trim((string) ($billing['postal_code'] ?? '')),
    'countryCode'  => strtoupper(trim((string) ($billing['country_code'] ?? ''))),
  ], static fn (string $value): bool => $value !== '');
  $sdkUrl = 'https://www.paypal.com/sdk/js?' . http_build_query([
      'components' => 'card-fields',
      'client-id'  => $account->client_id,
      'currency'   => strtoupper((string) $transaction->currency),
      'intent'     => 'capture',
      // 托管卡片字段的占位文案由 PayPal 按此语言渲染，必须与页面语言一致。
      'locale'     => $paypal_locale,
  ], '', '&', PHP_QUERY_RFC3986);

  // Blade 的 @json 指令无法解析跨行数组字面量，必须先在 PHP 块中组装。
  $cardMessages = [
      'incomplete'   => trans('PaypalB::common.card_incomplete'),
      'completed'    => trans('PaypalB::common.payment_completed'),
      'accepted'     => trans('PaypalB::common.payment_accepted'),
      'confirming'   => trans('PaypalB::common.payment_confirming'),
      'not_done'     => trans('PaypalB::common.payment_not_done'),
      'load_failed'  => trans('PaypalB::common.card_load_failed'),
      'not_eligible' => trans('PaypalB::common.card_not_eligible'),
      'failed'       => trans('PaypalB::common.card_failed'),
      'invalid'      => trans('PaypalB::common.card_invalid'),
  ];
@endphp

@section('title', trans('PaypalB::common.card_title'))

@section('content')
  <div class="text-center mb-4">
    <h1 class="h4 mb-1">{{ trans('PaypalB::common.card_title') }}</h1>
    <div class="text-secondary">{{ trans('PaypalB::common.card_subtitle') }}</div>
  </div>

  @include('PaypalB::checkout.summary')

  <div id="paypal-card-message" class="alert d-none" role="alert"></div>

  <form id="paypal-card-form" novalidate>
    <div class="mb-3">
      <label for="paypal-card-name" class="form-label fw-semibold">{{ trans('PaypalB::common.card_holder_name') }}</label>
      <div id="paypal-card-name" class="paypal-hosted-field form-control p-0"></div>
    </div>
    <div class="mb-3">
      <label for="paypal-card-number" class="form-label fw-semibold">{{ trans('PaypalB::common.card_number') }}</label>
      <div id="paypal-card-number" class="paypal-hosted-field form-control p-0"></div>
    </div>
    <div class="row">
      <div class="col-12 col-sm-6 mb-3">
        <label for="paypal-card-expiry" class="form-label fw-semibold">{{ trans('PaypalB::common.card_expiry') }}</label>
        <div id="paypal-card-expiry" class="paypal-hosted-field form-control p-0"></div>
      </div>
      <div class="col-12 col-sm-6 mb-3">
        <label for="paypal-card-cvv" class="form-label fw-semibold">{{ trans('PaypalB::common.card_cvv') }}</label>
        <div id="paypal-card-cvv" class="paypal-hosted-field form-control p-0"></div>
      </div>
    </div>
    <button id="paypal-card-submit" type="submit" class="btn btn-primary w-100 btn-lg mt-2">
      {{ trans('PaypalB::common.card_submit') }}
    </button>
  </form>
@endsection

@push('scripts')
  <script src="{{ $sdkUrl }}" data-client-token="{{ $client_token }}" referrerpolicy="no-referrer"></script>
  <script>
    (() => {
      const form = document.getElementById('paypal-card-form');
      const submit = document.getElementById('paypal-card-submit');
      const messageBox = document.getElementById('paypal-card-message');
      const captureUrl = @json($capture_url);
      const statusUrl = @json($status_url);
      const captureToken = @json($capture_token);
      const returnUrl = @json($return_url);
      const billingAddress = @json($cardBillingAddress);
      const i18n = @json($cardMessages);

      const showMessage = (message, state = 'error') => {
        const variant = state === 'success' ? 'alert-success' : (state === 'pending' ? 'alert-warning' : 'alert-danger');
        messageBox.textContent = message;
        messageBox.className = `alert ${variant}`;
        submit.disabled = state !== 'error';
      };

      const fail = (error, fallback) => {
        showMessage(error && error.message ? error.message : fallback);
        submit.disabled = false;
      };

      const post = async (url, body) => {
        const response = await fetch(url, {
          method: 'POST',
          credentials: 'omit',
          cache: 'no-store',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify(body),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
          throw new Error(payload.message || i18n.incomplete);
        }

        return payload;
      };

      const finish = (payload) => {
        showMessage(i18n.completed, 'success');
        window.location.assign(returnUrl);
      };

      const capture = async () => {
        const clientMetadataId = typeof window.paypal.getClientMetadataID === 'function'
          ? window.paypal.getClientMetadataID()
          : '';
        const payload = await post(captureUrl, {
          method: 'card',
          capture_token: captureToken,
          client_metadata_id: clientMetadataId || '',
        });
        if (payload.status === 'completed') {
          finish(payload);
          return;
        }

        showMessage(payload.message || i18n.accepted, 'pending');
        for (let attempt = 0; attempt < 15; attempt += 1) {
          await new Promise((resolve) => setTimeout(resolve, 2000));
          const status = await post(statusUrl, { capture_token: captureToken });
          if (status.status === 'completed') {
            finish(status);
            return;
          }
          if (['failed', 'cancelled', 'expired'].includes(status.status)) {
            throw new Error(status.message || i18n.not_done);
          }
        }

        showMessage(i18n.confirming, 'pending');
      };

      if (!window.paypal || !window.paypal.CardFields) {
        fail(null, i18n.load_failed);
        return;
      }

      const cardFields = window.paypal.CardFields({
        createOrder: () => Promise.resolve(@json((string) $transaction->paypal_order_id)),
        onApprove: () => capture().catch((error) => fail(error, i18n.failed)),
        onError: (error) => fail(error, i18n.failed),
      });

      if (!cardFields.isEligible()) {
        fail(null, i18n.not_eligible);
        return;
      }

      if (typeof cardFields.NameField === 'function') {
        cardFields.NameField().render('#paypal-card-name');
      }
      cardFields.NumberField().render('#paypal-card-number');
      cardFields.ExpiryField().render('#paypal-card-expiry');
      cardFields.CVVField().render('#paypal-card-cvv');

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        submit.disabled = true;
        messageBox.className = 'alert d-none';
        cardFields.submit({ billingAddress }).catch((error) => fail(error, i18n.invalid));
      });
    })();
  </script>
@endpush
