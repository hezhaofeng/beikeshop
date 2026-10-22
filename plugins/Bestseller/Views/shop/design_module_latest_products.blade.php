@addStyle(asset('vendor/swiper/swiper-bundle.min.css'))
@addScript(asset('vendor/swiper/swiper-bundle.min.js'))

<section class="module-item {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}">
  @include('design._partial._module_tool')
  <div class="module-info module-product mb-3 mb-md-5 swiper-style-plus">
    <div class="container position-relative">
      <div class="module-title">{{ $content['title'] }}</div>
      @if ($content['products'])
        <div class="swiper module-latest-product-{{ $module_id }} module-slideshow">
          <div class="swiper-wrapper">
            @foreach ($content['products'] as $product)
              <div class="swiper-slide">@include('shared.product')</div>
            @endforeach
          </div>
        </div>
        <div class="swiper-pagination rectangle module-latest-product-{{ $module_id }}-pagination"></div>
        <div class="swiper-button-prev product-latest-{{ $module_id }}-prev"></div>
        <div class="swiper-button-next product-latest-{{ $module_id }}-next"></div>
      @elseif ($design)
        <div class="row">
          @for ($s = 0; $s < 6; $s++)
            <div class="col-6 col-md-4 col-lg-2">
              <div class="product-wrap">
                <div class="image"><a href="javascript:void(0)"><img src="{{ asset('image/placeholder.png') }}" class="img-fluid"></a></div>
                <div class="product-name">请配置商品</div>
                <div class="product-price"><span class="price-new">66.66</span><span class="price-lod">99.99</span></div>
              </div>
            </div>
          @endfor
        </div>
      @endif
    </div>
  </div>

  <script>
    new Swiper('.module-latest-product-{{ $module_id }}', {
      watchSlidesProgress: true,
      breakpoints: {
        320: { slidesPerView: 2, slidesPerGroup: 2, spaceBetween: 10 },
        768: { slidesPerView: 3, slidesPerGroup: 3, spaceBetween: 20 },
        1200: { slidesPerView: 6, slidesPerGroup: 6, spaceBetween: 30 },
      },
      pagination: {
        el: '.module-latest-product-{{ $module_id }}-pagination',
        clickable: true,
      },
      navigation: {
        nextEl: '.product-latest-{{ $module_id }}-next',
        prevEl: '.product-latest-{{ $module_id }}-prev',
      },
    });
  </script>
</section>
