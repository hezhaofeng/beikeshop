<div class="top-notice" data-mode="{{ $notice['overflow'] }}" tabindex="0"
     title="{{ $notice['text'] }}"
     style="color: {{ $notice['color'] }}; background: {{ $notice['background'] }}; font-size: {{ $notice['font_size'] }}px;">
  @if ($notice['link'])
    <a class="top-notice__text" href="{{ $notice['link'] }}">{{ $notice['text'] }}</a>
  @else
    <span class="top-notice__text">{{ $notice['text'] }}</span>
  @endif
</div>
@once
  <style>
    header .top-wrap > .container-fluid:has(> .top-notice) { gap: 12px; min-width: 0; }
    header .top-wrap:has(.top-notice) .left,
    header .top-wrap:has(.top-notice) .right { flex-shrink: 0; }
    header .top-wrap .top-notice {
      flex: 1 1 0%; min-width: 0; overflow-x: auto; overflow-y: hidden;
      white-space: nowrap; text-align: center; line-height: 28px; border-radius: 3px;
      scrollbar-width: none;
    }
    .top-notice::-webkit-scrollbar { display: none; }
    .top-notice__text { display: inline-block; white-space: nowrap; color: inherit; text-decoration: none; }
    .top-notice__text:hover { color: inherit; text-decoration: underline; }
    header .top-wrap .top-notice[data-mode="ellipsis"] { overflow: hidden; text-overflow: ellipsis; }
    .top-notice[data-mode="ellipsis"] .top-notice__text { display: inline; }
    .top-notice:focus-visible { outline: 2px solid currentColor; outline-offset: -2px; }
  </style>
  <script>
    (() => {
      const init = () => {
        document.querySelectorAll('.top-notice[data-mode="scroll"]').forEach((notice) => {
          if (notice.dataset.initialized) return;
          notice.dataset.initialized = '1';
          const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
          let paused = false;
          let direction = 1;
          let position = 0;
          let previous = 0;
          let waitUntil = performance.now() + 1800;
          const pause = () => { paused = true; };
          const resume = () => {
            paused = notice.matches(':hover') || notice.contains(document.activeElement);
            position = notice.scrollLeft;
            waitUntil = performance.now() + 1800;
          };
          notice.addEventListener('mouseenter', pause);
          notice.addEventListener('mouseleave', resume);
          notice.addEventListener('focusin', pause);
          notice.addEventListener('focusout', () => setTimeout(resume, 0));
          notice.addEventListener('touchstart', pause, { passive: true });
          notice.addEventListener('touchend', resume, { passive: true });
          notice.addEventListener('touchcancel', resume, { passive: true });
          const tick = (time) => {
            if (!notice.isConnected) return;
            const elapsed = previous ? Math.min(time - previous, 50) : 0;
            previous = time;
            const limit = notice.scrollWidth - notice.clientWidth;
            if (!paused && !reducedMotion.matches && !document.hidden && limit > 1 && time >= waitUntil) {
              position = Math.max(0, Math.min(limit, position + direction * elapsed * 0.035));
              notice.scrollLeft = position;
              if (position >= limit || position <= 0) {
                direction *= -1;
                waitUntil = time + 1800;
              }
            }
            requestAnimationFrame(tick);
          };
          requestAnimationFrame(tick);
        });
      };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
      else init();
    })();
  </script>
@endonce
