{{--
  内嵌模式：与原生 Paypal 插件一样，主题卡片已展示订单号和金额，这里只放一个按钮容器；
  容器内是 B 站结账页的 iframe，PayPal 按钮由 iframe 内的 PayPal SDK 绘制。

  安全边界：
  - 只接受 b_site_origin 发来的 postMessage，且 reference 必须与当前会话一致；
  - 消息只是“去查状态”的触发信号，页面随后调用本站 status 接口，由服务端向 B 站签名拉取；
  - 任何跳转到成功页的动作都以 status 接口返回的 redirect_url 为准。
--}}
@php
  $embed = $paypal_a_instruction['embedded'];
  $embedMessages = [
      'loading'     => trans('PaypalA::common.embedded_loading'),
      'unavailable' => trans('PaypalA::common.embedded_unavailable'),
      'retry'       => trans('PaypalA::common.embedded_retry'),
      'redirecting' => trans('PaypalA::common.embedded_redirecting'),
      'confirming'  => trans('PaypalA::common.embedded_confirming'),
      'pending'     => trans('PaypalA::common.capture_pending_notice'),
      'start_failed'=> trans('PaypalA::common.start_failed'),
  ];
  $methods = array_values(array_filter([
      $paypal_a_instruction['wallet_enabled'] ? 'wallet' : null,
      $paypal_a_instruction['card_enabled'] ? 'card' : null,
  ]));
@endphp

<div id="paypal-a-embedded" data-methods="{{ implode(',', $methods) }}">
  @if(count($methods) > 1)
    <div class="btn-group mb-3" role="group" id="paypal-a-embedded-methods">
      <button type="button" class="btn btn-outline-primary active" data-method="wallet">{{ trans('PaypalA::common.pay_with_wallet') }}</button>
      <button type="button" class="btn btn-outline-primary" data-method="card">{{ trans('PaypalA::common.pay_with_card') }}</button>
    </div>
  @endif

  <div id="paypal-a-embedded-message" class="alert d-none" role="status"></div>

  {{-- 与原生 Paypal 插件相同的容器 id，便于主题样式复用。 --}}
  <div id="paypal-button-container" class="position-relative" style="min-height: 120px">
    <div id="paypal-a-embedded-loading" class="d-flex align-items-center justify-content-center text-secondary" style="min-height: 120px">
      <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>{{ $embedMessages['loading'] }}
    </div>
  </div>
</div>

<script>
  (() => {
    const root = document.getElementById('paypal-a-embedded');
    const container = document.getElementById('paypal-button-container');
    const loading = document.getElementById('paypal-a-embedded-loading');
    const messageBox = document.getElementById('paypal-a-embedded-message');
    const methodButtons = Array.from(document.querySelectorAll('#paypal-a-embedded-methods [data-method]'));
    const i18n = @json($embedMessages);
    const config = {
      startUrl: @json($paypal_a_instruction['start_url']),
      statusUrl: @json($paypal_a_instruction['status_url']),
      fallbackUrl: @json(shop_route('paypal_a.orders.embedded_fallback', ['number' => $paypal_a_instruction['order_number']], false)),
      guestEmail: @json($paypal_a_instruction['guest_email']),
      bSiteOrigin: @json($embed['b_site_origin']),
      fallback: @json((bool) $embed['fallback']),
      readyTimeoutMs: @json((int) $embed['ready_timeout_seconds'] * 1000),
      pollMs: @json((int) $embed['status_poll_seconds'] * 1000),
      csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
    };
    const methods = (root.dataset.methods || 'wallet').split(',').filter(Boolean);

    const state = {
      method: methods[0] || 'wallet',
      reference: '',
      checkoutUrl: '',
      frame: null,
      ready: false,
      readyTimer: null,
      pollTimer: null,
      finished: false,
      checking: false,
    };

    const showMessage = (message, variant = 'alert-danger', retry = false) => {
      messageBox.innerHTML = '';
      messageBox.append(document.createTextNode(message));
      if (retry) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-secondary ms-3';
        button.textContent = i18n.retry;
        button.addEventListener('click', () => start(state.method));
        messageBox.append(button);
      }
      messageBox.className = `alert ${variant}`;
    };
    const hideMessage = () => { messageBox.className = 'alert d-none'; };

    const request = async (url, options = {}) => {
      const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        ...options,
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': config.csrf,
          'X-Requested-With': 'XMLHttpRequest',
          ...(options.headers || {}),
        },
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(payload.message || i18n.start_failed);
      }
      return payload;
    };

    const clearTimers = () => {
      if (state.readyTimer) { clearTimeout(state.readyTimer); state.readyTimer = null; }
      if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    };

    const removeFrame = () => {
      if (state.frame) { state.frame.remove(); state.frame = null; }
    };

    // 降级：同一会话顶层跳转到 B 站托管页。会话不需要改，frame-ancestors 只限制被嵌入、不限制顶层打开。
    const degrade = (reason) => {
      if (state.finished) return;
      state.finished = true;
      clearTimers();
      fetch(config.fallbackUrl, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrf },
        body: JSON.stringify({ reason, email: config.guestEmail }),
      }).catch(() => {});
      if (config.fallback && state.checkoutUrl) {
        showMessage(i18n.redirecting, 'alert-info');
        window.location.assign(state.checkoutUrl);
        return;
      }
      removeFrame();
      loading.classList.add('d-none');
      state.finished = false;
      showMessage(i18n.unavailable, 'alert-danger', true);
    };

    // 服务端向 B 站签名拉取真实状态；浏览器消息只决定“现在去查一次”。
    const checkStatus = async () => {
      if (state.finished || state.checking) return;
      state.checking = true;
      try {
        const query = new URLSearchParams({ email: config.guestEmail }).toString();
        const payload = await request(`${config.statusUrl}?${query}`, { method: 'GET' });
        if (payload.redirect_url) {
          state.finished = true;
          clearTimers();
          showMessage(i18n.confirming, 'alert-success');
          window.location.assign(payload.redirect_url);
          return;
        }
        if (payload.capture_pending) {
          state.finished = true;
          clearTimers();
          removeFrame();
          showMessage(i18n.pending, 'alert-warning');
          return;
        }
        if (['failed', 'cancelled', 'expired'].includes(payload.status) && payload.message) {
          // 会话已终结：允许买家重新发起，创建新的会话。
          clearTimers();
          removeFrame();
          loading.classList.add('d-none');
          showMessage(payload.message, 'alert-danger', true);
        }
      } catch (error) {
        // 状态查询失败不代表付款失败，下一轮轮询会再试。
      } finally {
        state.checking = false;
      }
    };

    const onMessage = (event) => {
      if (event.origin !== config.bSiteOrigin) return;
      const data = event.data || {};
      if (data.source !== 'paypal_b' || data.reference !== state.reference) return;

      switch (data.event) {
        case 'ready':
          state.ready = true;
          if (state.readyTimer) { clearTimeout(state.readyTimer); state.readyTimer = null; }
          loading.classList.add('d-none');
          if (state.frame) state.frame.classList.remove('invisible');
          break;
        case 'resize':
          if (state.frame && Number.isFinite(data.height) && data.height > 0) {
            state.frame.style.height = `${Math.max(120, Math.ceil(data.height))}px`;
          }
          break;
        case 'load_failed':
          degrade('sdk_load_failed');
          break;
        case 'completed':
        case 'pending_capture':
        case 'failed':
        case 'cancelled':
        case 'ended':
          checkStatus();
          break;
        default:
          break;
      }
    };

    const mountFrame = (checkoutUrl) => {
      removeFrame();
      const frame = document.createElement('iframe');
      frame.src = checkoutUrl;
      frame.title = 'PayPal';
      frame.className = 'w-100 border-0 invisible';
      frame.style.height = '160px';
      frame.setAttribute('allow', 'payment *');
      frame.setAttribute('referrerpolicy', 'no-referrer');
      frame.setAttribute('scrolling', 'no');
      container.append(frame);
      state.frame = frame;
      state.ready = false;
      state.readyTimer = setTimeout(() => {
        if (!state.ready) degrade('ready_timeout');
      }, config.readyTimeoutMs);
      state.pollTimer = setInterval(checkStatus, config.pollMs);
    };

    const start = async (method) => {
      hideMessage();
      clearTimers();
      removeFrame();
      state.finished = false;
      state.method = method;
      loading.classList.remove('d-none');
      methodButtons.forEach((button) => button.classList.toggle('active', button.dataset.method === method));

      try {
        const payload = await request(config.startUrl, {
          method: 'POST',
          body: JSON.stringify({ method, email: config.guestEmail }),
        });
        state.reference = payload.reference || '';
        state.checkoutUrl = payload.checkout_url || '';
        if (!state.checkoutUrl) throw new Error(i18n.start_failed);
        if (payload.presentation !== 'embedded') {
          // B 站按顶层托管建了会话（不支持内嵌或已被服务端降级）：直接跳转。
          showMessage(i18n.redirecting, 'alert-info');
          window.location.assign(state.checkoutUrl);
          return;
        }
        mountFrame(state.checkoutUrl);
      } catch (error) {
        loading.classList.add('d-none');
        showMessage(error && error.message ? error.message : i18n.start_failed, 'alert-danger', true);
      }
    };

    methodButtons.forEach((button) => {
      button.addEventListener('click', () => {
        if (button.dataset.method !== state.method) start(button.dataset.method);
      });
    });

    window.addEventListener('message', onMessage);
    start(state.method);
  })();
</script>
