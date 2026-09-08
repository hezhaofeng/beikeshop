<section id="meilisearch-index-panel" class="mt-5 pt-4 border-top">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
    <div>
      <h5 class="mb-1">索引管理</h5>
      <p class="text-secondary mb-0">从数据库读取商品重建 Meilisearch 索引。索引按商品库隔离，展示库只包含 CyberCloak 已发布的 SKU。</p>
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="refresh">
      <i class="bi bi-arrow-clockwise me-1"></i>刷新状态
    </button>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">服务状态</div>
        <div class="fw-semibold mt-1" data-field="health">检测中…</div>
        <div class="text-secondary small text-break mt-1" data-field="host">-</div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">接管前台搜索</div>
        <div class="fw-semibold mt-1" data-field="search-enabled">-</div>
        <div class="text-secondary small mt-1" data-field="mysql-fallback">-</div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">CyberCloak 兼容</div>
        <div class="fw-semibold mt-1" data-field="cyber-cloak">-</div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">启用语言</div>
        <div class="fw-semibold mt-1" data-field="locale-count">0</div>
      </div>
    </div>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <label for="meilisearch-catalog" class="mb-0 text-secondary">商品库</label>
    <select id="meilisearch-catalog" class="form-select form-select-sm" style="width: 160px"></select>

    <label for="meilisearch-locales" class="mb-0 text-secondary ms-lg-2">语言</label>
    <select id="meilisearch-locales" class="form-select form-select-sm" multiple size="1" style="min-width: 180px"></select>

    <button type="button" class="btn btn-primary btn-sm" data-action="rebuild">
      <i class="bi bi-database-down me-1"></i>从数据库同步索引
    </button>
    <button type="button" class="btn btn-outline-primary btn-sm" data-action="sync">
      <i class="bi bi-arrow-repeat me-1"></i>增量同步
    </button>
  </div>

  <div class="text-secondary small mb-3">
    不选语言表示同步全部语言。全量同步会建立新索引并在完成后原子切换，重建期间前台继续使用旧索引；
    任务由 <code>meilisearch</code> 队列执行，需保证 <code>php artisan queue:work --queue=meilisearch</code> 正在运行。
  </div>

  <div class="alert py-2 mb-3 d-none" data-field="task">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span class="fw-semibold" data-field="task-status">索引任务</span>
      <span data-field="task-progress">0%</span>
      <span class="text-secondary" data-field="task-message"></span>
      <button type="button" class="btn btn-outline-secondary btn-sm ms-auto d-none" data-action="cancel-task">终止任务</button>
    </div>
    <div class="progress mt-2" style="height: 6px">
      <div class="progress-bar" role="progressbar" style="width: 0%" data-field="task-bar"></div>
    </div>
    <div class="small text-danger mt-2 d-none" data-field="task-error"></div>
  </div>

  <div class="alert alert-info py-2 mb-3" data-field="message">正在读取索引状态…</div>

  <div data-field="catalogs"></div>
</section>

@push('footer')
  <script>
    $(function () {
      const panel = $('#meilisearch-index-panel');
      const state = {
        task: null,
        pollingTimer: null,
        catalogs: [],
      };
      const endpoints = {
        status: @json(admin_route('meilisearch.status')),
        rebuild: @json(admin_route('meilisearch.rebuild')),
        sync: @json(admin_route('meilisearch.sync')),
        deleteIndex: @json(admin_route('meilisearch.indexes.delete')),
        task: @json(admin_route('meilisearch.tasks.show', ['task' => '__TASK__'])),
        cancelTask: @json(admin_route('meilisearch.tasks.cancel', ['task' => '__TASK__'])),
      };

      // 统一转义数据库内容，避免索引名或错误信息被当作 HTML 插入页面。
      function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
      }

      function responseData(response) {
        return response && response.data ? response.data : {};
      }

      function responseMessage(error) {
        return error?.response?.data?.message || error?.message || '请求处理失败';
      }

      function showMessage(message, type) {
        panel.find('[data-field="message"]')
          .removeClass('alert-info alert-success alert-danger alert-warning')
          .addClass('alert-' + (type || 'info'))
          .text(message);
      }

      function taskStatusLabel(status) {
        return {
          queued: '排队中',
          running: '执行中',
          succeeded: '已完成',
          failed: '失败',
        }[status] || status || '-';
      }

      function actionLabel(action) {
        return action === 'sync' ? '增量同步' : '全量索引';
      }

      function taskIsActive(task) {
        return task && ['queued', 'running'].includes(task.status);
      }

      function stopPolling() {
        if (state.pollingTimer) {
          window.clearInterval(state.pollingTimer);
          state.pollingTimer = null;
        }
      }

      function renderTask(task) {
        state.task = task || null;
        const alert = panel.find('[data-field="task"]');
        if (!task) {
          alert.addClass('d-none');
          return;
        }

        const active = taskIsActive(task);
        alert
          .removeClass('d-none alert-info alert-success alert-danger')
          .addClass(task.status === 'failed' ? 'alert-danger' : (task.status === 'succeeded' ? 'alert-success' : 'alert-info'));
        alert.find('[data-field="task-status"]').text(actionLabel(task.action) + '：' + taskStatusLabel(task.status));
        alert.find('[data-field="task-progress"]').text(Number(task.progress || 0) + '%');
        alert.find('[data-field="task-message"]').text(task.message || '');
        alert.find('[data-field="task-bar"]').css('width', Number(task.progress || 0) + '%');
        alert.find('[data-action="cancel-task"]').toggleClass('d-none', !active);

        const error = alert.find('[data-field="task-error"]');
        if (task.error) {
          error.removeClass('d-none').text(task.error);
        } else {
          error.addClass('d-none').text('');
        }

        panel.find('[data-action="rebuild"], [data-action="sync"]').prop('disabled', active);
      }

      function startPolling(task) {
        renderTask(task);
        stopPolling();
        if (!taskIsActive(task)) {
          return;
        }

        state.pollingTimer = window.setInterval(function () {
          const url = endpoints.task.replace('__TASK__', encodeURIComponent(task.id));
          $http.get(url, null, {hload: false}).then(function (response) {
            const next = responseData(response);
            renderTask(next);
            if (!taskIsActive(next)) {
              stopPolling();
              showMessage(next.message || '索引任务已结束', next.status === 'failed' ? 'danger' : 'success');
              load(true);
            }
          }).catch(function (error) {
            stopPolling();
            showMessage(responseMessage(error), 'danger');
          });
        }, 3000);
      }

      // 未知文档数用占位符表示，避免把 null 显示成 0 误导管理员以为索引为空。
      function formatCount(value) {
        return value === null || value === undefined ? '<span class="text-secondary">未知</span>' : Number(value).toLocaleString();
      }

      function renderCatalogs(data) {
        const container = panel.find('[data-field="catalogs"]');
        const blocks = (data.catalogs || []).map(function (catalog) {
          const rows = (catalog.locales || []).map(function (item) {
            const missing = !item.active_index;
            const indexes = (item.indexes || []).map(function (index) {
              const active = Boolean(index.active);
              const label = active ? '<span class="badge bg-success-subtle text-success">当前使用</span>' : '<span class="badge bg-light text-secondary">未使用</span>';
              const remove = !active && index.exists ? `<button type="button" class="btn btn-link btn-sm text-danger p-0 ms-2" data-action="delete-index" data-index="${escapeHtml(index.uid)}" title="删除索引" aria-label="删除索引"><i class="bi bi-trash"></i></button>` : '';
              return `<div class="d-flex align-items-center gap-2 mb-1"><code class="text-break">${escapeHtml(index.uid)}</code>${label}<span class="text-secondary small">文档 ${formatCount(index.documents)}</span>${remove}</div>`;
            }).join('');
            return `
              <tr>
                <td>${escapeHtml(item.locale)}</td>
                <td class="text-break">${missing ? '<span class="text-danger">尚未建立索引</span>' : escapeHtml(item.active_index)}</td>
                <td class="text-break">${indexes || '<span class="text-secondary">暂无索引</span>'}</td>
                <td>${missing ? '-' : formatCount(item.documents)}</td>
                <td>${escapeHtml(item.last_updated_at || '-')}</td>
              </tr>`;
          }).join('');

          return `
            <div class="border rounded p-3 mb-3">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="fw-semibold">${escapeHtml(catalog.label)}</span>
                <span class="badge bg-light text-secondary">连接 ${escapeHtml(catalog.connection)}</span>
                <span class="text-secondary small">数据库可索引商品：${formatCount(catalog.source_count)}</span>
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr class="text-secondary">
                      <th style="width: 100px">语言</th>
                      <th>活动索引</th>
                      <th>全部索引</th>
                      <th style="width: 120px">已索引文档</th>
                      <th style="width: 180px">最后同步</th>
                    </tr>
                  </thead>
                  <tbody>${rows}</tbody>
                </table>
              </div>
            </div>`;
        });

        container.html(blocks.join('') || '<div class="text-secondary">没有可用的商品库</div>');
      }

      function renderOptions(data) {
        const catalogSelect = panel.find('#meilisearch-catalog');
        const previousCatalog = catalogSelect.val();
        catalogSelect.empty();
        (data.catalogs || []).forEach(function (catalog) {
          catalogSelect.append($('<option>').val(catalog.catalog).text(catalog.label));
        });
        if (previousCatalog) {
          catalogSelect.val(previousCatalog);
        }

        const localeSelect = panel.find('#meilisearch-locales');
        const previousLocales = localeSelect.val() || [];
        localeSelect.empty();
        (data.locales || []).forEach(function (locale) {
          localeSelect.append($('<option>').val(locale).text(locale));
        });
        localeSelect.val(previousLocales);
        localeSelect.attr('size', Math.min(Math.max((data.locales || []).length, 1), 4));
      }

      function render(data) {
        state.catalogs = data.catalogs || [];

        panel.find('[data-field="health"]')
          .text(data.health && data.health.ok ? '连接正常' : '连接失败')
          .removeClass('text-success text-danger')
          .addClass(data.health && data.health.ok ? 'text-success' : 'text-danger');
        panel.find('[data-field="host"]').text(data.host || '-');
        panel.find('[data-field="search-enabled"]')
          .text(data.search_enabled ? '已开启' : '未开启')
          .removeClass('text-success text-secondary')
          .addClass(data.search_enabled ? 'text-success' : 'text-secondary');
        panel.find('[data-field="mysql-fallback"]').text(data.mysql_fallback ? 'MySQL 回退已启用' : 'MySQL 回退已关闭');
        panel.find('[data-field="cyber-cloak"]').text(data.cyber_cloak ? '已启用，按商品库隔离索引' : '未启用，仅索引默认商品库');
        panel.find('[data-field="locale-count"]').text((data.locales || []).length);

        renderOptions(data);
        renderCatalogs(data);

        if (data.health && !data.health.ok) {
          showMessage('Meilisearch 连接失败：' + (data.health.message || '未知错误'), 'danger');
        }
      }

      function load(silent) {
        return $http.get(endpoints.status, null, {hload: !silent}).then(function (response) {
          const data = responseData(response);
          render(data);
          if (taskIsActive(data.task)) {
            startPolling(data.task);
          } else {
            renderTask(data.task);
          }
          if (!silent && data.health && data.health.ok) {
            showMessage('索引状态已更新', 'info');
          }
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      }

      function submit(url, confirmText) {
        if (confirmText && !window.confirm(confirmText)) {
          return;
        }

        const payload = {
          catalog: panel.find('#meilisearch-catalog').val(),
          locales: panel.find('#meilisearch-locales').val() || [],
        };

        $http.post(url, payload, {hload: true}).then(function (response) {
          const task = responseData(response);
          if (!task || !task.id) {
            throw new Error('任务提交成功但未返回任务编号');
          }
          showMessage('任务已提交，页面会自动刷新执行状态', 'info');
          startPolling(task);
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      }

      panel.on('click', '[data-action="refresh"]', function () {
        load(false);
      });

      panel.on('click', '[data-action="rebuild"]', function () {
        submit(endpoints.rebuild, '将从数据库读取全部商品重建索引，期间前台继续使用旧索引，确认执行？');
      });

      panel.on('click', '[data-action="sync"]', function () {
        submit(endpoints.sync, null);
      });

      panel.on('click', '[data-action="delete-index"]', function () {
        const button = $(this);
        const index = button.attr('data-index');
        if (!index || !window.confirm('确认删除未使用的索引「' + index + '」？删除后不可恢复。')) {
          return;
        }

        button.prop('disabled', true);
        $http.post(endpoints.deleteIndex, {index: index}, {hload: true}).then(function (response) {
          showMessage('索引已删除', 'success');
          return load(true);
        }).catch(function (error) {
          button.prop('disabled', false);
          showMessage(responseMessage(error), 'danger');
        });
      });

      panel.on('click', '[data-action="cancel-task"]', function () {
        if (!state.task || !window.confirm('终止后任务锁会被释放，若队列仍在执行可能造成索引不一致，确认终止？')) {
          return;
        }

        const url = endpoints.cancelTask.replace('__TASK__', encodeURIComponent(state.task.id));
        $http.post(url, {}, {hload: true}).then(function (response) {
          stopPolling();
          renderTask(responseData(response));
          showMessage('索引任务已终止', 'warning');
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      });

      load(false);
    });
  </script>
@endpush
