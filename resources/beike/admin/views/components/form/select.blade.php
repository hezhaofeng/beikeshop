@php
  // 兼容历史换行/逗号配置与 SettingRepo 保存的 JSON 数组。
  $selectedValues = [];
  if ($multiple) {
    if (is_array($value)) {
      $selectedValues = $value;
    } elseif (is_string($value)) {
      $decoded = json_decode($value, true);
      $selectedValues = is_array($decoded)
        ? $decoded
        : preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    }

    $selectedValues = array_values(array_unique(array_filter(array_map(
      fn ($item) => strtoupper(trim((string) $item)),
      $selectedValues
    ))));
  }

  $selectName = $multiple ? $name . '[]' : $name;
  $optionItems = [];
  if (isTwoDimensionalArray($options)) {
    foreach ($options as $option) {
      $optionItems[] = [
        'value' => (string) $option[$key],
        'label' => (string) $option[$label],
      ];
    }
  } else {
    foreach ($options as $option) {
      $optionItems[] = ['value' => (string) $option, 'label' => (string) $option];
    }
  }

  $optionLabels = [];
  foreach ($optionItems as $option) {
    $optionLabels[strtoupper($option['value'])] = $option['label'];
  }
@endphp

@if ($multiple)
  @if ($format)
    <x-admin::form.row :title="$title">
  @endif

  <div class="form-multi-select" data-input-name="{{ $selectName }}">
    {{-- 无选择时仍提交空数组，覆盖已经保存的旧列表。 --}}
    <input type="hidden" class="form-multi-select-empty-input" name="{{ $selectName }}" value="" {{ $selectedValues !== [] ? 'disabled' : '' }}>
    <div class="form-multi-select-values" aria-live="polite">
      @foreach ($selectedValues as $selectedValue)
        <span class="form-multi-select-item" data-value="{{ $selectedValue }}">
          <span>{{ $optionLabels[$selectedValue] ?? $selectedValue }}</span>
          <input type="hidden" name="{{ $selectName }}" value="{{ $selectedValue }}">
          <button type="button" class="btn btn-link form-multi-select-remove" data-action="remove" title="删除 {{ $optionLabels[$selectedValue] ?? $selectedValue }}" aria-label="删除 {{ $optionLabels[$selectedValue] ?? $selectedValue }}">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </span>
      @endforeach
      <span class="form-multi-select-empty {{ $selectedValues !== [] ? 'd-none' : '' }}">暂无选择</span>
    </div>
    <select class="{{ $class }} w-100 form-multi-select-add" data-action="add" onclick="event.stopPropagation();">
      <option value="">添加选项</option>
      @foreach ($optionItems as $option)
        @php($isSelected = in_array(strtoupper($option['value']), $selectedValues, true))
        <option value="{{ $option['value'] }}" {{ $isSelected ? 'disabled' : '' }}>{{ $option['label'] }}</option>
      @endforeach
    </select>
  </div>

  @if ($format)
      {{ $slot }}
    </x-admin::form.row>
  @endif

  @once
    @push('header')
      <style>
        .form-multi-select-values { display: flex; flex-wrap: wrap; gap: 6px; min-height: 38px; margin-bottom: 8px; }
        .form-multi-select-item { display: inline-flex; align-items: center; gap: 4px; max-width: 100%; padding: 5px 6px 5px 9px; border: 1px solid var(--bs-border-color); border-radius: 4px; background: var(--bs-tertiary-bg); font-size: 13px; }
        .form-multi-select-item > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .form-multi-select-remove { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; padding: 0; color: var(--bs-secondary-color); text-decoration: none; }
        .form-multi-select-remove:hover { color: var(--bs-danger); }
        .form-multi-select-empty { align-self: center; color: var(--bs-secondary-color); font-size: 13px; }
      </style>
    @endpush

    @push('footer')
      <script>
        $(function () {
          // 从候选下拉中选择后立即生成隐藏值和可删除标签。
          $(document).on('change', '.form-multi-select-add', function () {
            const $select = $(this);
            const value = $select.val();
            if (!value) {
              return;
            }

            const $picker = $select.closest('.form-multi-select');
            const $selectedOption = $select.find('option:selected');
            const label = $selectedOption.text();
            const name = $picker.data('input-name');
            const alreadyAdded = $picker.find('.form-multi-select-item').filter(function () {
              return $(this).attr('data-value') === value;
            }).length > 0;

            if (!alreadyAdded) {
              const $item = $('<span>', { class: 'form-multi-select-item', 'data-value': value });
              const $remove = $('<button>', {
                type: 'button',
                class: 'btn btn-link form-multi-select-remove',
                'data-action': 'remove',
                title: '删除 ' + label,
                'aria-label': '删除 ' + label,
              }).append($('<i>', { class: 'bi bi-x-lg', 'aria-hidden': 'true' }));

              $item.append($('<span>').text(label));
              $item.append($('<input>', { type: 'hidden', name: name, value: value }));
              $item.append($remove);
              $picker.find('.form-multi-select-values').append($item);
              $picker.find('.form-multi-select-empty').addClass('d-none');
              $picker.find('.form-multi-select-empty-input').prop('disabled', true);
            }

            $selectedOption.prop('disabled', true);
            $select.val('');
          });

          // 点击标签的 X 图标后恢复对应候选项，方便再次添加。
          $(document).on('click', '.form-multi-select-remove', function () {
            const $item = $(this).closest('.form-multi-select-item');
            const $picker = $item.closest('.form-multi-select');
            const value = $item.attr('data-value');

            $item.remove();
            $picker.find('.form-multi-select-add option').filter(function () {
              return this.value === value;
            }).prop('disabled', false);

            if ($picker.find('.form-multi-select-item').length === 0) {
              $picker.find('.form-multi-select-empty').removeClass('d-none');
              $picker.find('.form-multi-select-empty-input').prop('disabled', false);
            }
          });
        });
      </script>
    @endpush
  @endonce
@elseif($format)
  <x-admin::form.row :title="$title">
    <select class="{{ $class }}" name="{{ $name }}" {{ $attributes }} onclick="event.stopPropagation();">
      @foreach ($optionItems as $option)
        <option value="{{ $option['value'] }}" {{ $option['value'] == $value ? 'selected': '' }}>{{ $option['label'] }}</option>
      @endforeach
    </select>
    {{ $slot }}
  </x-admin::form.row>
@else
  <select class="{{ $class }}" name="{{ $name }}" {{ $attributes }} onclick="event.stopPropagation();">
    @foreach ($optionItems as $option)
      <option value="{{ $option['value'] }}" {{ $option['value'] == $value ? 'selected': '' }}>{{ $option['label'] }}</option>
    @endforeach
  </select>
@endif
