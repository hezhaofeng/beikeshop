@extends('layout.master')
@section('body-class', 'page-categories')

@section('content')
  <x-shop-breadcrumb type="static" value="products.search" :is-full="true" />

  <div class="container-fluid">
    @include('shared.product_sort_bar')

    <div class="row g-3 g-lg-4">
      @if (count($items))
        @foreach ($items as $product)
          <div class="col-6 col-md-3 col-lg-20">@include('shared.product')</div>
        @endforeach
      @else
        <x-shop-no-data />
      @endif
    </div>

    {{ $products->links('shared/pagination/bootstrap-4') }}
  </div>

@endsection

@push('add-scripts')
  <script>
    $(function () {
      $('.order-select, .perpage-select').on('change', function () {
        const url = new URL(window.location.href);
        const order = $('.order-select').val();
        const perPage = $('.perpage-select').val();

        url.searchParams.delete('page');

        if (order) {
          const [sort, direction] = order.split('|');
          url.searchParams.set('sort', sort);
          url.searchParams.set('order', direction);
        } else {
          url.searchParams.delete('sort');
          url.searchParams.delete('order');
        }

        if (perPage) {
          url.searchParams.set('per_page', perPage);
        }

        window.location.href = url.toString();
      });
    });
  </script>
@endpush
