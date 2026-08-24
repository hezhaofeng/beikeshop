@php
  $trackingConfig = $config ?? [];
@endphp
<script>
  window.beikeAdTrackingConfig = @json($trackingConfig);

  (function () {
    const config = window.beikeAdTrackingConfig || {};
    if (window.beikeAdTracking) return;

    const state = {
      platformsLoaded: false,
      consent: null,
      consentBannerBound: false,
      gtagBooted: false,
    };
    const platforms = config.platforms || {};
    const enabledEvents = Array.isArray(config.enabled_events) ? config.enabled_events : [];
    const facebookDispatchMode = typeof config.facebook_dispatch_mode === 'string'
      ? config.facebook_dispatch_mode
      : 'failover';
    const facebookPixelIds = Array.isArray(config.facebook_pixel_ids)
      ? config.facebook_pixel_ids.filter(function (pixelId) { return !!pixelId; })
      : (config.facebook_pixel_id ? [config.facebook_pixel_id] : []);
    const facebookBrowserPixelIds = Array.isArray(config.facebook_browser_pixel_ids)
      ? config.facebook_browser_pixel_ids.filter(function (pixelId) { return !!pixelId; })
      : (facebookDispatchMode === 'broadcast' ? facebookPixelIds : facebookPixelIds.slice(0, 1));

    const getCookie = function (name) {
      const prefix = name + '=';
      const item = document.cookie.split('; ').find((row) => row.indexOf(prefix) === 0);
      return item ? decodeURIComponent(item.substring(prefix.length)) : '';
    };

    const hasConsent = function () {
      if (config.consent_mode === 'always') return true;
      if (window.beikeAdConsent === true) return true;
      if (state.consent === true) return true;
      try {
        if (window.localStorage.getItem('beike_ad_consent') === 'granted') return true;
      } catch (e) {
        // 浏览器禁用 localStorage 时继续检查 Cookie。
      }
      return getCookie('beike_ad_consent') === 'granted';
    };

    const consentDecision = function () {
      try {
        const localValue = window.localStorage.getItem('beike_ad_consent');
        if (localValue === 'granted' || localValue === 'denied') return localValue;
      } catch (e) {
        // 本地存储不可用时回退到 Cookie。
      }
      const cookieValue = getCookie('beike_ad_consent');
      if (cookieValue === 'granted' || cookieValue === 'denied') return cookieValue;
      return '';
    };

    // 平台和事件开关统一在前端收口，避免后台开关和实际发送行为不一致。
    const platformEnabled = function (platform, channel) {
      const item = platforms[platform] || {};
      if (!item || item.enabled !== true) return false;
      if (channel === 'server') return item.server_enabled === true;
      return item.browser_enabled === true;
    };

    const eventEnabled = function (name) {
      return enabledEvents.includes(name);
    };

    const pageParams = function () {
      return {
        page_location: window.location.href,
        page_path: window.location.pathname,
        page_referrer: document.referrer || '',
        page_title: document.title,
        language: document.documentElement.lang || ''
      };
    };

    const loadScript = function (src, onload) {
      if (document.querySelector('script[src="' + src + '"]')) {
        if (onload) onload();
        return;
      }
      const script = document.createElement('script');
      script.async = true;
      script.src = src;
      if (onload) script.onload = onload;
      document.head.appendChild(script);
    };

    const ensureDataLayer = function () {
      window.dataLayer = window.dataLayer || [];
      return window.dataLayer;
    };

    const ensureGtag = function () {
      ensureDataLayer();
      window.gtag = window.gtag || function () {
        window.dataLayer.push(arguments);
      };
      if (!state.gtagBooted) {
        window.gtag('js', new Date());
        state.gtagBooted = true;
      }
    };

    const loadPlatforms = function () {
      if (state.platformsLoaded || !hasConsent()) return;
      state.platformsLoaded = true;

      if (platformEnabled('facebook', 'browser') && facebookBrowserPixelIds.length > 0) {
        !function (f, b, e, v, n, t, s) {
          if (f.fbq) return;
          n = f.fbq = function () {
            n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments)
          };
          if (!f._fbq) f._fbq = n;
          n.push = n;
          n.loaded = true;
          n.version = '2.0';
          n.queue = [];
          t = b.createElement(e);
          t.async = true;
          t.src = v;
          s = b.getElementsByTagName(e)[0];
          s.parentNode.insertBefore(t, s);
        }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
        facebookBrowserPixelIds.forEach(function (pixelId) {
          window.fbq('init', pixelId);
        });
      }

      if (
        (platformEnabled('ga4', 'browser') && config.ga4_measurement_id)
        || (platformEnabled('google_ads', 'browser') && config.google_ads_id)
      ) {
        ensureGtag();
        if (platformEnabled('ga4', 'browser') && config.ga4_measurement_id) {
          window.gtag('config', config.ga4_measurement_id);
        }
        if (platformEnabled('google_ads', 'browser') && config.google_ads_id) {
          window.gtag('config', config.google_ads_id);
        }
        const googleId = config.ga4_measurement_id || config.google_ads_id;
        loadScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(googleId));
      }

      if (platformEnabled('google_ads', 'browser') && config.google_tag_manager_id) {
        ensureDataLayer();
        window.dataLayer.push({
          'gtm.start': new Date().getTime(),
          event: 'gtm.js'
        });
        loadScript('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(config.google_tag_manager_id));
      }

      if (platformEnabled('tiktok', 'browser') && config.tiktok_pixel_id) {
        window.ttq = window.ttq || function () {
          window.ttq.queue.push(arguments);
        };
        window.ttq.queue = window.ttq.queue || [];
        window.ttq.load = window.ttq.load || function (pixelId) {
          window.ttq('load', pixelId);
        };
        window.ttq.track = window.ttq.track || function (eventName, payload) {
          window.ttq('track', eventName, payload);
        };
        window.ttq.load(config.tiktok_pixel_id);
        loadScript('https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=' + encodeURIComponent(config.tiktok_pixel_id) + '&lib=ttq');
      }

      if (platformEnabled('twitter', 'browser') && config.twitter_pixel_id) {
        window.twq = window.twq || function () {
          window.twq.exe ? window.twq.exe.apply(window.twq, arguments) : window.twq.queue.push(arguments);
        };
        window.twq.version = '1.1';
        window.twq.queue = [];
        window.twq('config', config.twitter_pixel_id);
        loadScript('https://static.ads-twitter.com/uwt.js');
      }

      if (platformEnabled('pinterest', 'browser') && config.pinterest_tag_id) {
        window.pintrk = window.pintrk || function () {
          window.pintrk.queue.push(Array.prototype.slice.call(arguments));
        };
        window.pintrk.queue = window.pintrk.queue || [];
        window.pintrk('load', config.pinterest_tag_id);
        loadScript('https://s.pinimg.com/ct/core.js');
      }
    };

    const ga4Name = function (name) {
      return {
        PageView: 'page_view',
        ViewContent: 'view_item',
        AddToCart: 'add_to_cart',
        InitiateCheckout: 'begin_checkout',
        Purchase: 'purchase',
        Search: 'search'
      }[name] || name.toLowerCase();
    };

    const track = function (name, params) {
      if (!eventEnabled(name)) return;
      if (!hasConsent()) return;
      loadPlatforms();
      const data = Object.assign({}, params || {});

      if (window.fbq && platformEnabled('facebook', 'browser') && facebookBrowserPixelIds.length > 0) {
        if (name === 'PageView') {
          window.fbq('track', 'PageView');
        } else {
          window.fbq('track', name, data);
        }
      }

      if (window.gtag && (
        (platformEnabled('ga4', 'browser') && config.ga4_measurement_id)
        || (platformEnabled('google_ads', 'browser') && config.google_ads_id)
      )) {
        window.gtag('event', ga4Name(name), data);
        if (
          name === 'Purchase'
          && platformEnabled('google_ads', 'browser')
          && config.google_ads_id
          && config.google_ads_label
        ) {
          window.gtag('event', 'conversion', {
            send_to: config.google_ads_id + '/' + config.google_ads_label,
            value: Number(data.value || 0),
            currency: data.currency || '',
            transaction_id: data.transaction_id || data.order_id || ''
          });
        }
      }

      if (window.ttq && platformEnabled('tiktok', 'browser') && typeof window.ttq.track === 'function') {
        window.ttq.track(name === 'Purchase' ? 'CompletePayment' : name, data);
      }

      if (window.twq && platformEnabled('twitter', 'browser') && typeof window.twq === 'function') {
        window.twq('track', name, data);
      }

      if (window.pintrk && platformEnabled('pinterest', 'browser')) {
        window.pintrk('track', name, data);
      }

      if (window.dataLayer && platformEnabled('google_ads', 'browser') && config.google_tag_manager_id) {
        window.dataLayer.push(Object.assign({
          event: 'beike_' + name
        }, data));
      }
    };

    const setConsent = function (granted) {
      state.consent = !!granted;
      window.beikeAdConsent = !!granted;
      try {
        window.localStorage.setItem('beike_ad_consent', granted ? 'granted' : 'denied');
      } catch (e) {
        // 存储不可用时仍将状态发送给服务端。
      }
      const csrf = document.querySelector('meta[name="csrf-token"]');
      fetch(config.consent_endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf ? csrf.content : ''
        },
        body: JSON.stringify({granted: !!granted})
      }).catch(function () {});
      if (granted) {
        state.platformsLoaded = false;
        loadPlatforms();
        track('PageView', pageParams());
      }
    };

    window.beikeAdTracking = {
      track: track,
      setConsent: setConsent,
      hasConsent: hasConsent,
      config: config
    };

    const installCartTracking = function () {
      if (!window.bk || typeof window.bk.addCart !== 'function' || window.bk.addCart.__adTrackingWrapped) return;
      const originalAddCart = window.bk.addCart;
      const wrappedAddCart = function (params, event, callback) {
        const button = event
          ? $(event)
          : $('.product-container .add-cart, .product-container .btn-add-cart').first();
        const eventParams = {
          content_type: 'product',
          content_ids: [String((params || {}).sku_id || (button ? button.attr('product-id') : '') || '')],
          item_id: String((params || {}).sku_id || (button ? button.attr('product-id') : '') || ''),
          item_name: button
            ? (button.attr('product-name') || button.closest('.product-container, .product-item, .product-card').find('.product-name').first().text().trim())
            : '',
          unit_price: Number((button ? button.attr('product-price') : 0) || 0),
          value: Number((button ? button.attr('product-price') : 0) || 0) * Number((params || {}).quantity || 1),
          currency: config.currency || '',
          quantity: Number((params || {}).quantity || 1),
          items: []
        };
        eventParams.items = [{
          item_id: eventParams.item_id,
          item_name: eventParams.item_name,
          price: eventParams.unit_price,
          quantity: eventParams.quantity
        }];
        const complete = function (response) {
          if (response && response.status === 'success') {
            track('AddToCart', eventParams);
          }
          if (typeof callback === 'function') callback(response);
        };
        return originalAddCart.call(this, params, event, complete);
      };
      wrappedAddCart.__adTrackingWrapped = true;
      window.bk.addCart = wrappedAddCart;
    };

    const bindConsentBanner = function () {
      const banner = document.querySelector('[data-ad-consent-banner]');
      if (!banner || state.consentBannerBound) return;
      state.consentBannerBound = true;

      const updateBanner = function () {
        banner.style.display = consentDecision() ? 'none' : 'block';
      };

      const accept = banner.querySelector('[data-ad-consent-accept]');
      const decline = banner.querySelector('[data-ad-consent-decline]');
      if (accept) {
        accept.addEventListener('click', function () {
          setConsent(true);
          updateBanner();
        });
      }
      if (decline) {
        decline.addEventListener('click', function () {
          setConsent(false);
          updateBanner();
        });
      }

      updateBanner();
    };

    const initialize = function () {
      loadPlatforms();
      track('PageView', pageParams());

      const keyword = new URLSearchParams(window.location.search).get('keyword');
      if (keyword) track('Search', {
        search_string: keyword,
        search_term: keyword,
        search_location: window.location.pathname
      });
      installCartTracking();
      bindConsentBanner();
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initialize);
    } else {
      initialize();
    }
    let installAttempts = 0;
    const timer = window.setInterval(function () {
      installCartTracking();
      bindConsentBanner();
      installAttempts += 1;
      if ((window.bk && window.bk.addCart && window.bk.addCart.__adTrackingWrapped) || installAttempts >= 20) {
        window.clearInterval(timer);
      }
    }, 200);
  })();
</script>
@if (($trackingConfig['consent_mode'] ?? 'opt_in') === 'opt_in')
  <style>
    .ad-tracking-consent {
      position: fixed;
      left: 16px;
      right: 16px;
      bottom: 16px;
      z-index: 1090;
      max-width: 980px;
      margin: 0 auto;
      border-radius: 14px;
      background: rgba(17, 24, 39, 0.96);
      color: #f8fafc;
      box-shadow: 0 12px 30px rgba(15, 23, 42, 0.25);
      display: none;
    }
    .ad-tracking-consent__inner {
      display: flex;
      gap: 16px;
      align-items: center;
      justify-content: space-between;
      padding: 16px 18px;
      flex-wrap: wrap;
    }
    .ad-tracking-consent__actions {
      display: flex;
      gap: 10px;
    }
    .ad-tracking-consent .btn {
      min-width: 120px;
    }
  </style>
  <div class="ad-tracking-consent" data-ad-consent-banner>
    <div class="ad-tracking-consent__inner">
      <div>
        <div class="fw-bold">{{ __('AdTracking::common.cookie_banner_title') }}</div>
        <div class="small opacity-75">{{ __('AdTracking::common.cookie_banner_text') }}</div>
      </div>
      <div class="ad-tracking-consent__actions">
        <button type="button" class="btn btn-sm btn-outline-light" data-ad-consent-decline>{{ __('AdTracking::common.reject') }}</button>
        <button type="button" class="btn btn-sm btn-primary" data-ad-consent-accept>{{ __('AdTracking::common.accept') }}</button>
      </div>
    </div>
  </div>
@endif
