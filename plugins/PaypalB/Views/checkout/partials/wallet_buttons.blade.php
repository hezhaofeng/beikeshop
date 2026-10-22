{{--
  PayPal 钱包按钮与服务端捕获脚本。B 站托管结账页和本地支付页共用：
  授权、捕获、轮询和提示逻辑必须完全一致，否则修一处就会漏一处。

  $wallet 键：client_id、currency、paypal_locale、paypal_order_id、capture_url、status_url、
  capture_token、return_url。credentials 可选：托管页没有买家会话，默认 omit；
  本地支付页传 same-origin 以复用商城会话，避免每次轮询都新建一条会话记录。
--}}
@php
  $walletSdkUrl = 'https://www.paypal.com/sdk/js?' . http_build_query([
      'components' => 'buttons',
      'client-id'  => $wallet['client_id'],
      'currency'   => strtoupper((string) $wallet['currency']),
      'intent'     => 'capture',
      'commit'     => 'true',
      // PayPal SDK 只认 xx_XX 形式；与页面语言保持一致，避免按钮和正文语言不同。
      'locale'     => $wallet['paypal_locale'],
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
  $walletCredentials = ($wallet['credentials'] ?? 'omit') === 'same-origin' ? 'same-origin' : 'omit';
@endphp

<div id="paypal-b-wallet-message" class="alert d-none" role="alert"></div>
<div id="paypal-b-wallet-buttons" style="min-height: 48px"></div>

<script src="{{ $walletSdkUrl }}" referrerpolicy="no-referrer"></script>
<script>
  (() => {
    const messageBox = document.getElementById('paypal-b-wallet-message');
    const captureUrl = @json($wallet['capture_url']);
    const statusUrl = @json($wallet['status_url']);
    const captureToken = @json($wallet['capture_token']);
    const returnUrl = @json($wallet['return_url']);
    const paypalOrderId = @json((string) $wallet['paypal_order_id']);
    const credentials = @json($walletCredentials);
    const i18n = @json($walletMessages);

    const showMessage = (message, state = 'error') => {
      const variant = state === 'success' ? 'alert-success' : (state === 'pending' ? 'alert-warning' : 'alert-danger');
      messageBox.textContent = message;
      messageBox.className = `alert ${variant}`;
    };
    // 内嵌时把关键节点告诉 A 站父页面；非内嵌时 PaypalBEmbed.send 是空操作。
    const notify = (event) => {
      if (window.PaypalBEmbed) window.PaypalBEmbed.send(event);
    };

    const post = async (url, body) => {
      const response = await fetch(url, {
        method: 'POST',
        credentials,
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

    const finish = () => {
      showMessage(i18n.completed, 'success');
      notify('completed');
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
        finish();
        return;
      }

      showMessage(payload.message || i18n.accepted, 'pending');
      for (let attempt = 0; attempt < 15; attempt += 1) {
        await new Promise((resolve) => setTimeout(resolve, 2000));
        const status = await post(statusUrl, { capture_token: captureToken });
        if (status.status === 'completed') {
          finish();
          return;
        }
        if (['failed', 'cancelled', 'expired'].includes(status.status)) {
          throw new Error(status.message || i18n.not_done);
        }
      }

      showMessage(i18n.confirming, 'pending');
      notify('pending_capture');
      // 买家已付款、PayPal 仍在确认：送回原站点展示“等待确认”，不要留在支付页诱导重复付款。
      setTimeout(() => window.location.assign(returnUrl), 3000);
    };

    if (!window.paypal || !window.paypal.Buttons) {
      showMessage(i18n.load_failed);
      notify('load_failed');
      return;
    }

    window.paypal.Buttons({
      createOrder: () => Promise.resolve(paypalOrderId),
      onApprove: () => capture().catch((error) => {
        showMessage(error && error.message ? error.message : i18n.failed);
        notify('failed');
      }),
      onCancel: () => {
        showMessage(i18n.cancelled);
        notify('cancelled');
      },
      onError: (error) => {
        showMessage(error && error.message ? error.message : i18n.failed);
        notify('failed');
      },
    }).render('#paypal-b-wallet-buttons').catch((error) => {
      showMessage(error && error.message ? error.message : i18n.unavailable);
      notify('load_failed');
    });
  })();
</script>
