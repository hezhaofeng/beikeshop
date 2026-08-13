@extends('admin::layouts.master')

@section('title', __('admin/plugin.plugins_show'))

@section('content-area-class', 'w-max-1200')

@section('page-title-back', admin_route('plugins.index', http_build_query(request()->query())))

@section('content')
  <div class="card h-min-600">
    <div class="card-body">
      @if (session('success'))
        <x-admin-alert type="success" msg="{{ session('success') }}" class="mt-4"/>
      @endif

      <div class="mb-4">
        <h4 class="mb-2">{{ $plugin->getLocaleName() }}</h4>
        <p class="mb-0 text-secondary">{!! $plugin->getLocaleDescription() !!}</p>
      </div>

      @include('TieredShipping::admin.config_form')
    </div>
  </div>
@endsection
