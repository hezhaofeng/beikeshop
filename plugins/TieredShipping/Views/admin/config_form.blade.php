@php
  $setting = $plugin->getSetting() ?: [];
  $mode = old('calculation_mode', $setting['calculation_mode'] ?? 'amount_free');
  $tieredRules = old('tiered_rules', $setting['tiered_rules'] ?? []);

  if (is_string($tieredRules)) {
    $decodedRules = json_decode($tieredRules, true);
    $tieredRules = is_array($decodedRules) ? $decodedRules : [];
  }
  if (!is_array($tieredRules)) {
    $tieredRules = [];
  }
  if (!$tieredRules) {
    $tieredRules = [['amount' => 0, 'fee' => old('standard_fee', $setting['standard_fee'] ?? 0)]];
  }
@endphp

@push('header')
  <style>
    .tiered-shipping-settings .mode-panel[hidden] { display: none !important; }
    .tiered-shipping-settings .tiered-rule-remove { white-space: nowrap; }
  </style>
@endpush

<form class="needs-validation tiered-shipping-settings" novalidate action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST">
  @csrf
  {{ method_field('put') }}

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">运费规则</h6></div>
    <div class="card-body">
      <x-admin-form-select
        name="calculation_mode"
        title="计费方式"
        :options="[
          ['value' => 'amount_free', 'label' => '订单金额满额免运费'],
          ['value' => 'tiered_amount', 'label' => '按订单金额阶梯运费'],
          ['value' => 'quantity_free', 'label' => 'n件商品（包含n）以上免运费'],
          ['value' => 'quantity_additional_fee', 'label' => '固定运费加商品件数运费'],
        ]"
        value="{{ $mode }}" />

      <x-admin-form-input
        name="standard_fee"
        title="基础运费"
        type="number"
        step="0.01"
        groupRight="{{ current_currency_code() }}"
        :required="true"
        :error="$errors->first('standard_fee')"
        value="{{ old('standard_fee', $setting['standard_fee'] ?? '') }}">
        <div class="help-text font-size-12 lh-base">未达到免运费门槛，或未命中金额阶梯时收取此费用。</div>
      </x-admin-form-input>

      <div class="mode-panel" data-mode-panel="amount_free" @if($mode !== 'amount_free') hidden @endif>
        <x-admin-form-input
          name="amount_free_threshold"
          title="金额免运费门槛"
          type="number"
          step="0.01"
          groupRight="{{ current_currency_code() }}"
          :error="$errors->first('amount_free_threshold')"
          value="{{ old('amount_free_threshold', $setting['amount_free_threshold'] ?? '') }}">
          <div class="help-text font-size-12 lh-base">订单商品小计达到此金额后，运费为 0。</div>
        </x-admin-form-input>
      </div>

      <div class="mode-panel" data-mode-panel="quantity_free" @if($mode !== 'quantity_free') hidden @endif>
        <x-admin-form-input
          name="quantity_free_threshold"
          title="免运费商品件数（包含该件数）"
          type="number"
          step="1"
          groupRight="件"
          :error="$errors->first('quantity_free_threshold')"
          value="{{ old('quantity_free_threshold', $setting['quantity_free_threshold'] ?? '') }}">
          <div class="help-text font-size-12 lh-base">订单商品总件数达到 n 件（包含 n 件）后，运费为 0。</div>
        </x-admin-form-input>
      </div>

      <div class="mode-panel" data-mode-panel="quantity_additional_fee" @if($mode !== 'quantity_additional_fee') hidden @endif>
        <x-admin-form-input
          name="additional_item_fee"
          title="每增加一件商品运费"
          type="number"
          step="0.01"
          groupRight="{{ current_currency_code() }}"
          :error="$errors->first('additional_item_fee')"
          value="{{ old('additional_item_fee', $setting['additional_item_fee'] ?? '') }}">
          <div class="help-text font-size-12 lh-base">基础运费包含首件商品；第二件起，每增加一件商品加收此费用。</div>
        </x-admin-form-input>

        <x-admin-form-input
          name="quantity_free_threshold"
          title="满 n 件免运费（包含 n 件）"
          type="number"
          step="1"
          groupRight="件"
          :error="$errors->first('quantity_free_threshold')"
          value="{{ old('quantity_free_threshold', $setting['quantity_free_threshold'] ?? '') }}">
          <div class="help-text font-size-12 lh-base">商品总件数达到该数量后，固定运费和件数附加运费均为 0。</div>
        </x-admin-form-input>
      </div>

      <section class="mode-panel" data-mode-panel="tiered_amount" @if($mode !== 'tiered_amount') hidden @endif>
        <div class="mb-2">
          <label class="form-label mb-1">金额阶梯规则</label>
          <div class="form-text mt-0">系统自动按门槛从低到高匹配，订单金额达到某门槛后使用该行运费；未命中时收取基础运费。</div>
          @error('tiered_rules')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="table-responsive border rounded">
          <table class="table align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>订单金额满</th>
                <th>收取运费</th>
                <th class="text-end">操作</th>
              </tr>
            </thead>
            <tbody data-tiered-rules>
              @foreach($tieredRules as $index => $rule)
                <tr>
                  <td>
                    <input class="form-control" type="number" min="0" step="0.01" name="tiered_rules[{{ $index }}][amount]" value="{{ $rule['amount'] ?? '' }}" required>
                    @error("tiered_rules.$index.amount")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                  </td>
                  <td>
                    <input class="form-control" type="number" min="0" step="0.01" name="tiered_rules[{{ $index }}][fee]" value="{{ $rule['fee'] ?? '' }}" required>
                    @error("tiered_rules.$index.fee")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                  </td>
                  <td class="text-end"><button class="btn btn-outline-danger btn-sm tiered-rule-remove" type="button">删除</button></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <button class="btn btn-outline-secondary btn-sm mt-3" type="button" data-add-tiered-rule>添加阶梯</button>
      </section>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <x-admin-form-switch name="status" title="启用插件" value="{{ old('status', (int) ($setting['status'] ?? 0)) }}" />
    </div>
  </div>

  <x-admin::form.row title="">
    <button type="submit" class="btn btn-primary btn-lg">{{ __('common.submit') }}</button>
  </x-admin::form.row>
</form>

@push('footer')
  <script>
    (() => {
      const form = document.querySelector('.tiered-shipping-settings');
      if (!form) return;

      const mode = form.querySelector('[name="calculation_mode"]');
      const rows = form.querySelector('[data-tiered-rules]');
      const updateMode = () => form.querySelectorAll('[data-mode-panel]').forEach(panel => {
        const active = panel.dataset.modePanel === mode.value;
        panel.hidden = !active;
        panel.querySelectorAll('input, select, textarea').forEach(input => {
          input.disabled = !active;
        });
      });
      const nextIndex = () => Math.max(-1, ...Array.from(rows.querySelectorAll('input[name*="[amount]"]')).map(input => {
        const match = input.name.match(/tiered_rules\[(\d+)]/);
        return match ? Number(match[1]) : -1;
      })) + 1;
      const addRule = () => {
        const index = nextIndex();
        const row = document.createElement('tr');
        row.innerHTML = `
          <td><input class="form-control" type="number" min="0" step="0.01" name="tiered_rules[${index}][amount]" required></td>
          <td><input class="form-control" type="number" min="0" step="0.01" name="tiered_rules[${index}][fee]" required></td>
          <td class="text-end"><button class="btn btn-outline-danger btn-sm tiered-rule-remove" type="button">删除</button></td>`;
        rows.appendChild(row);
      };

      mode.addEventListener('change', updateMode);
      form.querySelector('[data-add-tiered-rule]').addEventListener('click', addRule);
      rows.addEventListener('click', event => {
        if (event.target.closest('.tiered-rule-remove')) event.target.closest('tr').remove();
      });
      updateMode();
    })();
  </script>
@endpush
