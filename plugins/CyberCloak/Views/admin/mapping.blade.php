<section id="cyber-cloak-mapping-panel" class="mt-5 pt-4 border-top">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
    <div>
      <h5 class="mb-1">映射管理</h5>
      <p class="text-secondary mb-0">首页商品直接使用已发布的商品映射；下方异常区仅用于处理自动映射未确认的 SKU。</p>
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="refresh">
      <i class="bi bi-arrow-clockwise me-1"></i>刷新状态
    </button>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-2">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">当前发布版本</div>
        <div class="fw-semibold text-break mt-1" data-field="published-version">-</div>
      </div>
    </div>
    <div class="col-6 col-xl-2">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">异常 SKU：待确认 / 冲突</div>
        <div class="fw-semibold mt-1"><span data-field="pending-count">0</span> / <span data-field="conflict-count">0</span></div>
      </div>
    </div>
    <div class="col-6 col-xl-2">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">商品链接数</div>
        <div class="fw-semibold mt-1" data-field="product-routes">0</div>
      </div>
    </div>
    <div class="col-6 col-xl-2">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">分类链接数</div>
        <div class="fw-semibold mt-1" data-field="category-routes">0</div>
      </div>
    </div>
    <div class="col-6 col-xl-2">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">首页转换模块 / 链接</div>
        <div class="fw-semibold mt-1"><span data-field="home-modules">0</span> / <span data-field="banner-count">0</span></div>
      </div>
    </div>
    <div class="col-6 col-xl-2">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">Banner 已映射 / 未映射</div>
        <div class="fw-semibold mt-1"><span data-field="banner-mapped">0</span> / <span data-field="banner-unmapped">0</span></div>
      </div>
    </div>
    <div class="col-6 col-xl-2">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">Banner 图片已配置 / 待配置</div>
        <div class="fw-semibold mt-1"><span data-field="banner-image-configured">0</span> / <span data-field="banner-image-pending">0</span></div>
      </div>
    </div>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <label for="cyber-cloak-mapping-mode" class="mb-0 text-secondary">匹配方式</label>
    <select id="cyber-cloak-mapping-mode" class="form-select form-select-sm" style="width: 150px">
      <option value="exact">精确匹配</option>
      <option value="candidate">候选匹配</option>
      <option value="random">随机映射</option>
    </select>
    <button type="button" class="btn btn-primary btn-sm" data-action="product">
      <i class="bi bi-box-seam me-1"></i>生成商品映射
    </button>
    <button type="button" class="btn btn-outline-success btn-sm" data-action="publish" disabled>
      <i class="bi bi-check2-circle me-1"></i>发布当前草稿
    </button>
    <button type="button" class="btn btn-outline-primary btn-sm" data-action="category">
      <i class="bi bi-diagram-3 me-1"></i>分类链接同步
    </button>
    <button type="button" class="btn btn-outline-primary btn-sm" data-action="banner">
      <i class="bi bi-images me-1"></i>Banner 链接检查
    </button>
    <button type="button" class="btn btn-outline-primary btn-sm" data-action="banner-images-sync">
      <i class="bi bi-image me-1"></i>扫描 Banner 图片
    </button>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cache">
      <i class="bi bi-trash3 me-1"></i>清除缓存
    </button>
    <select id="cyber-cloak-mapping-version" class="form-select form-select-sm ms-lg-2" style="min-width: 230px">
      <option value="">暂无映射版本</option>
    </select>
  </div>

  <div class="text-secondary small mb-3">商品和分类链接复用已发布路由；Banner 图片扫描后，可上传 Cloak 图片，或填写已有图片路径和 JSON。</div>

  <div class="alert py-2 mb-3 d-none" data-field="mapping-task">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <strong data-field="mapping-task-status">商品映射任务</strong>
      <span data-field="mapping-task-progress">0%</span>
      <span class="text-secondary" data-field="mapping-task-message"></span>
    </div>
    <div class="small mt-1 d-none" data-field="mapping-task-error"></div>
  </div>

  <div class="alert alert-info py-2 mb-3" data-field="message">
    正在读取映射状态...
  </div>

  <details class="border rounded mb-3" data-field="banner-image-panel">
    <summary class="px-3 py-2 bg-light fw-semibold cursor-pointer">
      Banner 图片映射（已配置 <span data-field="banner-image-configured-inline">0</span> / 待配置 <span data-field="banner-image-pending-inline">0</span>）
    </summary>
    <div class="px-3 py-2 border-top text-secondary small">
      真实图片只作为来源标识保存；展示模式使用这里上传或填写的 Cloak 图片资源。上传复用 BeikeShop 后台图片存储，普通路径会自动复用真实图片的多语言结构，复杂结构可直接填写 JSON。
    </div>
    <div class="table-responsive border-top">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="min-width: 220px">真实 Banner</th>
            <th style="min-width: 300px">Cloak 图片</th>
            <th>状态</th>
            <th class="text-end">操作</th>
          </tr>
        </thead>
        <tbody data-field="banner-image-items">
          <tr><td colspan="4" class="text-center text-secondary py-4">请先点击“扫描 Banner 图片”</td></tr>
        </tbody>
      </table>
    </div>
  </details>

  <details class="border rounded d-none" data-field="exception-panel">
    <summary class="px-3 py-2 bg-light fw-semibold cursor-pointer">
      异常 SKU 人工处理（<span data-field="exception-count">0</span>）
    </summary>
    <div class="px-3 py-2 border-top text-secondary small">
      自动映射无法确定展示 SKU 时才需要在这里确认；已发布商品映射会被首页直接使用。
    </div>
    <div class="table-responsive border-top">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>真实 SKU</th>
            <th>状态</th>
            <th>展示 SKU 候选</th>
            <th style="min-width: 180px">确认展示 SKU ID</th>
            <th class="text-end">操作</th>
          </tr>
        </thead>
        <tbody data-field="items">
          <tr>
            <td colspan="5" class="text-center text-secondary py-4">正在读取...</td>
          </tr>
        </tbody>
      </table>
    </div>
  </details>
</section>

@push('footer')
  <style>
    #cyber-cloak-mapping-panel .cc-real-sku {
      min-width: 180px;
    }

    #cyber-cloak-mapping-panel .cc-candidate {
      min-width: 220px;
    }

    #cyber-cloak-mapping-panel summary {
      cursor: pointer;
    }
  </style>

  <script>
    $(function () {
      const panel = $('#cyber-cloak-mapping-panel');
      const state = {
        selectedVersion: '',
        publishedVersion: '',
        selectedStatus: '',
        selectedSkuCount: 0,
        banner: {},
        bannerImages: {},
        mappingTask: null,
        pollingTimer: null,
      };
      const endpoints = {
        status: @json(admin_route('cyber_cloak.mappings.status')),
        task: @json(admin_route('cyber_cloak.mappings.tasks.show', ['task' => '__TASK__'])),
        publicSkus: @json(admin_route('cyber_cloak.mappings.public_skus')),
        product: @json(admin_route('cyber_cloak.mappings.product')),
        category: @json(admin_route('cyber_cloak.mappings.category')),
        banner: @json(admin_route('cyber_cloak.mappings.banner')),
        bannerImagesSync: @json(admin_route('cyber_cloak.mappings.banner_images.sync')),
        bannerImageUpdate: @json(admin_route('cyber_cloak.mappings.banner_images.update', ['mapping' => '__MAPPING__'])),
        fileUpload: @json(admin_route('file.store')),
        cache: @json(admin_route('cyber_cloak.mappings.cache')),
        confirm: @json(admin_route('cyber_cloak.mappings.confirm')),
      };

      // 统一转义数据库内容，避免 SKU 文本被当作 HTML 插入页面。
      function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
      }

      // 后台接口统一返回 json_success/json_fail 格式，错误信息在这里集中提取。
      function responseData(response) {
        return response && response.data ? response.data : {};
      }

      // 提取接口或网络异常中的首条可读错误信息。
      function responseMessage(error) {
        return error?.response?.data?.message || error?.message || '请求处理失败';
      }

      // 在面板顶部显示当前操作结果，避免依赖浏览器原生弹窗。
      function showMessage(message, type) {
        const alert = panel.find('[data-field="message"]');
        alert.removeClass('alert-info alert-success alert-danger alert-warning').addClass('alert-' + (type || 'info')).text(message);
      }

      // 将数据库中的映射状态转换为后台用户可读的中文标签。
      function statusLabel(status) {
        return {
          draft: '草稿',
          published: '已发布',
          archived: '已归档',
          pending: '待确认',
          conflict: '冲突',
          active: '已启用',
          disabled: '已停用',
        }[status] || status || '-';
      }

      function taskStatusLabel(status) {
        return {
          queued: '排队中',
          running: '执行中',
          succeeded: '已完成',
          failed: '失败',
        }[status] || status || '-';
      }

      function taskUrl(taskId) {
        return endpoints.task.replace('__TASK__', encodeURIComponent(taskId));
      }

      function taskIsActive(task) {
        return task && ['queued', 'running'].includes(task.status);
      }

      function stopTaskPolling() {
        if (state.pollingTimer) {
          window.clearInterval(state.pollingTimer);
          state.pollingTimer = null;
        }
      }

      // 显示持久化任务状态；映射运行时禁止重复提交，避免同时发布多个版本。
      function renderTask(task) {
        state.mappingTask = task || null;
        const alert = panel.find('[data-field="mapping-task"]');
        if (!task) {
          alert.addClass('d-none');
          return;
        }

        const active = taskIsActive(task);
        alert
          .removeClass('d-none alert-info alert-success alert-danger alert-warning')
          .addClass(task.status === 'failed' ? 'alert-danger' : (task.status === 'succeeded' ? 'alert-success' : 'alert-info'));
        alert.find('[data-field="mapping-task-status"]').text('商品映射任务：' + taskStatusLabel(task.status));
        alert.find('[data-field="mapping-task-progress"]').text(Number(task.progress || 0) + '%');
        alert.find('[data-field="mapping-task-message"]').text(task.message || '等待状态更新');
        alert.find('[data-field="mapping-task-error"]').toggleClass('d-none', !task.error).text(task.error || '');
        panel.find('[data-action="product"]').prop('disabled', active);
        if (active) {
          panel.find('[data-action="publish"]').prop('disabled', true);
        }
      }

      function completeTask(task) {
        stopTaskPolling();
        const result = task.result || {};
        const version = result.version || state.selectedVersion;
        load(version, true).then(function () {
          if (task.status === 'succeeded') {
            showMessage(task.message || '商品映射任务已完成', result.published ? 'success' : 'warning');
          } else {
            showMessage(task.error || '商品映射任务失败，请查看任务状态和 Laravel 日志', 'danger');
          }
        }).catch(function () {
          // 任务最终状态已经显示，不再覆盖为状态页刷新失败信息。
        });
      }

      function pollTask() {
        const task = state.mappingTask;
        if (!task || !taskIsActive(task)) {
          stopTaskPolling();
          return;
        }

        $http.get(taskUrl(task.id), null, {hload: false}).then(function (response) {
          const nextTask = responseData(response);
          renderTask(nextTask);
          if (!taskIsActive(nextTask)) {
            completeTask(nextTask);
          }
        }).catch(function (error) {
          stopTaskPolling();
          showMessage('读取商品映射任务状态失败：' + responseMessage(error), 'danger');
        });
      }

      function startTaskPolling(task) {
        renderTask(task);
        if (!taskIsActive(task)) {
          return;
        }

        stopTaskPolling();
        pollTask();
        state.pollingTimer = window.setInterval(pollTask, 2000);
      }

      // 重绘版本下拉框，并保持后台当前选中的版本。
      function renderVersions(data) {
        const select = panel.find('#cyber-cloak-mapping-version');
        select.empty();
        if (!data.versions || data.versions.length === 0) {
          state.selectedVersion = '';
          state.selectedStatus = '';
          state.selectedSkuCount = 0;
          panel.find('[data-action="publish"]').prop('disabled', true);
          select.append('<option value="">暂无映射版本</option>');
          return;
        }
        data.versions.forEach(function (item) {
          const label = escapeHtml(item.version + ' · ' + statusLabel(item.status));
          select.append('<option value="' + escapeHtml(item.version) + '">' + label + '</option>');
        });
        state.selectedVersion = data.selected_version || data.versions[0].version;
        select.val(state.selectedVersion);
      }

      // 重绘当前版本、待处理数量、稳定链接和首页转换状态。
      function renderSummary(data) {
        const selected = data.selected || {};
        state.selectedStatus = selected.status || '';
        state.selectedSkuCount = Number(selected.sku_count || 0);
        state.publishedVersion = data.published_version || '';
        panel.find('[data-field="published-version"]').text(state.publishedVersion || '暂无');
        panel.find('[data-field="pending-count"]').text(selected.pending_count || 0);
        panel.find('[data-field="conflict-count"]').text(selected.conflict_count || 0);
        const exceptionCount = Number(selected.pending_count || 0) + Number(selected.conflict_count || 0);
        const canPublish = state.selectedStatus === 'draft' && state.selectedSkuCount > 0 && exceptionCount === 0;
        panel.find('[data-action="publish"]').prop('disabled', !canPublish);
        panel.find('[data-field="exception-count"]').text(exceptionCount);
        panel.find('[data-field="exception-panel"]').toggleClass('d-none', exceptionCount === 0);
        panel.find('[data-field="product-routes"]').text(data.route_stats?.product || 0);
        panel.find('[data-field="category-routes"]').text(data.route_stats?.category || 0);
        state.banner = data.home || state.banner || {};
        panel.find('[data-field="home-modules"]').text(state.banner?.module_count || 0);
        panel.find('[data-field="banner-count"]').text(state.banner?.banner_count || 0);
        panel.find('[data-field="banner-mapped"]').text(state.banner?.mapped || 0);
        panel.find('[data-field="banner-unmapped"]').text(state.banner?.unmapped || 0);
        renderBannerImages(data.banner_images || state.bannerImages || {});
      }

      // 重绘真实 Banner 与 Cloak 图片目标，路径输入保留为可复制的纯文本或 JSON。
      function renderBannerImages(data) {
        state.bannerImages = data || {};
        const configured = Number(state.bannerImages.configured || 0);
        const pending = Number(state.bannerImages.pending || 0);
        panel.find('[data-field="banner-image-configured"], [data-field="banner-image-configured-inline"]').text(configured);
        panel.find('[data-field="banner-image-pending"], [data-field="banner-image-pending-inline"]').text(pending);
        const body = panel.find('[data-field="banner-image-items"]');
        body.empty();
        if (!state.bannerImages.items || state.bannerImages.items.length === 0) {
          body.append('<tr><td colspan="4" class="text-center text-secondary py-4">当前首页没有可扫描的图片字段</td></tr>');
          return;
        }
        state.bannerImages.items.forEach(function (item) {
          const preview = item.real_preview
            ? '<img src="' + escapeHtml(item.real_preview) + '" class="img-thumbnail me-2" style="width:72px;height:48px;object-fit:cover">'
            : '<span class="text-secondary me-2">无预览</span>';
          const input = escapeHtml(item.public_input || '');
          const status = item.status === 'disabled' ? 'disabled' : (item.configured ? 'active' : 'pending');
          const disabled = item.id ? '' : ' disabled';
          const uploadControl = item.id
            ? '<label class="btn btn-outline-secondary btn-sm mb-0" title="上传 Cloak 图片"><i class="bi bi-cloud-arrow-up"></i><input type="file" class="d-none" data-role="banner-image-file" accept=".jpg,.jpeg,.png,.gif,.webp"></label>'
            : '<span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true" title="请先扫描 Banner 图片"><i class="bi bi-cloud-arrow-up"></i></span>';
          const publicPreview = item.public_preview
            ? '<a href="' + escapeHtml(item.public_preview) + '" target="_blank" rel="noopener" title="查看 Cloak 图片"><img src="' + escapeHtml(item.public_preview) + '" class="img-thumbnail" style="width:72px;height:48px;object-fit:cover"></a>'
            : '<span class="small text-secondary">尚未配置</span>';
          body.append(
            '<tr data-banner-image-id="' + escapeHtml(item.id || '') + '">' +
              '<td>' + preview + '<span>' + escapeHtml(item.module_code + ' · ' + item.path) + '</span></td>' +
              '<td><div class="d-flex gap-2 align-items-start"><textarea class="form-control form-control-sm" rows="2" data-role="banner-image-input" placeholder="上传图片，或填写已有路径 / JSON"' + disabled + '>' + input + '</textarea>' + uploadControl + '</div><div class="mt-2">' + publicPreview + '</div></td>' +
              '<td><select class="form-select form-select-sm" data-role="banner-image-status"' + disabled + '>' +
                '<option value="active"' + (status === 'active' ? ' selected' : '') + '>已启用</option>' +
                '<option value="disabled"' + (status === 'disabled' ? ' selected' : '') + '>已停用</option>' +
              '</select></td>' +
              '<td class="text-end"><button type="button" class="btn btn-outline-primary btn-sm" data-action="save-banner-image"' + disabled + '><i class="bi bi-check2 me-1"></i>保存</button></td>' +
            '</tr>'
          );
        });
      }

      // 重绘待确认 SKU，并为每一行保留候选选择和展示 SKU 搜索入口。
      function renderItems(data) {
        const body = panel.find('[data-field="items"]');
        body.empty();
        if (!data.items || data.items.length === 0) {
          body.append('<tr><td colspan="5" class="text-center text-secondary py-4">当前版本没有待处理映射</td></tr>');
          return;
        }

        data.items.forEach(function (item) {
          const candidates = (item.candidates || []).map(function (candidate) {
            const title = '#' + candidate.id + ' ' + (candidate.sku || candidate.model || '无 SKU');
            return '<option value="' + escapeHtml(candidate.id) + '">' + escapeHtml(title) + '</option>';
          }).join('');
          const statusClass = item.status === 'conflict' ? 'text-bg-danger' : 'text-bg-warning';
          const realTitle = '#' + item.real_sku_id + ' ' + (item.real_sku || item.real_model || '无 SKU');
          const editable = data.selected?.status === 'draft';
          body.append(
            '<tr data-row-id="' + item.id + '" data-real-sku-id="' + item.real_sku_id + '">' +
              '<td class="cc-real-sku">' + escapeHtml(realTitle) + '<div class="small text-secondary">商品 #' + escapeHtml(item.real_product_id) + '</div></td>' +
              '<td><span class="badge ' + statusClass + '">' + escapeHtml(statusLabel(item.status)) + '</span></td>' +
              '<td>' +
                '<select class="form-select form-select-sm cc-candidate" data-role="candidate">' +
                  '<option value="">选择候选或手动填写</option>' + candidates +
                '</select>' +
                '<div class="input-group input-group-sm mt-1">' +
                  '<input type="text" class="form-control cc-search-keyword" data-role="search-keyword" placeholder="搜索 SKU / 型号">' +
                  '<button type="button" class="btn btn-outline-secondary" data-action="search-public" title="搜索展示 SKU"><i class="bi bi-search"></i></button>' +
                '</div>' +
                '<select class="form-select form-select-sm mt-1 d-none" data-role="search-result"></select>' +
              '</td>' +
              '<td><input type="number" min="1" class="form-control form-control-sm cc-public-id" data-role="public-id" placeholder="展示 SKU ID" ' + (editable ? '' : 'disabled') + '></td>' +
              '<td class="text-end"><button type="button" class="btn btn-outline-primary btn-sm" data-action="confirm-item" ' + (editable ? '' : 'disabled') + '><i class="bi bi-check2 me-1"></i>确认</button></td>' +
            '</tr>'
          );
          if (!editable) {
            body.find('tr:last [data-role="candidate"]').prop('disabled', true);
            body.find('tr:last [data-role="search-keyword"], tr:last [data-action="search-public"], tr:last [data-role="search-result"]').prop('disabled', true);
          }
        });
      }

      // 按接口返回的数据刷新面板的全部可视区域。
      function render(data) {
        renderVersions(data);
        renderSummary(data);
        renderItems(data);
        renderTask(data.task || state.mappingTask);
      }

      // 保存 Banner 扫描结果，让刷新映射版本时不丢失顶部统计。
      function renderBanner(data) {
        state.banner = data || {};
        panel.find('[data-field="home-modules"]').text(state.banner.module_count || 0);
        panel.find('[data-field="banner-count"]').text(state.banner.banner_count || 0);
        panel.find('[data-field="banner-mapped"]').text(state.banner.mapped || 0);
        panel.find('[data-field="banner-unmapped"]').text(state.banner.unmapped || 0);
      }

      // 读取指定映射版本的状态并刷新页面。
      function load(version, silent) {
        const params = version ? {version: version} : null;
        return $http.get(endpoints.status, params, {hload: !silent}).then(function (response) {
          const data = responseData(response);
          render(data);
          if (taskIsActive(data.task)) {
            startTaskPolling(data.task);
          }
          if (!silent) {
            showMessage('映射状态已读取', 'info');
          }
          return data;
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
          throw error;
        });
      }

      panel.on('change', '#cyber-cloak-mapping-version', function () {
        state.selectedVersion = $(this).val() || '';
        load(state.selectedVersion);
      });

      panel.on('change', '[data-role="candidate"]', function () {
        $(this).closest('tr').find('[data-role="public-id"]').val($(this).val());
      });

      panel.on('change', '[data-role="search-result"]', function () {
        $(this).closest('tr').find('[data-role="public-id"]').val($(this).val());
      });

      panel.on('click', '[data-action="search-public"]', function () {
        const row = $(this).closest('tr');
        const keyword = row.find('[data-role="search-keyword"]').val().trim();
        if (!keyword) {
          showMessage('请输入展示 SKU 或型号', 'danger');
          return;
        }
        $http.get(endpoints.publicSkus, {keyword: keyword, limit: 20}, {hload: true}).then(function (response) {
          const items = responseData(response) || [];
          const select = row.find('[data-role="search-result"]');
          select.empty();
          if (items.length === 0) {
            select.addClass('d-none');
            showMessage('没有找到可用的展示 SKU', 'warning');
            return;
          }
          select.append('<option value="">选择搜索结果</option>');
          items.forEach(function (item) {
            const title = '#' + item.id + ' ' + (item.sku || item.model || '无 SKU');
            select.append('<option value="' + escapeHtml(item.id) + '">' + escapeHtml(title) + '</option>');
          });
          select.removeClass('d-none');
          if (items.length === 1) {
            select.val(items[0].id);
            row.find('[data-role="public-id"]').val(items[0].id);
          }
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      });

      panel.on('click', '[data-action="refresh"]', function () {
        load(state.selectedVersion);
      });

      panel.on('click', '[data-action="product"]', function () {
        const mode = panel.find('#cyber-cloak-mapping-mode').val();
        const payload = {mode: mode};
        $http.post(endpoints.product, payload, {hload: true}).then(function (response) {
          const task = responseData(response).task;
          if (!task) {
            throw new Error('商品映射任务提交成功但未返回任务编号');
          }
          showMessage('商品映射任务已提交，页面会自动刷新执行状态', 'info');
          startTaskPolling(task);
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      });

      panel.on('click', '[data-action="publish"]', function () {
        if (state.selectedStatus !== 'draft' || !state.selectedVersion || state.selectedSkuCount === 0) {
          showMessage('请选择一个包含商品和 SKU 映射的草稿版本', 'warning');
          return;
        }
        $http.post(endpoints.product, {version: state.selectedVersion}, {hload: true}).then(function (response) {
          const task = responseData(response).task;
          if (!task) {
            throw new Error('商品映射发布任务提交成功但未返回任务编号');
          }
          showMessage('商品映射发布任务已提交，页面会自动刷新执行状态', 'info');
          startTaskPolling(task);
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      });

      panel.on('click', '[data-action="category"]', function () {
        $http.post(endpoints.category, {}, {hload: true}).then(function (response) {
          const result = responseData(response);
          showMessage('分类映射已完成，分类链接 ' + result.routes + ' 条', 'success');
          return load(state.selectedVersion);
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      });

      panel.on('click', '[data-action="banner"]', function () {
        $http.post(endpoints.banner, {}, {hload: true}).then(function (response) {
          const result = responseData(response);
          renderBanner(result);
          showMessage('Banner 检查完成：已映射 ' + result.mapped + '，未映射 ' + result.unmapped + '，无需映射 ' + result.not_required, result.unmapped ? 'warning' : 'success');
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      });

      panel.on('click', '[data-action="banner-images-sync"]', function () {
        $http.post(endpoints.bannerImagesSync, {}, {hload: true}).then(function (response) {
          const result = responseData(response);
          renderBannerImages(result);
          showMessage('Banner 图片已扫描，请填写 Cloak 图片路径并保存', 'success');
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      });

      // 上传后复用后台图片存储接口，返回的 upload/... 路径会立即保存到当前 Banner 映射。
      panel.on('change', '[data-role="banner-image-file"]', function () {
        const input = this;
        const file = input.files && input.files[0];
        const row = $(input).closest('tr');
        const mappingId = row.data('banner-image-id');
        if (!file || !mappingId) {
          input.value = '';
          showMessage('请先扫描 Banner 图片，再上传 Cloak 图片', 'warning');
          return;
        }

        const formData = new FormData();
        formData.append('file', file, file.name);
        formData.append('type', 'image');
        $(input).prop('disabled', true);
        $http.post(endpoints.fileUpload, formData, {hload: true}).then(function (response) {
          const uploadedPath = responseData(response).value || '';
          if (!uploadedPath) {
            throw new Error('图片上传成功但未返回访问路径');
          }
          row.find('[data-role="banner-image-input"]').val(uploadedPath);
          const url = endpoints.bannerImageUpdate.replace('__MAPPING__', mappingId);

          return $http.post(url, {
            public_image: uploadedPath,
            status: row.find('[data-role="banner-image-status"]').val(),
          }, {hload: true});
        }).then(function (response) {
          renderBannerImages(responseData(response));
          showMessage('Cloak 图片已上传并保存到 Banner 映射', 'success');
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        }).then(function () {
          input.value = '';
          $(input).prop('disabled', false);
        });
      });

      panel.on('click', '[data-action="save-banner-image"]', function () {
        const row = $(this).closest('tr');
        const mappingId = row.data('banner-image-id');
        if (! mappingId) {
          showMessage('请先扫描 Banner 图片并完成插件迁移', 'danger');
          return;
        }
        const url = endpoints.bannerImageUpdate.replace('__MAPPING__', mappingId);
        $http.post(url, {
          public_image: row.find('[data-role="banner-image-input"]').val(),
          status: row.find('[data-role="banner-image-status"]').val(),
        }, {hload: true}).then(function (response) {
          renderBannerImages(responseData(response));
          showMessage('Banner 图片映射已保存', 'success');
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      });

      panel.on('click', '[data-action="cache"]', function () {
        $http.post(endpoints.cache, {}, {hload: true}).then(function (response) {
          showMessage(response.message || '映射及首页装修缓存已清除', 'success');
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      });

      panel.on('click', '[data-action="confirm-item"]', function () {
        const row = $(this).closest('tr');
        const publicSkuId = Number(row.find('[data-role="public-id"]').val());
        if (!publicSkuId || !state.selectedVersion) {
          showMessage('请先选择或填写展示 SKU ID', 'danger');
          return;
        }
        $http.post(endpoints.confirm, {
          version: state.selectedVersion,
          real_sku_id: Number(row.data('real-sku-id')),
          public_sku_id: publicSkuId,
        }, {hload: true}).then(function () {
          showMessage('SKU 映射已确认', 'success');
          return load(state.selectedVersion);
        }).catch(function (error) {
          showMessage(responseMessage(error), 'danger');
        });
      });

      load();

      $(window).on('beforeunload', stopTaskPolling);
    });
  </script>
@endpush
