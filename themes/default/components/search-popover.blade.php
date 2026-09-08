<div class="offcanvas" tabindex="-1" id="offcanvas-search-top" aria-labelledby="offcanvasTopLabel">
  <div class="container">
    <div class="offcanvas-header">
      <div class="search-input-wrap input-group mb-0">
        <input type="text" class="form-control search-popover-input input-group-lg fs-4" focus placeholder="{{ __('common.input') }}"
               value="{{ request('keyword') }}" data-lang="{{ locale() === system_setting('base.locale') ? '' : session()->get('locale') }}">
        <button type="button" class="input-group-text search-popover-submit" aria-label="{{ __('shop/products.search') }}">
          <i class="bi bi-search"></i>
        </button>
      </div>
      <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="hot-search-wrap mb-4">
      <h5>{{ __('shop/products.hot_search') }}</h5>
      @if ($hot_keywords)
        <ul class="hot-search-list">
          @foreach ($hot_keywords as $keyword)
            <li><a class="rounded-pill btn btn-light border border-1" href="{{ shop_route('products.search', ['keyword' => $keyword]) }}">{{ $keyword }}</a></li>
          @endforeach
        </ul>
      @endif
    </div>

    <div class="search-pop-products-wrap d-none">
      <div class="spinner-border" role="status"></div>
      <h5 class="hot-products-title">{{ __('shop/products.hot_products') }}</h5>

      <div class="sp-products hot-products-list">
        <div class="row g-3 g-lg-4"></div>
      </div>
      <div class="sp-products search-products-list d-none">
        <div class="row g-3 g-lg-4"></div>
      </div>
    </div>
  </div>
</div>

@push('add-scripts')
  <script>
    if ($(window).width() < 768) {
      $('#offcanvas-search-top').addClass('offcanvas-start');
    } else {
      $('#offcanvas-search-top').addClass('offcanvas-top');
    }

    $(function () {
      const $input = $('.search-popover-input');
      const searchUrl = @json(shop_route('products.search'));
      const $wrap = $('.search-pop-products-wrap');
      const $hotList = $wrap.find('.hot-products-list');
      const $searchList = $wrap.find('.search-products-list');
      const $title = $('.hot-products-title');
      const isMobile = @json(is_mobile());
      let hotLoaded = false;

      // 打开弹窗时懒加载趋势商品
      $('#offcanvas-search-top').on('shown.bs.offcanvas', function () {
        if (!hotLoaded) {
          loadHotProducts();
        }
      });

      function submitSearch() {
        const keyword = $input.val().trim();
        if (!keyword) {
          return;
        }

        window.location.href = searchUrl + (searchUrl.includes('?') ? '&' : '?') + 'keyword=' + encodeURIComponent(keyword);
      }

      $('.search-popover-submit').on('click', submitSearch);

      // 回车提交。搜索 URL 由 shop_route 在服务端生成，非首页也不会跳到相对路径；
      // 下方「查看全部」按钮通过 trigger keydown 13 复用同一入口。
      $input.on('keydown', function (e) {
        // 输入法组合状态下的回车用于确认候选词，此时不应跳转搜索页。
        if (e.keyCode === 13 && !e.originalEvent?.isComposing) {
          e.preventDefault();
          submitSearch();
        }
      });

      function loadHotProducts() {
        $wrap.removeClass('d-none').addClass('loading');
        $http.get('{{ shop_route('products.hot-products') }}', {}, { hload: true }).then(res => {
          $hotList.find('.row').html(res);
          hotLoaded = true;
          if (isMobile) {
            $('.hot-products-list .product-wrap').addClass('list');
          }
        }).finally(() => {
          $wrap.removeClass('loading');
        });
      }

      $input.on('input', bk.debounce(function () {
        const keyword = $input.val().trim();

        $wrap.addClass('loading');

        if (keyword.length > 0) {
          topSearchGetData(keyword)
        } else {
          $wrap.removeClass('loading');
          $hotList.removeClass('d-none');
          $searchList.addClass('d-none').find('.row').empty();
          $title.text('{{ __("shop/products.hot_products") }}').removeClass('d-none');
        }
      }, 300));

      $(document).on('click', '.search-pop-products-show-all .btn', function () {
        $(".search-popover-input").trigger({
          type: "keydown",
          keyCode: 13
        });
      });

      $('a[href="#offcanvas-search-top"]').on('click', function () {
        const keyword = $input.val().trim();
        if (keyword) {
          topSearchGetData(keyword)
        }
      })

      function topSearchGetData(keyword) {
        const searchListHeight = $searchList.height();
        $http.get('{{ shop_route('products.autocomplete') }}', { name: keyword, html: true, limit: 5 }, { hload: true }).then(res => {
          $hotList.addClass('d-none');
          if (searchListHeight) {
            $searchList.height(searchListHeight);
          }
          $searchList.removeClass('d-none').find('.row').html(res);
          if (isMobile) {
            $('.search-products-list .product-wrap').addClass('list');
          }

          $title.text(res ? '{{ __("shop/products.search_result") }}' : '{{ __("shop/products.hot_products") }}');
          $title.toggleClass('d-none', !res);
        }).finally(() => {
          $wrap.removeClass('loading');
        });
      }
    })
  </script>
@endpush
