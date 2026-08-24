@extends('layout.master')

@section('body-class', 'page-order-lookup')
@section('title', __('WesternUnion::common.order_lookup_title'))

@section('content')
  <div class="container">
    <div class="row my-5 justify-content-center">
      <div class="col-12 col-md-8 col-lg-6">
        <div class="card">
          <div class="card-body p-4">
            <h1 class="h4 mb-2">{{ __('WesternUnion::common.order_lookup_title') }}</h1>
            <p class="text-secondary mb-4">{{ __('WesternUnion::common.order_lookup_description') }}</p>

            <form action="{{ shop_route('western-union.orders.find') }}" method="POST">
              @csrf
              <div class="mb-3">
                <label class="form-label" for="order-lookup-number">{{ __('WesternUnion::common.order_number') }}</label>
                <input id="order-lookup-number" class="form-control @error('number') is-invalid @enderror" type="text" name="number" value="{{ old('number') }}" maxlength="64" required autocomplete="off">
                @error('number')
                  <div class="invalid-feedback">{{ $message }}</div>
                @enderror
              </div>
              <div class="mb-4">
                <label class="form-label" for="order-lookup-email">{{ __('WesternUnion::common.order_email') }}</label>
                <input id="order-lookup-email" class="form-control @error('email') is-invalid @enderror" type="email" name="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email">
                @error('email')
                  <div class="invalid-feedback">{{ $message }}</div>
                @enderror
              </div>
              <button class="btn btn-primary" type="submit">{{ __('WesternUnion::common.find_order') }}</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
@endsection
