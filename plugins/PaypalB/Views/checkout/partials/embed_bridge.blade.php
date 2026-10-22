{{--
  内嵌模式下与 A 站父页面通信的唯一通道。所有结账页共用，保证事件名与载荷一致。

  非内嵌时 window.PaypalBEmbed 仍存在但所有方法都是空操作，调用方不必判断模式。
  消息只作为“去查状态”的触发信号：A 站收到后一律通过服务端签名接口拉取真实状态，
  因此这里不携带任何金额或状态细节，被伪造也不会影响订单。

  事件：ready | resize | completed | pending_capture | failed | cancelled | ended | load_failed
--}}
<script>
  (() => {
    const embedded = @json(! empty($embedded));
    const origin = @json((string) ($embed_origin ?? ''));
    const reference = @json((string) ($embed_reference ?? ''));
    const framed = (() => {
      try {
        return window.parent && window.parent !== window;
      } catch (error) {
        return true;
      }
    })();
    const active = embedded && origin !== '' && framed;

    const send = (event, extra = {}) => {
      if (!active) return;
      try {
        window.parent.postMessage({ source: 'paypal_b', event, reference, ...extra }, origin);
      } catch (error) {
        // 父页面 origin 与配置不一致时浏览器会拒绝投递；A 站的加载超时会自行降级。
      }
    };

    window.PaypalBEmbed = {
      active,
      send,
      // 父页面按上报高度调整 iframe，避免内嵌区域出现滚动条或大片空白。
      observe: () => {
        if (!active) return;
        const report = () => send('resize', { height: Math.ceil(document.documentElement.scrollHeight) });
        report();
        if (typeof ResizeObserver === 'function') {
          new ResizeObserver(report).observe(document.body);
        } else {
          window.setInterval(report, 1000);
        }
        window.addEventListener('load', report);
      },
    };

    if (active) {
      document.documentElement.classList.add('pb-embedded');
      send('ready');
      window.PaypalBEmbed.observe();
    }
  })();
</script>
