@extends('admin::layouts.master')

@section('title', $plugin->getLocaleName())
@section('content-area-class', 'w-max-1200')
@section('page-title-back', admin_route('plugins.index', http_build_query(request()->query())))
@section('head-form-btns', true)

@section('content')
@php
  $setting = $plugin->getSetting() ?: [];
  $status = old('status', (int) ($setting['status'] ?? 0));
  $homepageCarousel = $homepageCarousel ?? ['modules' => [], 'categories' => [], 'link_types' => []];
  $modules = $homepageCarousel['modules'] ?? [];
  $categories = $homepageCarousel['categories'] ?? [];
  $linkTypes = $homepageCarousel['link_types'] ?? [];
@endphp

<form class="needs-validation" novalidate action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST">
  @csrf
  {{ method_field('put') }}

  <div class="card mb-4">
    <div class="card-body">
      <div class="mb-2 fw-semibold">首页轮播跳转配置</div>
      <div class="text-secondary small">这里只配置跳转目标和打开方式，图片内容仍然在首页装修中维护。</div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">轮播链接</h6></div>
    <div class="card-body">
      @forelse ($modules as $module)
        <div class="border rounded p-3 mb-4" data-carousel-module>
          <div class="fw-semibold mb-3">{{ $module['title'] ?? $module['code'] }}</div>

          @foreach (($module['images'] ?? []) as $image)
            @php
              $moduleId = $module['module_id'];
              $index = $image['index'];
              $slideType = old("slides.$moduleId.$index.type", $image['link']['type'] ?? 'custom');
              $slideValue = old("slides.$moduleId.$index.value", $image['link']['value'] ?? '');
              $slideNewWindow = (bool) old("slides.$moduleId.$index.new_window", ! empty($image['link']['new_window']));
            @endphp
            <div class="row g-3 align-items-start mb-3" data-carousel-row>
              <div class="col-md-2 col-4">
                <img src="{{ $image['image'] }}" alt="" class="img-fluid rounded border">
              </div>
              <div class="col-md-10 col-8">
                <div class="fw-semibold mb-2">{{ $image['title'] }}</div>
                <div class="row g-2">
                  <div class="col-md-3">
                    <label class="form-label">链接类型</label>
                    <select class="form-select" name="slides[{{ $moduleId }}][{{ $index }}][type]" data-role="carousel-link-type">
                      @foreach ($linkTypes as $option)
                        <option value="{{ $option['value'] }}" @selected($slideType === $option['value'])>{{ $option['label'] }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="col-md-5" data-role="carousel-value-category">
                    <label class="form-label">分类</label>
                    <select class="form-select" name="slides[{{ $moduleId }}][{{ $index }}][value]" @disabled($slideType !== 'category')>
                      <option value="">请选择分类</option>
                      @foreach ($categories as $category)
                        <option value="{{ $category['value'] }}" @selected((string) $slideValue === (string) $category['value'])>{{ $category['label'] }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="col-md-5" data-role="carousel-value-custom">
                    <label class="form-label">自定义 URL</label>
                    <input type="text" class="form-control" name="slides[{{ $moduleId }}][{{ $index }}][value]" value="{{ $slideValue }}" placeholder="https://example.com" @disabled($slideType !== 'custom')>
                  </div>
                  <div class="col-md-2 d-flex align-items-end">
                    <input type="hidden" name="slides[{{ $moduleId }}][{{ $index }}][new_window]" value="0">
                    <label class="form-check mt-2">
                      <input class="form-check-input" type="checkbox" name="slides[{{ $moduleId }}][{{ $index }}][new_window]" value="1" @checked($slideNewWindow)>
                      <span class="form-check-label">新窗口</span>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          @endforeach
        </div>
      @empty
        <x-admin-alert type="info" msg="当前首页没有可配置的轮播模块，请先在装修中放入幻灯片模块。"/>
      @endforelse
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <x-admin-form-switch name="status" :title="__('common.whether_open')" value="{{ $status }}" />
    </div>
  </div>

  <x-admin::form.row title="">
    <button type="submit" class="btn btn-primary btn-lg">{{ __('common.submit') }}</button>
  </x-admin::form.row>
</form>

@push('footer')
  <script>
    $(function () {
      function toggleRow($row) {
        const type = $row.find('[data-role="carousel-link-type"]').val();
        $row.find('[data-role="carousel-value-category"]').toggleClass('d-none', type !== 'category').find('select').prop('disabled', type !== 'category');
        $row.find('[data-role="carousel-value-custom"]').toggleClass('d-none', type !== 'custom').find('input').prop('disabled', type !== 'custom');
      }

      $('[data-carousel-row]').each(function () {
        toggleRow($(this));
      });

      $(document).on('change', '[data-role="carousel-link-type"]', function () {
        toggleRow($(this).closest('[data-carousel-row]'));
      });
    });
  </script>
@endpush

@endsection
