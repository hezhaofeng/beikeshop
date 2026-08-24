@php
  $setting = $plugin->getSetting() ?: [];
  $status = old('status', (int) ($setting['status'] ?? 0));
@endphp

<form class="needs-validation" novalidate action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST">
  @csrf
  {{ method_field('put') }}

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">{{ __('InsuranceFee::common.settings') }}</h6></div>
    <div class="card-body">
      <x-admin-form-input
        name="insurance_fee_usd"
        title="{{ __('InsuranceFee::common.insurance_fee_usd') }}"
        type="number"
        min="0.01"
        max="99999999.99"
        step="0.01"
        groupRight="USD"
        :required="true"
        :error="$errors->first('insurance_fee_usd')"
        value="{{ old('insurance_fee_usd', $setting['insurance_fee_usd'] ?? '') }}">
        <div class="help-text font-size-12 lh-base">{{ __('InsuranceFee::common.insurance_fee_usd_help') }}</div>
      </x-admin-form-input>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <x-admin-form-switch name="status" :title="__('InsuranceFee::common.status')" value="{{ $status }}" />
    </div>
  </div>

  <x-admin::form.row title="">
    <button type="submit" class="btn btn-primary btn-lg">{{ __('common.submit') }}</button>
  </x-admin::form.row>
</form>
