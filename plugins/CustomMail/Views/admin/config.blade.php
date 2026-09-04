@extends('admin::layouts.master')

@section('title', $plugin->getLocaleName())
@section('content-area-class', 'w-max-1200')
@section('page-title-back', admin_route('plugins.index', http_build_query(request()->query())))
@section('head-form-btns', true)

@section('content')
@php
  $setting = $plugin->getSetting() ?: [];
  $enabled = old('enabled_events', $setting['enabled_events'] ?? []);
  $templates = old('templates', $setting['templates'] ?? []);
  $additionalRecipients = old('additional_recipients', $setting['additional_recipients'] ?? '');
  $enabled = is_array($enabled) ? array_values(array_filter($enabled)) : [];
  $templates = is_array($templates) ? $templates : [];
  $paymentInfoCodes = \Plugin\CustomMail\Services\CustomMailService::PAYMENT_INFO_PAYMENT_CODES;
  $paymentInfoLabels = [
    'offline_transfer' => 'Offline Transfer',
    'western_union'   => 'Western Union',
  ];
  $paymentInfo = old('payment_info', $setting['payment_info'] ?? []);
  $paymentInfo = is_array($paymentInfo) ? $paymentInfo : [];
  $paymentMethods = is_array($paymentMethods ?? null) ? $paymentMethods : [];
  $paymentMethodTemplateEvents = \Plugin\CustomMail\Services\CustomMailService::PAYMENT_METHOD_TEMPLATE_EVENTS;
  $paymentMethodTemplates = [];
  foreach ($paymentMethodTemplateEvents as $paymentMethodTemplateEvent) {
    $paymentMethodTemplates[$paymentMethodTemplateEvent] = data_get($templates, $paymentMethodTemplateEvent . '.payment_methods', []);
    $paymentMethodTemplates[$paymentMethodTemplateEvent] = is_array($paymentMethodTemplates[$paymentMethodTemplateEvent])
      ? $paymentMethodTemplates[$paymentMethodTemplateEvent]
      : [];
  }
  if ($paymentMethods === []) {
    $savedPaymentCodes = [];
    foreach ($paymentMethodTemplates as $savedTemplates) {
      $savedPaymentCodes = array_merge($savedPaymentCodes, array_keys($savedTemplates));
    }
    foreach (array_unique($savedPaymentCodes) as $paymentCode) {
      $paymentCode = trim((string) $paymentCode);
      if ($paymentCode !== '') {
        $paymentMethods[] = ['code' => $paymentCode, 'label' => "已保存配置（{$paymentCode}）", 'enabled' => false];
      }
    }
  }
  $events = \Plugin\CustomMail\Services\CustomMailService::EVENTS;
  $defaults = [
    'customer_registered' => ['subject' => '欢迎注册 {{store_name}}', 'body' => '<h2>欢迎注册</h2><p>您好，{{customer_name}}。</p>'],
    'order_created' => ['subject' => '订单 {{order_number}} 已创建', 'body' => '<h2>订单已创建</h2><p>订单号：{{order_number}}<br>金额：{{order_total}}</p>{{payment_info_html}}'],
    'order_status_unpaid' => ['subject' => '订单 {{order_number}} 待支付', 'body' => '<h2>订单待支付</h2><p>您的订单已创建，待支付金额：{{order_total}}。</p>{{payment_info_html}}<p><a href="{{payment_url}}">立即支付</a></p>'],
    'order_payment_reminder' => ['subject' => '提醒：订单 {{order_number}} 尚未支付', 'body' => '<h2>订单待支付提醒</h2><p>您的订单尚未完成支付，待支付金额：{{order_total}}。</p>{{payment_info_html}}<p><a href="{{payment_url}}">立即支付</a></p>'],
    'order_status_paid' => ['subject' => '订单 {{order_number}} 已支付', 'body' => '<p>您的订单已支付，感谢您的购买。</p>'],
    'order_status_shipped' => ['subject' => '订单 {{order_number}} 已发货', 'body' => '<p>您的订单已发货，请留意物流信息。</p>'],
    'order_status_completed' => ['subject' => '订单 {{order_number}} 已完成', 'body' => '<p>订单已完成，感谢您的支持。</p>'],
    'order_status_cancelled' => ['subject' => '订单 {{order_number}} 已取消', 'body' => '<p>订单已取消。</p>'],
    'order_status_refunding' => ['subject' => '订单 {{order_number}} 退款处理中', 'body' => '<p>订单退款正在处理中。</p>'],
    'rma_created' => ['subject' => '售后申请 {{rma_id}} 已提交', 'body' => '<p>售后申请已提交，订单号：{{order_number}}。</p>'],
  ];
  $placeholders = [
    '{{customer_name}}', '{{customer_email}}', '{{order_number}}', '{{order_status}}',
    '{{order_total}}', '{{order_date}}', '{{shipping_method}}', '{{payment_method}}', '{{payment_url}}', '{{order_view_url}}',
    '{{view_order_button_html}}', '{{order_products_html}}', '{{order_totals_html}}', '{{shipping_address_html}}', '{{shipping_telephone}}',
    '{{payment_info_html}}',
    '{{rma_id}}', '{{rma_status}}', '{{rma_product}}', '{{rma_quantity}}',
    '{{comment}}', '{{store_name}}',
  ];
@endphp

<form class="needs-validation" novalidate action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST" id="custom-mail-form">
  @csrf
  {{ method_field('put') }}

  <div class="card mb-4">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
          <h5 class="mb-1">自定义邮件设置</h5>
          <div class="text-secondary small">选择发送场景，为每个场景设置邮件主题、HTML 正文及是否发送给客户。</div>
        </div>
        <span class="badge bg-light text-dark border">已选择 <span data-custom-mail-enabled-count>{{ count($enabled) }}</span> 个场景</span>
      </div>
      <hr>
      <x-admin-form-switch name="status" title="启用插件" value="{{ old('status', (int) ($setting['status'] ?? 0)) }}">
        <div class="help-text font-size-12 lh-base">关闭后不会发送本插件配置的任何邮件。</div>
      </x-admin-form-switch>
      <x-admin-form-input name="additional_recipients" title="其他收件人" :required="false" value="{{ $additionalRecipients }}">
        <div class="help-text font-size-12 lh-base">所有已启用场景都会发送给后台基础设置的联系邮箱；此处可额外填写多个收件人，使用逗号、分号或空格分隔。</div>
      </x-admin-form-input>
      <x-admin::form.row title="发送场景">
        <input type="hidden" name="enabled_events[]" value="">
        <div class="form-checkbox">
          @foreach ($events as $event => $label)
            <label class="form-check d-inline-flex align-items-center mt-2 me-4">
              <input class="form-check-input me-2" type="checkbox" name="enabled_events[]" value="{{ $event }}" data-custom-mail-event="{{ $event }}" @checked(in_array($event, $enabled, true))>
              <span class="form-check-label">{{ $label }}</span>
            </label>
          @endforeach
        </div>
        <div class="help-text font-size-12 lh-base">订单状态邮件会在状态迁移成功且事务提交后发送。若要替换系统通知，请在系统邮件设置中关闭对应场景，避免重复发送。</div>
      </x-admin::form.row>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <h6 class="card-title mb-1">支付信息 HTML 片段</h6>
        <div class="text-secondary small">仅用于 Offline Transfer 和 Western Union 邮件。模板中放置 <code>@{{payment_info_html}}</code> 后，发送时会自动替换为选中的片段。</div>
      </div>
    </div>
    <div class="card-body">
      @foreach ($paymentInfoCodes as $paymentCode)
        @php
          $methodConfig = is_array($paymentInfo[$paymentCode] ?? null) ? $paymentInfo[$paymentCode] : [];
          $profiles = is_array($methodConfig['profiles'] ?? null) ? array_values($methodConfig['profiles']) : [];
          $strategy = ($methodConfig['strategy'] ?? 'manual') === 'round_robin' ? 'round_robin' : 'manual';
          $selectedProfile = (string) ($methodConfig['selected_profile'] ?? '');
          $rotationBatchSize = max(1, min(1000000, (int) ($methodConfig['rotation_batch_size'] ?? 1)));
        @endphp
        <div class="border rounded p-3 mb-4" data-payment-info-method="{{ $paymentCode }}">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <h6 class="mb-0">{{ $paymentInfoLabels[$paymentCode] ?? $paymentCode }} <code class="ms-2">{{ $paymentCode }}</code></h6>
            <div class="d-flex flex-wrap align-items-center gap-3">
              <div class="d-flex align-items-center gap-2">
                <label class="small text-secondary mb-0" for="payment-info-strategy-{{ $paymentCode }}">选择方式</label>
                <select class="form-select form-select-sm" id="payment-info-strategy-{{ $paymentCode }}" name="payment_info[{{ $paymentCode }}][strategy]" style="width:150px;">
                  <option value="manual" @selected($strategy === 'manual')>手动指定</option>
                  <option value="round_robin" @selected($strategy === 'round_robin')>按订单轮询</option>
                </select>
              </div>
              <div class="d-flex align-items-center gap-2">
                <label class="small text-secondary mb-0" for="payment-info-batch-{{ $paymentCode }}">每片段次数</label>
                <input class="form-control form-control-sm" id="payment-info-batch-{{ $paymentCode }}" name="payment_info[{{ $paymentCode }}][rotation_batch_size]" type="number" min="1" max="1000000" step="1" value="{{ $rotationBatchSize }}" style="width:100px;">
                <span class="small text-secondary">次</span>
              </div>
            </div>
            <div class="form-text">仅轮询模式生效。例如填写 3，当前片段连续分配 3 个新订单后切换到下一个片段；手动模式忽略此设置。</div>
          </div>
          <div class="mb-3" data-payment-info-selected-wrap="{{ $paymentCode }}">
            <label class="form-label" for="payment-info-selected-{{ $paymentCode }}">手动使用的片段</label>
            <select class="form-select" id="payment-info-selected-{{ $paymentCode }}" name="payment_info[{{ $paymentCode }}][selected_profile]">
              <option value="">自动使用第一个启用片段</option>
              @foreach ($profiles as $profile)
                @php $profileId = trim((string) ($profile['id'] ?? '')); @endphp
                @if ($profileId !== '')
                  <option value="{{ $profileId }}" @selected($selectedProfile === $profileId)>{{ $profile['name'] ?? $profileId }}</option>
                @endif
              @endforeach
            </select>
          </div>
          <div class="d-flex flex-column gap-3" data-payment-info-profiles="{{ $paymentCode }}">
            @foreach ($profiles as $index => $profile)
              @php
                $profileId = trim((string) ($profile['id'] ?? 'profile_' . $index));
                $profileName = (string) ($profile['name'] ?? '');
                $profileHtml = (string) ($profile['html'] ?? '');
                $profileEnabled = filter_var($profile['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
              @endphp
              <div class="border rounded p-3" data-payment-info-profile>
                <input type="hidden" name="payment_info[{{ $paymentCode }}][profiles][{{ $index }}][id]" value="{{ $profileId }}">
                <div class="row g-3 align-items-start">
                  <div class="col-md-4">
                    <label class="form-label">片段名称</label>
                    <input class="form-control" name="payment_info[{{ $paymentCode }}][profiles][{{ $index }}][name]" value="{{ $profileName }}" maxlength="100">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">状态</label>
                    <select class="form-select" name="payment_info[{{ $paymentCode }}][profiles][{{ $index }}][enabled]">
                      <option value="1" @selected($profileEnabled)>启用</option>
                      <option value="0" @selected(! $profileEnabled)>停用</option>
                    </select>
                  </div>
                  <div class="col-md-5 text-md-end">
                    <button type="button" class="btn btn-outline-danger btn-sm mt-md-4" data-payment-info-remove>删除片段</button>
                  </div>
                  <div class="col-12">
                    <label class="form-label">HTML 片段</label>
                    <textarea class="form-control font-monospace" name="payment_info[{{ $paymentCode }}][profiles][{{ $index }}][html]" rows="8" maxlength="100000" placeholder="粘贴支付信息 HTML，例如 table 片段">{{ $profileHtml }}</textarea>
                    <div class="form-text">支持现有邮件占位符，例如 <code>@{{order_number}}</code>。发送前会过滤脚本和事件属性。</div>
                  </div>
                </div>
              </div>
            @endforeach
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm mt-3" data-payment-info-add="{{ $paymentCode }}">添加 {{ $paymentInfoLabels[$paymentCode] ?? $paymentCode }} 片段</button>
        </div>
      @endforeach
    </div>
  </div>

  <div class="row g-4 align-items-start">
    <div class="col-lg-3">
      <div class="card">
        <div class="card-header"><h6 class="card-title mb-0">邮件场景</h6></div>
        <div class="card-body p-2">
          <div class="nav nav-pills flex-column gap-1" role="tablist" aria-label="邮件场景">
            @foreach ($events as $event => $label)
              <button class="btn {{ $loop->first ? 'btn-primary' : 'btn-light' }} text-start w-100 d-flex align-items-center justify-content-between" type="button" data-custom-mail-tab="{{ $event }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                <span>{{ $label }}</span>
                <span class="badge {{ in_array($event, $enabled, true) ? 'bg-white text-primary' : 'bg-secondary' }}" data-custom-mail-state="{{ $event }}">{{ in_array($event, $enabled, true) ? '已启用' : '未启用' }}</span>
              </button>
            @endforeach
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-9">
      @foreach ($events as $event => $label)
        @php
          $template = array_merge(
            $defaults[$event] ?? ['subject' => '', 'body' => ''],
            is_array($templates[$event] ?? null) ? $templates[$event] : []
          );
          if (in_array($event, $paymentMethodTemplateEvents, true)) {
            unset($template['payment_methods']);
          }
        @endphp
        <section class="card custom-mail-panel {{ $loop->first ? '' : 'd-none' }}" data-custom-mail-panel="{{ $event }}">
          <div class="card-header d-flex align-items-center justify-content-between gap-3">
            <div>
              <h6 class="card-title mb-1">{{ $label }}</h6>
                <span class="text-secondary small">编辑此场景的邮件内容及是否发送给客户。{{ $event === 'order_payment_reminder' ? '此场景仅在订单详情页手动发送。' : '' }}</span>
            </div>
            <span class="badge {{ in_array($event, $enabled, true) ? 'bg-success' : 'bg-secondary' }}" data-custom-mail-panel-state="{{ $event }}">{{ in_array($event, $enabled, true) ? '已启用' : '未启用' }}</span>
          </div>
          <div class="card-body">
            <x-admin-form-input name="templates[{{ $event }}][subject]" title="邮件主题" :required="false" value="{{ $template['subject'] ?? '' }}">
              <div class="help-text font-size-12 lh-base">主题支持下方列出的占位符。</div>
            </x-admin-form-input>
            <x-admin-form-textarea name="templates[{{ $event }}][body]" title="HTML 正文" :required="false" value="{{ $template['body'] ?? '' }}">
              <div class="help-text font-size-12 lh-base">仅替换白名单占位符，不会执行 Blade 或 PHP。商品明细和收货地址占位符由系统生成安全 HTML，仅建议放在正文中。</div>
              <div class="mt-2 d-flex flex-wrap gap-1">
                @foreach ($placeholders as $placeholder)
                  <span class="badge bg-light text-dark border fw-normal">{{ $placeholder }}</span>
                @endforeach
              </div>
            </x-admin-form-textarea>
            <x-admin::form.row title="发送给客户">
              <input type="hidden" name="templates[{{ $event }}][recipients][]" value="">
              <label class="form-check">
                <input class="form-check-input" type="checkbox" name="templates[{{ $event }}][recipients][]" value="customer" @checked(in_array('customer', (array) ($template['recipients'] ?? []), true))>
                <span class="form-check-label">发送给客户邮箱</span>
              </label>
            </x-admin::form.row>

            @if (in_array($event, $paymentMethodTemplateEvents, true))
              @php
                $eventPaymentTemplates = $paymentMethodTemplates[$event] ?? [];
              @endphp
              <div class="border-top mt-4 pt-4">
                <h6 class="mb-1">支付方式专用模板</h6>
                <div class="help-text font-size-12 lh-base mb-3">
                  订单支付方式编码精确匹配专用模板；未配置或未匹配时使用上面的通用模板。新启用的支付插件会自动出现在这里。
                </div>

                @if ($paymentMethods === [])
                  <div class="alert alert-light border mb-0">当前没有可配置的支付方式，仍可使用通用模板。</div>
                @else
                  <div class="d-flex flex-column gap-3">
                    @foreach ($paymentMethods as $paymentMethod)
                      @php
                        $paymentCode = (string) ($paymentMethod['code'] ?? '');
                        $methodTemplate = is_array($eventPaymentTemplates[$paymentCode] ?? null)
                          ? $eventPaymentTemplates[$paymentCode]
                          : [];
                        $methodConfigured = trim((string) ($methodTemplate['subject'] ?? '')) !== ''
                          && trim((string) ($methodTemplate['body'] ?? '')) !== '';
                      @endphp
                      <details class="border rounded p-3" {{ $methodConfigured ? 'open' : '' }}>
                        <summary class="d-flex align-items-center justify-content-between gap-3" style="cursor:pointer;">
                          <span>
                            <strong>{{ $paymentMethod['label'] ?? $paymentCode }}</strong>
                            <code class="ms-2">{{ $paymentCode }}</code>
                          </span>
                          <span class="badge {{ $methodConfigured ? 'bg-success' : ($paymentMethod['enabled'] ?? false ? 'bg-secondary' : 'bg-warning text-dark') }}">
                            {{ $methodConfigured ? '已配置' : (($paymentMethod['enabled'] ?? false) ? '未配置' : '支付方式不可用') }}
                          </span>
                        </summary>
                        <div class="mt-3">
                          <x-admin-form-input name="templates[{{ $event }}][payment_methods][{{ $paymentCode }}][subject]" title="邮件主题" :required="false" value="{{ $methodTemplate['subject'] ?? '' }}">
                            <div class="help-text font-size-12 lh-base">仅对支付方式编码 <code>{{ $paymentCode }}</code> 的订单生效。</div>
                          </x-admin-form-input>
                          <x-admin-form-textarea name="templates[{{ $event }}][payment_methods][{{ $paymentCode }}][body]" title="HTML 正文" :required="false" value="{{ $methodTemplate['body'] ?? '' }}">
                            <div class="help-text font-size-12 lh-base">仅替换白名单占位符，不会执行 Blade 或 PHP。商品明细和收货地址占位符由系统生成安全 HTML，仅建议放在正文中。</div>
                            <div class="mt-2 d-flex flex-wrap gap-1">
                              @foreach ($placeholders as $placeholder)
                                <span class="badge bg-light text-dark border fw-normal">{{ $placeholder }}</span>
                              @endforeach
                            </div>
                          </x-admin-form-textarea>
                          <x-admin::form.row title="发送给客户">
                            <input type="hidden" name="templates[{{ $event }}][payment_methods][{{ $paymentCode }}][recipients][]" value="">
                            <label class="form-check">
                              <input class="form-check-input" type="checkbox" name="templates[{{ $event }}][payment_methods][{{ $paymentCode }}][recipients][]" value="customer" @checked(in_array('customer', (array) ($methodTemplate['recipients'] ?? []), true))>
                              <span class="form-check-label">发送给客户邮箱</span>
                            </label>
                          </x-admin::form.row>
                        </div>
                      </details>
                    @endforeach
                  </div>
                @endif
              </div>
            @endif
          </div>
        </section>
      @endforeach
    </div>
  </div>

  <div class="mt-4">
    <button type="submit" class="btn btn-primary btn-lg">{{ __('common.submit') }}</button>
  </div>
</form>
@endsection

@push('footer')
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const tabs = document.querySelectorAll('[data-custom-mail-tab]');
      const panels = document.querySelectorAll('[data-custom-mail-panel]');
      const eventInputs = document.querySelectorAll('[data-custom-mail-event]');
      const paymentInfoMethods = document.querySelectorAll('[data-payment-info-method]');

      const activatePanel = function (event) {
        tabs.forEach(function (tab) {
          const active = tab.dataset.customMailTab === event;
          tab.classList.toggle('btn-primary', active);
          tab.classList.toggle('btn-light', !active);
          tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
          panel.classList.toggle('d-none', panel.dataset.customMailPanel !== event);
        });
      };

      const syncEnabledState = function () {
        let enabledCount = 0;
        eventInputs.forEach(function (input) {
          const enabled = input.checked;
          enabledCount += enabled ? 1 : 0;
          document.querySelectorAll('[data-custom-mail-state="' + input.dataset.customMailEvent + '"]').forEach(function (badge) {
            badge.textContent = enabled ? '已启用' : '未启用';
            badge.classList.toggle('bg-secondary', !enabled);
            badge.classList.toggle('bg-white', enabled);
            badge.classList.toggle('text-primary', enabled);
          });
          document.querySelectorAll('[data-custom-mail-panel-state="' + input.dataset.customMailEvent + '"]').forEach(function (badge) {
            badge.textContent = enabled ? '已启用' : '未启用';
            badge.classList.toggle('bg-success', enabled);
            badge.classList.toggle('bg-secondary', !enabled);
          });
        });
        document.querySelectorAll('[data-custom-mail-enabled-count]').forEach(function (element) {
          element.textContent = enabledCount;
        });
      };

      tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          activatePanel(tab.dataset.customMailTab);
        });
      });
      eventInputs.forEach(function (input) {
        input.addEventListener('change', syncEnabledState);
      });

      const syncPaymentInfoStrategy = function (method) {
        const strategy = method.querySelector('select[name$="[strategy]"]');
        const selected = method.querySelector('[data-payment-info-selected-wrap]');
        if (strategy && selected) {
          selected.classList.toggle('d-none', strategy.value !== 'manual');
        }
      };

      const syncPaymentInfoOptions = function (method) {
        const selected = method.querySelector('select[name$="[selected_profile]"]');
        if (!selected) {
          return;
        }
        const current = selected.value;
        selected.innerHTML = '<option value="">自动使用第一个启用片段</option>';
        method.querySelectorAll('[data-payment-info-profile]').forEach(function (profile) {
          const id = profile.querySelector('input[name$="[id]"]');
          const name = profile.querySelector('input[name$="[name]"]');
          if (!id || !id.value) {
            return;
          }
          const option = document.createElement('option');
          option.value = id.value;
          option.textContent = name && name.value ? name.value : id.value;
          selected.appendChild(option);
        });
        selected.value = current;
      };

      paymentInfoMethods.forEach(function (method) {
        const code = method.dataset.paymentInfoMethod;
        const strategy = method.querySelector('select[name$="[strategy]"]');
        const addButton = method.querySelector('[data-payment-info-add="' + code + '"]');
        let nextProfileIndex = method.querySelectorAll('[data-payment-info-profile]').length;
        syncPaymentInfoStrategy(method);
        syncPaymentInfoOptions(method);
        if (strategy) {
          strategy.addEventListener('change', function () {
            syncPaymentInfoStrategy(method);
          });
        }
        method.addEventListener('input', function (event) {
          if (event.target.matches('input[name$="[name]"]')) {
            syncPaymentInfoOptions(method);
          }
        });
        method.addEventListener('click', function (event) {
          const removeButton = event.target.closest('[data-payment-info-remove]');
          if (removeButton) {
            const profile = removeButton.closest('[data-payment-info-profile]');
            if (profile) {
              profile.remove();
              syncPaymentInfoOptions(method);
            }
          }
        });
        if (addButton) {
          addButton.addEventListener('click', function () {
            const container = method.querySelector('[data-payment-info-profiles]');
            const index = nextProfileIndex++;
            const id = 'profile_' + Date.now() + '_' + Math.floor(Math.random() * 10000);
            const wrapper = document.createElement('div');
            wrapper.className = 'border rounded p-3';
            wrapper.setAttribute('data-payment-info-profile', '');
            wrapper.innerHTML = `
              <input type="hidden" name="payment_info[${code}][profiles][${index}][id]" value="${id}">
              <div class="row g-3 align-items-start">
                <div class="col-md-4">
                  <label class="form-label">片段名称</label>
                  <input class="form-control" name="payment_info[${code}][profiles][${index}][name]" value="" maxlength="100">
                </div>
                <div class="col-md-3">
                  <label class="form-label">状态</label>
                  <select class="form-select" name="payment_info[${code}][profiles][${index}][enabled]"><option value="1">启用</option><option value="0">停用</option></select>
                </div>
                <div class="col-md-5 text-md-end"><button type="button" class="btn btn-outline-danger btn-sm mt-md-4" data-payment-info-remove>删除片段</button></div>
                <div class="col-12">
                  <label class="form-label">HTML 片段</label>
                  <textarea class="form-control font-monospace" name="payment_info[${code}][profiles][${index}][html]" rows="8" maxlength="100000" placeholder="粘贴支付信息 HTML，例如 table 片段"></textarea>
                  <div class="form-text">支持现有邮件占位符。发送前会过滤脚本和事件属性。</div>
                </div>
              </div>`;
            container.appendChild(wrapper);
            syncPaymentInfoOptions(method);
          });
        }
      });
      syncEnabledState();
    });
  </script>
@endpush
