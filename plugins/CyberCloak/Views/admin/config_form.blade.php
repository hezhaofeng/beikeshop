@php
  // 按访问路径组织字段，避免将高频准入规则与低频部署项混在一起。
  $columns = collect($plugin->getColumns())->keyBy('name');
  $sections = [
    [
      'id' => 'real-access',
      'title' => '真实站准入',
      'summary' => '访问 key、签名 Cookie 与静态 IP 访问规则。',
      'fields' => [
        'status', 'access_keys', 'ip_whitelist', 'ip_blacklist',
      ],
    ],
    [
      'id' => 'risk-detection',
      'title' => '风险识别',
      'summary' => '四层漏斗、MaxMind 信号与风险评分。',
      'fields' => [
        'traffic_funnel_enabled', 'geoip_enabled', 'traffic_allowed_countries', 'traffic_blocked_countries',
        'traffic_allowed_languages', 'traffic_user_agent_blacklist', 'ip_reputation', 'traffic_blocked_asns',
        'traffic_datacenter_asns', 'traffic_challenge_threshold', 'traffic_block_threshold',
        'traffic_fingerprint_cookie', 'geoip_country_database', 'geoip_asn_database', 'geoip_anonymous_database',
      ],
    ],
    [
      'id' => 'rate-limit',
      'title' => '频率限制',
      'summary' => '按 IP 与路由组合计数的限速策略。',
      'fields' => [
        'traffic_rate_limit_enabled', 'traffic_rate_limit_max', 'traffic_rate_limit_decay',
      ],
    ],
    [
      'id' => 'provider',
      'title' => 'IP 供应商',
      'summary' => '离线缓存同步、超时与失败处理。',
      'fields' => [
        'ip_provider_enabled', 'ip_provider', 'ip_provider_endpoint', 'ip_provider_token',
        'ip_provider_timeout', 'ip_provider_cache_ttl', 'ip_provider_fail_mode',
        'ip_provider_allow_empty', 'ip_provider_schedule',
      ],
    ],
  ];

  // 兼容历史文本值与 JSON 数组；下拉保存后统一由 SettingRepo 存为数组。
  $normalizeList = static function (mixed $value): array {
    if (is_string($value)) {
      $decoded = json_decode($value, true);
      $value = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    }

    if (! is_array($value)) {
      return [];
    }

    return array_values(array_filter(array_map(
      static fn (mixed $item): string => strtoupper(trim((string) $item)),
      $value
    )));
  };
@endphp

@push('header')
  <style>
    .cyber-cloak-settings .accordion-button { letter-spacing: 0; }
    .cyber-cloak-settings .accordion-summary { font-size: 13px; color: var(--bs-secondary-color); }
  </style>
@endpush

<form class="needs-validation cyber-cloak-settings" novalidate action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST" id="form-app">
  @csrf
  {{ method_field('put') }}

  <div class="accordion" id="cyber-cloak-settings-accordion">
    @foreach ($sections as $section)
      @php
        $isFirstSection = $loop->first;
        $collapseId = 'cyber-cloak-' . $section['id'];
      @endphp
      <div class="accordion-item">
        <h2 class="accordion-header" id="{{ $collapseId }}-heading">
          <button class="accordion-button {{ $isFirstSection ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}" aria-expanded="{{ $isFirstSection ? 'true' : 'false' }}" aria-controls="{{ $collapseId }}">
            <span>
              <span class="d-block fw-semibold">{{ $section['title'] }}</span>
              <span class="accordion-summary">{{ $section['summary'] }}</span>
            </span>
          </button>
        </h2>
        <div id="{{ $collapseId }}" class="accordion-collapse collapse {{ $isFirstSection ? 'show' : '' }}" aria-labelledby="{{ $collapseId }}-heading" data-bs-parent="#cyber-cloak-settings-accordion">
          <div class="accordion-body">
            @foreach ($section['fields'] as $fieldName)
              @continue(! $columns->has($fieldName))

              @php
                $column = $columns->get($fieldName);
                $value = old($fieldName, $column['value'] ?? '');
              @endphp

              @if ($column['type'] === 'image')
                <x-admin-form-image
                  :name="$column['name']"
                  :title="$column['label']"
                  :description="$column['description'] ?? ''"
                  :error="$errors->first($column['name'])"
                  :required="$column['required'] ? true : false"
                  :value="$value">
                  <div class="help-text font-size-12 lh-base">{{ __('common.recommend_size') }} {{ $column['recommend_size'] ?? '100*100' }}</div>
                </x-admin-form-image>
              @endif

              @if ($column['type'] === 'string')
                <x-admin-form-input
                  :name="$column['name']"
                  :title="$column['label']"
                  :placeholder="$column['placeholder'] ?? ''"
                  :description="$column['description'] ?? ''"
                  :error="$errors->first($column['name'])"
                  :required="$column['required'] ? true : false"
                  :value="$value" />
              @endif

              @if ($column['type'] === 'select')
                <x-admin-form-select :name="$column['name']" :title="$column['label']" :value="$value" :options="$column['options']">
                  @if (isset($column['description']))
                    <div class="help-text font-size-12 lh-base">{{ $column['description'] }}</div>
                  @endif
                </x-admin-form-select>
              @endif

              @if ($column['type'] === 'select-multiple')
                @php
                  $selectedValues = $normalizeList($value);
                  $options = $column['options'] ?? [];
                  $knownValues = collect($options)->pluck('value')->map(
                    static fn (mixed $item): string => strtoupper((string) $item)
                  )->all();

                  // 存量手工规则不会因切换为下拉控件而丢失，可在页面中继续取消或替换。
                  foreach (array_diff($selectedValues, $knownValues) as $customValue) {
                    $options[] = ['value' => $customValue, 'label' => '自定义 - ' . $customValue];
                  }
                @endphp
                <x-admin-form-select :name="$column['name']" :title="$column['label']" :value="$selectedValues" :options="$options" :multiple="true">
                  @if (isset($column['description']))
                    <div class="help-text font-size-12 lh-base">{{ $column['description'] }}</div>
                  @endif
                </x-admin-form-select>
              @endif

              @if ($column['type'] === 'bool')
                <x-admin-form-switch :name="$column['name']" :title="$column['label']" :value="$value">
                  @if (isset($column['description']))
                    <div class="help-text font-size-12 lh-base">{{ $column['description'] }}</div>
                  @endif
                </x-admin-form-switch>
              @endif

              @if ($column['type'] === 'textarea')
                <x-admin-form-textarea :name="$column['name']" :title="$column['label']" :required="$column['required'] ? true : false" :value="$value">
                  @if (isset($column['description']))
                    <div class="help-text font-size-12 lh-base">{{ $column['description'] }}</div>
                  @endif
                </x-admin-form-textarea>
              @endif
            @endforeach
          </div>
        </div>
      </div>
    @endforeach
  </div>

  <x-admin::form.row title="">
    <button type="submit" class="btn btn-primary btn-lg mt-4">{{ __('common.submit') }}</button>
  </x-admin::form.row>
</form>
