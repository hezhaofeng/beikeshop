@addStyle(asset('vendor/swiper/swiper-bundle.min.css'))
@addScript(asset('vendor/swiper/swiper-bundle.min.js'))
@include('HomepageCarouselLink::design._partial.slide_link_style', ['module_id' => $module_id])

<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}">
  <div class="module-info   {{ $content['module_size'] ?? 'w-100' }}">
    <div class="swiper module-swiper-{{ $module_id }} module-slideshow">
      <div class="swiper-wrapper">
        @foreach($content['images'] as $image)
          @php
            $link = $image['link'] ?? [];
            $href = $link['link'] ?? '';
            $hasLink = is_string($href) && trim($href) !== '';
            $target = !empty($link['new_window']) ? '_blank' : '_self';
          @endphp
          <div class="swiper-slide">
            @if ($hasLink)
              <a
                href="{{ $href }}"
                target="{{ $target }}"
                @if ($target === '_blank') rel="noopener noreferrer" @endif
                class="homepage-carousel-slide-link d-flex justify-content-center">
            @else
              <div class="homepage-carousel-slide-link homepage-carousel-slide-link--disabled d-flex justify-content-center">
            @endif
              @if (($image['type'] ?? 'image') == 'video')
                <video src="{{ $image['image'] }}" class="img-fluid w-100" controls loop autoplay muted></video>
              @else
                <img src="{{ $image['image'] }}" fetchpriority="high" class="img-fluid seo-img" alt="{{ $image['image_alt'] ?? '' }}">
              @endif
            @if ($hasLink)
              </a>
            @else
              </div>
            @endif
          </div>
        @endforeach
      </div>
      <div class="swiper-pagination slideshow-pagination-{{ $module_id }}"></div>
      <div class="swiper-button-prev slideshow-btnprev-{{ $module_id }}"></div>
      <div class="swiper-button-next slideshow-btnnext-{{ $module_id }}"></div>
    </div>
  </div>

  <script>
    function homepageCarouselLinkSwiper_{{ $module_id }}() {
      new Swiper ('.module-swiper-{{ $module_id }}', {
        loop: '{{ count($content['images']) > 1 ? true : false }}',
        autoplay: true,
        pauseOnMouseEnter: true,
        clickable: true,

        pagination: {
          el: '.slideshow-pagination-{{ $module_id }}',
          clickable: true
        },

        navigation: {
          nextEl: '.slideshow-btnnext-{{ $module_id }}',
          prevEl: '.slideshow-btnprev-{{ $module_id }}',
        },
      })
    }

  @if ($design)
    bk.loadStyle('{{ asset('vendor/swiper/swiper-bundle.min.css') }}');
    bk.loadScript('{{ asset('vendor/swiper/swiper-bundle.min.js') }}', () => {
      homepageCarouselLinkSwiper_{{ $module_id }}();
    })
  @else
    homepageCarouselLinkSwiper_{{ $module_id }}();
  @endif
  </script>
</section>
