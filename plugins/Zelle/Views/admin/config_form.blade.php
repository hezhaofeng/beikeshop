@php
  $setting = $plugin->getSetting() ?: [];
  $status = old('status', (int) ($setting['status'] ?? 0));
@endphp

<form class="needs-validation" novalidate action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST">
  @csrf
  {{ method_field('put') }}

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">{{ __('Zelle::common.recipient_settings') }}</h6></div>
    <div class="card-body">
      <x-admin-form-select
        name="recipient_type"
        title="{{ __('Zelle::common.recipient_type') }}"
        :options="[
          ['value' => 'email', 'label' => __('Zelle::common.recipient_email')],
          ['value' => 'mobile', 'label' => __('Zelle::common.recipient_mobile')],
        ]"
        value="{{ old('recipient_type', $setting['recipient_type'] ?? 'email') }}" />
      <x-admin-form-input
        name="recipient_identifier"
        title="{{ __('Zelle::common.recipient_identifier') }}"
        :required="true"
        value="{{ old('recipient_identifier', $setting['recipient_identifier'] ?? '') }}" />
      <x-admin-form-input
        name="recipient_name"
        title="{{ __('Zelle::common.recipient_name') }}"
        :required="true"
        value="{{ old('recipient_name', $setting['recipient_name'] ?? '') }}" />
      <x-admin-form-input
        name="payment_note"
        title="{{ __('Zelle::common.payment_note') }}"
        value="{{ old('payment_note', $setting['payment_note'] ?? '') }}" />
      <div class="form-text text-secondary">{{ __('Zelle::common.usd_only_hint') }}</div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <x-admin-form-switch name="status" :title="__('Zelle::common.status')" value="{{ $status }}" />
    </div>
  </div>

  <x-admin::form.row title="">
    <button type="submit" class="btn btn-primary btn-lg">{{ __('common.submit') }}</button>
  </x-admin::form.row>
</form>
