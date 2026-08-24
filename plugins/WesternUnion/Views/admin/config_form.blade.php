@php
  $setting = $plugin->getSetting() ?: [];
  $status = old('status', (int) ($setting['status'] ?? 0));
  $receiptRequired = old('receipt_required', (int) ($setting['receipt_required'] ?? 1));
  $quantityRestrictionEnabled = old('quantity_restriction_enabled', (int) ($setting['quantity_restriction_enabled'] ?? 0));
@endphp

<form class="needs-validation" novalidate action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST">
  @csrf
  {{ method_field('put') }}

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">{{ __('WesternUnion::common.transfer_settings') }}</h6></div>
    <div class="card-body">
      <x-admin-form-textarea
        name="transfer_instruction"
        title="{{ __('WesternUnion::common.transfer_instruction') }}"
        :required="true"
        value="{{ old('transfer_instruction', $setting['transfer_instruction'] ?? '') }}">
        <div class="form-text text-secondary">{{ __('WesternUnion::common.transfer_instruction_help') }}</div>
      </x-admin-form-textarea>
      <x-admin-form-input
        name="discount_percentage"
        title="{{ __('WesternUnion::common.discount_percentage') }}"
        type="number"
        min="0"
        max="100"
        step="0.01"
        groupRight="%"
        :error="$errors->first('discount_percentage')"
        value="{{ old('discount_percentage', $setting['discount_percentage'] ?? 8) }}">
        <div class="form-text text-secondary">{{ __('WesternUnion::common.discount_percentage_help') }}</div>
      </x-admin-form-input>
      <x-admin-form-select
        name="receipt_required"
        title="{{ __('WesternUnion::common.receipt_required') }}"
        :options="[
          ['value' => '1', 'label' => __('WesternUnion::common.yes')],
          ['value' => '0', 'label' => __('WesternUnion::common.no')],
        ]"
        value="{{ $receiptRequired }}" />

      <x-admin-form-select
        name="quantity_restriction_enabled"
        title="按商品件数限制支付方式"
        :options="[
          ['value' => '1', 'label' => '是'],
          ['value' => '0', 'label' => '否'],
        ]"
        value="{{ $quantityRestrictionEnabled }}" />

      <x-admin-form-input
        name="quantity_restriction_threshold"
        title="Western Union件数门槛"
        type="number"
        min="1"
        step="1"
        groupRight="件"
        :error="$errors->first('quantity_restriction_threshold')"
        value="{{ old('quantity_restriction_threshold', $setting['quantity_restriction_threshold'] ?? '') }}">
        <div class="form-text text-secondary">购物车商品总件数超过此数量后，结账时只允许使用Western Union。</div>
      </x-admin-form-input>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <x-admin-form-switch name="status" :title="__('WesternUnion::common.status')" value="{{ $status }}" />
    </div>
  </div>

  <x-admin::form.row title="">
    <button type="submit" class="btn btn-primary btn-lg">{{ __('common.submit') }}</button>
  </x-admin::form.row>
</form>
