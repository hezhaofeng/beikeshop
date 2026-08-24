@addStyle(asset('vendor/swiper/swiper-bundle.min.css'))
@addScript(asset('vendor/swiper/swiper-bundle.min.js'))
@include('HomepageCarouselLink::design._partial.slide_link_style', ['module_id' => $module_id])

<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}">
  <div class="module-info">
    <div class="{{ $content['module_size'] ?? 'w-100' }}">
      @if ($content['scroll_text']['text'])
        <div class="module-swiper-img-scroll-text-2" style="
          background-color: {{ $content['scroll_text']['bg'] }};
          color: {{ $content['scroll_text']['color'] }};
          font-size: {{ $content['scroll_text']['font_size'] }}px;
          padding: {{ $content['scroll_text']['padding'] }}px 0;
          ">
          <div class="scroll-info">
            <span class="scroll-text">{{ $content['scroll_text']['text'] }}</span>
          </div>
        </div>
      @endif

      <div class="swiper module-swiper-img-text-{{ $module_id }} module-img-text-slideshow-2 w-{{ $content['module_size'] ?? 'w-100' }}">
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
                  class="homepage-carousel-slide-link">
              @else
                <div class="homepage-carousel-slide-link homepage-carousel-slide-link--disabled">
              @endif
                <div class="image-wrap">
                  <img src="{{ $image['image'] }}" fetchpriority="high" class="img-fluid" alt="{{ $image['image_alt'] ?? '' }}">
                </div>
                <div class="image-text-wrap">
                  <div class="container-fluid content-wrap {{ $image['text_position'] }}">
                    <div class="text-wrap" data-swiper-parallax-y="-100" data-swiper-parallax-duration="1000" data-swiper-parallax-opacity="0.5">
                      @if ($image['sub_title'])
                        <div class="sub-title">{{ $image['sub_title'] }}</div>
                      @endif
                      @if ($image['title'])
                        <h2 class="title">{{ $image['title'] }}</h2>
                      @endif
                      @if ($image['description'])
                        <p class="description">{{ $image['description'] }}</p>
                      @endif
                    </div>
                  </div>
                </div>
              @if ($hasLink)
                </a>
              @else
                </div>
              @endif
            </div>
          @endforeach
        </div>
        <div class="swiper-pagination slideshow-pagination-{{ $module_id }}"></div>
      </div>
    </div>
  </div>

  <script>
    var homepageCarouselImgTextSwiper2_{{ $module_id }} = new Swiper ('.module-swiper-img-text-{{ $module_id }}', {
      loop: true,
      parallax: true,
      pauseOnMouseEnter: true,
      clickable: true,
      effect: 'fade',

      pagination: {
        el: '.slideshow-pagination-{{ $module_id }}',
        clickable: true
      },

      autoplay: {
        delay: 3000,
        disableOnInteraction: false
      },
    })

    $('.module-img-text-slideshow-2').hover(function() {
      homepageCarouselImgTextSwiper2_{{ $module_id }}.autoplay.pause();
    }, function() {
      homepageCarouselImgTextSwiper2_{{ $module_id }}.autoplay.resume();
    });

    $(function () {
      homepageCarouselScrollText2_{{ $module_id }}()

      function homepageCarouselScrollText2_{{ $module_id }}() {
        var $module = $('.module-swiper-img-text-{{ $module_id }}').prev('.module-swiper-img-scroll-text-2');
        if (!$module.length) {
          return;
        }

        var $scrollText = $module.find('.scroll-text');
        var scrollText = $scrollText.text();
        var scrollTextWidth = $scrollText.width();
        var scrollInfoWidth = $module.width();
        var speed = 50;
        var duration = scrollTextWidth / speed;
        var scrollCount = Math.ceil(scrollInfoWidth / scrollTextWidth) + 1;
        var scrollTextHtml = '';

        for (var i = 0; i < scrollCount; i++) {
          scrollTextHtml +=
            '<span class="scroll-text" style="animation-duration: ' + duration + 's;">' +
            scrollText +
            '</span>';
        }

        $module.find('.scroll-info').html(scrollTextHtml);
      }
    })
  </script>
</section>
