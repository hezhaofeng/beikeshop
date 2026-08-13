@php
  // 仅保留基础设置和高级设置；映射管理在同一编辑页的独立第三部分展示。
  $columns = collect($plugin->getColumns())->keyBy('name');
  $sections = [
    [
      'id' => 'basic',
      'title' => '基础设置',
      'summary' => '访问 key、IP 规则及当前插件和双库运行状态。',
      'groups' => [[
        'title' => '访问与 IP 规则',
        'fields' => ['status', 'ip_whitelist', 'ip_blacklist', 'ip_reputation'],
      ]],
    ],
    [
      'id' => 'advanced',
      'title' => '高级设置',
      'summary' => '本地 GeoIP、流量规则和风险策略。',
      'groups' => [
        [
          'title' => 'GeoIP 与云 IP 汇总库',
          'fields' => ['geoip_enabled', 'geoip_country_database', 'geoip_asn_database', 'geoip_anonymous_database', 'cloud_ip_ranges_database'],
        ],
        [
          'title' => '流量规则',
          'fields' => ['traffic_funnel_enabled', 'traffic_allowed_countries', 'traffic_allowed_languages', 'traffic_user_agent_blacklist', 'traffic_blocked_asns', 'traffic_datacenter_asns'],
        ],
        [
          'title' => '风险策略',
          'fields' => ['traffic_rate_limit_enabled', 'traffic_rate_limit_max', 'traffic_rate_limit_decay', 'traffic_challenge_threshold', 'traffic_block_threshold', 'traffic_fingerprint_cookie', 'traffic_behavior_enabled', 'traffic_behavior_window', 'traffic_behavior_max_requests', 'traffic_behavior_max_routes', 'traffic_behavior_invalid_cookie_max', 'traffic_behavior_score', 'traffic_risk_audit_enabled', 'traffic_risk_audit_threshold'],
        ],
      ],
    ],
  ];
  // 路由缓存尚未刷新时仍允许配置页加载，并在操作区提示管理员刷新缓存。
  $shareRouteName = admin_name() . '.cyber_cloak.keys.share';
  $shareEndpoint = \Illuminate\Support\Facades\Route::has($shareRouteName)
    ? admin_route('cyber_cloak.keys.share', ['id' => '__KEY__'])
    : null;

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
    .cyber-cloak-settings .settings-group + .settings-group { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--bs-border-color); }
    .cyber-cloak-runtime-status .status-value { font-size: 14px; font-weight: 600; }
    .cyber-cloak-access-keys .key-actions { white-space: nowrap; }
  </style>
@endpush

<form class="needs-validation cyber-cloak-settings" novalidate action="{{ admin_route('cyber_cloak.settings.update') }}" method="POST" id="form-app">
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
            @if ($section['id'] === 'basic')
              <section id="cyber-cloak-runtime-status" class="cyber-cloak-runtime-status border rounded p-3 mb-4" aria-live="polite">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                  <h6 class="mb-0">运行状态</h6>
                  <button type="button" class="btn btn-outline-secondary btn-sm" data-action="refresh-runtime">刷新</button>
                </div>
                <div class="row g-3">
                  <div class="col-md-4"><div class="text-secondary small">插件</div><div class="status-value" data-field="plugin">正在检测...</div></div>
                  <div class="col-md-4"><div class="text-secondary small">真实库</div><div class="status-value" data-field="real">正在检测...</div></div>
                  <div class="col-md-4"><div class="text-secondary small">展示库</div><div class="status-value" data-field="public">正在检测...</div></div>
                </div>
              </section>

              <section id="cyber-cloak-access-keys" class="cyber-cloak-access-keys settings-group" aria-live="polite">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                  <div>
                    <h6 class="mb-1">访问 key</h6>
                    <p class="text-secondary small mb-0">列表仅显示摘要；分享链接使用保存的原文 key。</p>
                  </div>
                  <button type="button" class="btn btn-outline-secondary btn-sm" data-action="refresh-access-keys" title="刷新访问 key 列表" aria-label="刷新访问 key 列表"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
                </div>
                <div class="row g-2 align-items-end mb-3">
                  <div class="col-md-7">
                    <label class="form-label small mb-1" for="cyber-cloak-new-access-key">新访问 key</label>
                    <input id="cyber-cloak-new-access-key" class="form-control" type="password" data-field="new-access-key" maxlength="512" autocomplete="new-password">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small mb-1" for="cyber-cloak-access-key-expires-at">到期日期</label>
                    <input id="cyber-cloak-access-key-expires-at" class="form-control" type="date" data-field="access-key-expires-at">
                  </div>
                  <div class="col-md-2 d-grid">
                    <button type="button" class="btn btn-primary" data-action="create-access-key" title="保存访问 key"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>添加</button>
                  </div>
                </div>
                <div class="alert alert-info py-2 mb-3" data-field="access-key-message">正在读取访问 key...</div>
                <div class="table-responsive border rounded">
                  <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th>摘要</th>
                        <th>状态</th>
                        <th>到期时间</th>
                        <th class="text-end">操作</th>
                      </tr>
                    </thead>
                    <tbody data-field="access-key-items">
                      <tr><td colspan="4" class="text-center text-secondary py-4">正在读取...</td></tr>
                    </tbody>
                  </table>
                </div>
              </section>
            @endif

            @foreach ($section['groups'] as $group)
              <section class="settings-group">
                <h6 class="mb-3">{{ $group['title'] }}</h6>
                @foreach ($group['fields'] as $fieldName)
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
              </section>
            @endforeach
            @if ($section['id'] === 'advanced')
              <section id="cyber-cloak-risk-audit" class="settings-group" aria-live="polite">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                  <div>
                    <h6 class="mb-1">风险 IP 审核</h6>
                    <p class="text-secondary small mb-0">行为异常、自动化线索和未知扫描模式达到审核分数后显示在这里；IP 仅展示脱敏地址。</p>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm" data-field="risk-status-filter" aria-label="风险审核状态筛选">
                      <option value="">全部状态</option>
                      <option value="unreviewed">待审核</option>
                      <option value="trusted">可信</option>
                      <option value="suspicious">可疑</option>
                      <option value="blocked">封禁</option>
                    </select>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="refresh-risk-audit" title="刷新风险 IP 审核列表"><i class="bi bi-arrow-clockwise"></i></button>
                  </div>
                </div>
                <div class="alert alert-info py-2 mb-3" data-field="risk-message">正在读取风险 IP 审核数据...</div>
                <div class="table-responsive border rounded">
                  <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th>IP</th>
                        <th>状态</th>
                        <th>风险分</th>
                        <th>命中原因</th>
                        <th>最近出现</th>
                        <th class="text-end">审核</th>
                      </tr>
                    </thead>
                    <tbody data-field="risk-items">
                      <tr><td colspan="6" class="text-center text-secondary py-4">正在读取...</td></tr>
                    </tbody>
                  </table>
                </div>
              </section>
            @endif
          </div>
        </div>
      </div>
    @endforeach
  </div>

  <x-admin::form.row title="">
    <button type="submit" class="btn btn-primary btn-lg mt-4">{{ __('common.submit') }}</button>
  </x-admin::form.row>
</form>

@push('footer')
  <script>
    $(function () {
      const panel = $('#cyber-cloak-runtime-status');
      const endpoint = @json(admin_route('cyber_cloak.runtime.status'));
      const keyPanel = $('#cyber-cloak-access-keys');
      const keyEndpoints = {
        index: @json(admin_route('cyber_cloak.keys')),
        create: @json(admin_route('cyber_cloak.keys.create')),
        share: @json($shareEndpoint),
        update: @json(admin_route('cyber_cloak.keys.update', ['id' => '__KEY__'])),
        remove: @json(admin_route('cyber_cloak.keys.delete', ['id' => '__KEY__'])),
      };
      const riskPanel = $('#cyber-cloak-risk-audit');
      const riskEndpoints = {
        index: @json(admin_route('cyber_cloak.traffic_risks.index')),
        review: @json(admin_route('cyber_cloak.traffic_risks.review', ['profile' => '__PROFILE__'])),
      };

      // 状态接口只探测插件和连接，不保存或修改任何后台配置。
      function refreshRuntimeStatus() {
        panel.find('[data-field]').text('正在检测...');
        $.get(endpoint).done(function (response) {
          const data = response && response.data ? response.data : {};
          const plugin = data.plugin || {};
          const connections = data.connections || {};
          panel.find('[data-field="plugin"]').text(plugin.label || '状态未知');
          ['real', 'public'].forEach(function (mode) {
            const connection = connections[mode] || {};
            panel.find('[data-field="' + mode + '"]').text(connection.label || '连接状态未知');
          });
        }).fail(function () {
          panel.find('[data-field]').text('状态读取失败');
        });
      }

      panel.on('click', '[data-action="refresh-runtime"]', refreshRuntimeStatus);
      refreshRuntimeStatus();

      // 访问 key 列表只展示摘要；分享链接由管理员点击后按需生成。
      function keyPayload(response) {
        return response && response.data ? response.data : {};
      }

      function keyErrorMessage(error) {
        return error?.response?.data?.message || error?.message || '访问 key 操作失败';
      }

      function showKeyMessage(message, type) {
        const alert = keyPanel.find('[data-field="access-key-message"]');
        alert.removeClass('alert-info alert-success alert-danger alert-warning').addClass('alert-' + (type || 'info')).text(message);
      }

      function keyStatusLabel(status) {
        return status === 'enabled' ? '已启用' : '已停用';
      }

      function renderAccessKeys(data) {
        const items = data.items || [];
        const body = keyPanel.find('[data-field="access-key-items"]');
        body.empty();
        if (items.length === 0) {
          body.append('<tr><td colspan="4" class="text-center text-secondary py-4">暂无已保存访问 key</td></tr>');
          return;
        }

        items.forEach(function (item) {
          const id = escapeHtml(item.id || '');
          const status = item.status === 'enabled' ? 'enabled' : 'disabled';
          const statusClass = status === 'enabled' ? 'text-bg-success' : 'text-bg-secondary';
          const actionTitle = status === 'enabled' ? '停用访问 key' : '启用访问 key';
          const actionIcon = status === 'enabled' ? 'bi-pause-circle' : 'bi-play-circle';
          const hash = escapeHtml(item.hash || '旧版明文配置');
          const expiresAt = escapeHtml(item.expires_at || '永久有效');
          const shareable = item.shareable === true;
          const shareReason = escapeHtml(item.share_reason || '该 key 无法生成分享链接');
          const shareButton = !keyEndpoints.share
            ? '<button type="button" class="btn btn-outline-secondary btn-sm" disabled title="分享接口尚未注册，请执行 php artisan optimize:clear" aria-label="分享接口尚未注册，请执行 php artisan optimize:clear"><i class="bi bi-share" aria-hidden="true"></i></button>'
            : shareable
            ? '<button type="button" class="btn btn-outline-primary btn-sm" data-action="share-access-key" title="生成并复制访问链接" aria-label="生成并复制访问链接"><i class="bi bi-share" aria-hidden="true"></i></button>'
            : '<button type="button" class="btn btn-outline-secondary btn-sm" disabled title="' + shareReason + '" aria-label="' + shareReason + '"><i class="bi bi-share" aria-hidden="true"></i></button>';
          body.append(
            '<tr data-key-id="' + id + '" data-key-status="' + status + '">' +
              '<td class="font-monospace text-break">' + hash + '</td>' +
              '<td><span class="badge ' + statusClass + '">' + keyStatusLabel(status) + '</span></td>' +
              '<td class="text-nowrap">' + expiresAt + '</td>' +
              '<td class="text-end key-actions">' +
                shareButton +
                '<button type="button" class="btn btn-outline-secondary btn-sm" data-action="toggle-access-key" title="' + actionTitle + '" aria-label="' + actionTitle + '"><i class="bi ' + actionIcon + '" aria-hidden="true"></i></button>' +
                '<button type="button" class="btn btn-outline-danger btn-sm ms-1" data-action="delete-access-key" title="删除访问 key" aria-label="删除访问 key"><i class="bi bi-trash3" aria-hidden="true"></i></button>' +
              '</td>' +
            '</tr>'
          );
        });
      }

      function loadAccessKeys() {
        if (!keyPanel.length) {
          return;
        }
        return $http.get(keyEndpoints.index, null, {hload: true}).then(function (response) {
          const data = keyPayload(response);
          renderAccessKeys(data);
          showKeyMessage('访问 key 已读取', 'info');
          return data;
        }).catch(function (error) {
          showKeyMessage(keyErrorMessage(error), 'danger');
        });
      }

      keyPanel.on('click', '[data-action="refresh-access-keys"]', loadAccessKeys);
      keyPanel.on('click', '[data-action="create-access-key"]', function () {
        const keyInput = keyPanel.find('[data-field="new-access-key"]');
        const expiresInput = keyPanel.find('[data-field="access-key-expires-at"]');
        const key = (keyInput.val() || '').trim();
        if (!key) {
          showKeyMessage('请输入访问 key', 'warning');
          keyInput.trigger('focus');
          return;
        }

        $http.post(keyEndpoints.create, {key: key, expires_at: expiresInput.val() || null}, {hload: true}).then(function (response) {
          keyInput.val('');
          expiresInput.val('');
          showKeyMessage(response.message || '访问 key 已保存', 'success');
          return loadAccessKeys();
        }).catch(function (error) {
          showKeyMessage(keyErrorMessage(error), 'danger');
        });
      });

      function copyShareUrl(shareUrl) {
        if (navigator.clipboard && window.isSecureContext) {
          return navigator.clipboard.writeText(shareUrl);
        }

        return new Promise(function (resolve, reject) {
          const textarea = document.createElement('textarea');
          textarea.value = shareUrl;
          textarea.setAttribute('readonly', '');
          textarea.style.position = 'fixed';
          textarea.style.opacity = '0';
          document.body.appendChild(textarea);
          textarea.select();
          const copied = document.execCommand('copy');
          document.body.removeChild(textarea);
          copied ? resolve() : reject();
        });
      }

      keyPanel.on('click', '[data-action="share-access-key"]', function () {
        const id = String($(this).closest('tr').data('key-id') || '');
        if (!keyEndpoints.share) {
          showKeyMessage('分享接口尚未注册，请执行 php artisan optimize:clear', 'warning');
          return;
        }
        if (!id) {
          showKeyMessage('访问 key 标识无效', 'danger');
          return;
        }

        const endpoint = keyEndpoints.share.replace('__KEY__', encodeURIComponent(id));
        $http.post(endpoint, {}, {hload: true}).then(function (response) {
          const shareUrl = keyPayload(response).url || '';
          if (!shareUrl) {
            showKeyMessage('访问链接生成失败', 'danger');
            return;
          }

          return copyShareUrl(shareUrl).then(function () {
            showKeyMessage('访问链接已生成并复制', 'success');
          }).catch(function () {
            window.prompt('请复制访问链接', shareUrl);
            showKeyMessage('访问链接已生成，请手动复制', 'warning');
          });
        }).catch(function (error) {
          showKeyMessage(keyErrorMessage(error), 'danger');
        });
      });

      keyPanel.on('click', '[data-action="toggle-access-key"]', function () {
        const row = $(this).closest('tr');
        const id = String(row.data('key-id') || '');
        const status = row.data('key-status') === 'enabled' ? 'disabled' : 'enabled';
        if (!id) {
          showKeyMessage('访问 key 标识无效', 'danger');
          return;
        }

        const endpoint = keyEndpoints.update.replace('__KEY__', encodeURIComponent(id));
        $http.put(endpoint, {status: status}, {hload: true}).then(function (response) {
          showKeyMessage(response.message || '访问 key 状态已更新', 'success');
          return loadAccessKeys();
        }).catch(function (error) {
          showKeyMessage(keyErrorMessage(error), 'danger');
        });
      });

      keyPanel.on('click', '[data-action="delete-access-key"]', function () {
        const row = $(this).closest('tr');
        const id = String(row.data('key-id') || '');
        if (!id || !window.confirm('确认删除该访问 key？')) {
          return;
        }

        const endpoint = keyEndpoints.remove.replace('__KEY__', encodeURIComponent(id));
        $http.delete(endpoint, null, {hload: true}).then(function (response) {
          showKeyMessage(response.message || '访问 key 已删除', 'success');
          return loadAccessKeys();
        }).catch(function (error) {
          showKeyMessage(keyErrorMessage(error), 'danger');
        });
      });
      loadAccessKeys();

      // 后台表格统一转义服务端文本，避免风险原因被当作 HTML 插入审核页面。
      function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
      }

      // 将风险状态转为可扫描的中文标签。
      function riskStatusLabel(status) {
        return {
          unreviewed: '待审核',
          trusted: '可信',
          suspicious: '可疑',
          blocked: '封禁',
        }[status] || status || '-';
      }

      // 在高级设置内提示审核表是否可用和当前操作结果。
      function showRiskMessage(message, type) {
        const alert = riskPanel.find('[data-field="risk-message"]');
        alert.removeClass('alert-info alert-success alert-danger alert-warning').addClass('alert-' + (type || 'info')).text(message);
      }

      // 重绘脱敏 IP 风险档案，审核选择只提交受控状态值。
      function renderRiskProfiles(data) {
        const body = riskPanel.find('[data-field="risk-items"]');
        body.empty();
        if (!data.available) {
          body.append('<tr><td colspan="6" class="text-center text-secondary py-4">请先执行 CyberCloak 风险审计迁移</td></tr>');
          return;
        }
        if (!data.items || data.items.length === 0) {
          body.append('<tr><td colspan="6" class="text-center text-secondary py-4">当前筛选条件下没有风险 IP 档案</td></tr>');
          return;
        }
        data.items.forEach(function (item) {
          const reasons = (item.last_reasons || []).join('、') || '-';
          const statuses = ['unreviewed', 'trusted', 'suspicious', 'blocked'].map(function (status) {
            return '<option value="' + status + '"' + (item.status === status ? ' selected' : '') + '>' + riskStatusLabel(status) + '</option>';
          }).join('');
          body.append(
            '<tr data-profile-id="' + Number(item.id) + '">' +
              '<td class="text-nowrap">' + escapeHtml(item.ip || '已加密') + '</td>' +
              '<td><span class="badge text-bg-secondary">' + escapeHtml(riskStatusLabel(item.status)) + '</span></td>' +
              '<td class="text-nowrap">' + Number(item.last_score || 0) + ' / ' + Number(item.max_score || 0) + '<div class="small text-secondary">' + Number(item.hit_count || 0) + ' 次</div></td>' +
              '<td class="small">' + escapeHtml(reasons) + '</td>' +
              '<td class="small text-nowrap">' + escapeHtml(item.last_seen_at || '-') + '</td>' +
              '<td class="text-end"><div class="d-flex justify-content-end gap-1"><input type="text" class="form-control form-control-sm" data-role="risk-note" value="' + escapeHtml(item.review_note || '') + '" placeholder="审核备注" aria-label="审核备注"><select class="form-select form-select-sm" data-role="risk-status">' + statuses + '</select><button type="button" class="btn btn-outline-primary btn-sm" data-action="review-risk" title="保存审核状态"><i class="bi bi-check2"></i></button></div></td>' +
            '</tr>'
          );
        });
      }

      // 获取当前筛选条件下的风险档案，迁移未执行时以可读提示降级。
      function loadRiskProfiles() {
        if (!riskPanel.length) {
          return;
        }
        const status = riskPanel.find('[data-field="risk-status-filter"]').val() || '';
        return $http.get(riskEndpoints.index, {status: status, per_page: 20}, {hload: true}).then(function (response) {
          const data = response && response.data ? response.data : {};
          renderRiskProfiles(data);
          showRiskMessage(data.available ? '风险 IP 审核数据已读取' : '风险审计表尚未创建', data.available ? 'info' : 'warning');
        }).catch(function (error) {
          showRiskMessage(error?.response?.data?.message || '风险 IP 审核数据读取失败', 'danger');
        });
      }

      riskPanel.on('click', '[data-action="refresh-risk-audit"]', loadRiskProfiles);
      riskPanel.on('change', '[data-field="risk-status-filter"]', loadRiskProfiles);
      riskPanel.on('click', '[data-action="review-risk"]', function () {
        const row = $(this).closest('tr');
        const profileId = Number(row.data('profile-id'));
        const status = row.find('[data-role="risk-status"]').val();
        if (!profileId || !status) {
          showRiskMessage('风险 IP 档案无效', 'danger');
          return;
        }
        const endpoint = riskEndpoints.review.replace('__PROFILE__', profileId);
        const note = row.find('[data-role="risk-note"]').val() || '';
        $http.post(endpoint, {status: status, note: note}, {hload: true}).then(function (response) {
          showRiskMessage(response?.message || '风险 IP 审核状态已更新', 'success');
          return loadRiskProfiles();
        }).catch(function (error) {
          showRiskMessage(error?.response?.data?.message || '风险 IP 审核状态更新失败', 'danger');
        });
      });
      loadRiskProfiles();
    });
  </script>
@endpush
