<div class="product-tool d-flex justify-content-end align-items-center mb-lg-4 mb-2">
  <div class="d-flex align-items-center right-per-page">
    <div class="text-nowrap text-secondary">
      {{ __('common.showing_page', ['per_page' => $products->perPage(), 'total' => $products->total()]) }}
    </div>

    <div class="d-flex align-items-center">
      <select class="form-select perpage-select ms-3">
        @foreach ($per_pages as $val)
          <option value="{{ $val }}" {{ (int) $products->perPage() === (int) $val ? 'selected' : '' }}>{{ $val }}</option>
        @endforeach
      </select>

      <select class="form-select order-select ms-2">
        <option value="">{{ __('common.default') }}</option>
        <option value="products.sales|asc" {{ request('sort') == 'products.sales' && request('order') == 'asc' ? 'selected' : '' }}>{{ __('common.sales') }} ({{ __('common.low') . '-' . __('common.high')}})</option>
        <option value="products.sales|desc" {{ request('sort') == 'products.sales' && request('order') == 'desc' ? 'selected' : '' }}>{{ __('common.sales') }} ({{ __('common.high') . '-' . __('common.low')}})</option>
        <option value="pd.name|asc" {{ request('sort') == 'pd.name' && request('order') == 'asc' ? 'selected' : '' }}>{{ __('common.name') }} (A - Z)</option>
        <option value="pd.name|desc" {{ request('sort') == 'pd.name' && request('order') == 'desc' ? 'selected' : '' }}>{{ __('common.name') }} (Z - A)</option>
        <option value="product_skus.price|asc" {{ request('sort') == 'product_skus.price' && request('order') == 'asc' ? 'selected' : '' }}>{{ __('product.price') }} ({{ __('common.low') . '-' . __('common.high')}})</option>
        <option value="product_skus.price|desc" {{ request('sort') == 'product_skus.price' && request('order') == 'desc' ? 'selected' : '' }}>{{ __('product.price') }} ({{ __('common.high') . '-' . __('common.low')}})</option>
      </select>
    </div>
  </div>
</div>
