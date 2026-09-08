@extends('PaypalB::layout.checkout')

@php
  $sdkUrl = 'https://www.paypal.com/sdk/js?' . http_build_query([
      'components' => 'buttons',
      'client-id'  => $account->client_id,
      'currency'   => strtoupper((string) $transaction->currency),
      'intent'     => 'capture',
      'commit'     => 'true',
      // PayPal SDK 只认 xx_XX 形式；与页面语言保持一致，避免按钮和正文语言不同。
      'locale'     => $paypal_locale,
  ], '', '&', PHP_QUERY_RFC3986);

  // Blade 的 @json 指令无法解析跨行数组字面量，必须先在 PHP 块中组装。
  $walletMessages = [
      'incomplete'  => trans('PaypalB::common.wallet_incomplete'),
      'completed'   => trans('PaypalB::common.payment_completed'),
      'accepted'    => trans('PaypalB::common.payment_accepted'),
      'confirming'  => trans('PaypalB::common.payment_confirming'),
      'not_done'    => trans('PaypalB::common.payment_not_done'),
      'load_failed' => trans('PaypalB::common.wallet_load_failed'),
      'unavailable' => trans('PaypalB::common.wallet_unavailable'),
      'failed'      => trans('PaypalB::common.wallet_failed'),
      'cancelled'   => trans('PaypalB::common.wallet_cancelled'),
  ];
@endphp

@section('title', trans('PaypalB::common.wallet_title'))

@section('content')
  <div class="text-center mb-4">
    <h1 class="h4 mb-1">{{ trans('PaypalB::common.wallet_title') }}</h1>
    <div class="text-secondary">{{ trans('PaypalB::common.wallet_subtitle') }}</div>
  </div>

  @include('PaypalB::checkout.summary')

  <div id="paypal-wallet-message" class="alert d-none" role="alert"></div>
  <div id="paypal-wallet-buttons" style="min-height: 48px"></div>
@endsection

@push('scripts')
  <script src="{{ $sdkUrl }}" referrerpolicy="no-referrer"></script>
  <script>
    (() => {
      const messageBox = document.getElementById('paypal-wallet-message');
      const captureUrl = @json($capture_url);
      const statusUrl = @json($status_url);
      const captureToken = @json($capture_token);
      const returnUrl = @json($return_url);
      const i18n = @json($walletMessages);

      const showMessage = (message, state = 'error') => {
        const variant = state === 'success' ? 'alert-success' : (state === 'pending' ? 'alert-warning' : 'alert-danger');
        messageBox.textContent = message;
        messageBox.className = `alert ${variant}`;
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
          method: 'wallet',
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

      if (!window.paypal || !window.paypal.Buttons) {
        showMessage(i18n.load_failed);
        return;
      }

      window.paypal.Buttons({
        createOrder: () => Promise.resolve(@json((string) $transaction->paypal_order_id)),
        onApprove: () => capture().catch((error) => {
          showMessage(error && error.message ? error.message : i18n.failed);
        }),
        onCancel: () => {
          showMessage(i18n.cancelled);
        },
        onError: (error) => {
          showMessage(error && error.message ? error.message : i18n.failed);
        },
      }).render('#paypal-wallet-buttons').catch((error) => {
        showMessage(error && error.message ? error.message : i18n.unavailable);
      });
    })();
  </script>
@endpush
