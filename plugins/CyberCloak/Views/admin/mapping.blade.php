<section id="cyber-cloak-mapping-panel" class="mt-5 pt-4 border-top">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
    <div>
      <h5 class="mb-1">商品映射与首页链接</h5>
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
        <div class="text-secondary small">首页 Banner 链接</div>
        <div class="fw-semibold mt-1" data-field="banner-count">0</div>
      </div>
    </div>
    <div class="col-6 col-xl-2">
      <div class="border rounded p-3 h-100">
        <div class="text-secondary small">Banner 已映射 / 未映射</div>
        <div class="fw-semibold mt-1"><span data-field="banner-mapped">0</span> / <span data-field="banner-unmapped">0</span></div>
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
      <i class="bi bi-box-seam me-1"></i>商品自动映射
    </button>
    <button type="button" class="btn btn-outline-primary btn-sm" data-action="category">
      <i class="bi bi-diagram-3 me-1"></i>分类链接同步
    </button>
    <button type="button" class="btn btn-outline-primary btn-sm" data-action="banner">
      <i class="bi bi-images me-1"></i>Banner 链接检查
    </button>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cache">
      <i class="bi bi-trash3 me-1"></i>清除缓存
    </button>
    <select id="cyber-cloak-mapping-version" class="form-select form-select-sm ms-lg-2" style="min-width: 230px">
      <option value="">暂无映射版本</option>
    </select>
  </div>

  <div class="text-secondary small mb-3">商品自动映射会在无异常时直接发布并更新商品链接；分类链接同步和 Banner 链接检查复用当前已发布商品映射。</div>

  <div class="alert alert-info py-2 mb-3" data-field="message">
    正在读取映射状态...
  </div>

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
        banner: {},
      };
      const endpoints = {
        status: @json(admin_route('cyber_cloak.mappings.status')),
        publicSkus: @json(admin_route('cyber_cloak.mappings.public_skus')),
        product: @json(admin_route('cyber_cloak.mappings.product')),
        category: @json(admin_route('cyber_cloak.mappings.category')),
        banner: @json(admin_route('cyber_cloak.mappings.banner')),
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
        }[status] || status || '-';
      }

      // 重绘版本下拉框，并保持后台当前选中的版本。
      function renderVersions(data) {
        const select = panel.find('#cyber-cloak-mapping-version');
        select.empty();
        if (!data.versions || data.versions.length === 0) {
          state.selectedVersion = '';
          state.selectedStatus = '';
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

      // 重绘当前版本、待处理数量和两类稳定链接统计。
      function renderSummary(data) {
        const selected = data.selected || {};
        state.selectedStatus = selected.status || '';
        state.publishedVersion = data.published_version || '';
        panel.find('[data-field="published-version"]').text(state.publishedVersion || '暂无');
        panel.find('[data-field="pending-count"]').text(selected.pending_count || 0);
        panel.find('[data-field="conflict-count"]').text(selected.conflict_count || 0);
        const exceptionCount = Number(selected.pending_count || 0) + Number(selected.conflict_count || 0);
        panel.find('[data-field="exception-count"]').text(exceptionCount);
        panel.find('[data-field="exception-panel"]').toggleClass('d-none', exceptionCount === 0);
        panel.find('[data-field="product-routes"]').text(data.route_stats?.product || 0);
        panel.find('[data-field="category-routes"]').text(data.route_stats?.category || 0);
        panel.find('[data-field="banner-count"]').text(state.banner?.banner_count || 0);
        panel.find('[data-field="banner-mapped"]').text(state.banner?.mapped || 0);
        panel.find('[data-field="banner-unmapped"]').text(state.banner?.unmapped || 0);
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
      }

      // 保存 Banner 扫描结果，让刷新映射版本时不丢失顶部统计。
      function renderBanner(data) {
        state.banner = data || {};
        panel.find('[data-field="banner-count"]').text(state.banner.banner_count || 0);
        panel.find('[data-field="banner-mapped"]').text(state.banner.mapped || 0);
        panel.find('[data-field="banner-unmapped"]').text(state.banner.unmapped || 0);
      }

      // 读取指定映射版本的状态并刷新页面。
      function load(version) {
        const params = version ? {version: version} : null;
        return $http.get(endpoints.status, params, {hload: true}).then(function (response) {
          const data = responseData(response);
          render(data);
          showMessage('映射状态已读取', 'info');
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
        if (state.selectedStatus === 'draft' && state.selectedVersion) {
          payload.version = state.selectedVersion;
        }
        $http.post(endpoints.product, payload, {hload: true}).then(function (response) {
          const result = responseData(response);
          if (result.published) {
            const routeCount = result.product_routes?.routes || 0;
            showMessage('商品映射已发布，商品链接 ' + routeCount + ' 条已更新', 'success');
          } else {
            showMessage('映射已处理：已确认 ' + result.confirmed + '，待确认 ' + result.pending + '，冲突 ' + result.conflict + '。请处理下方异常后再次点击商品按钮发布', 'warning');
          }
          return load(result.version);
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
    });
  </script>
@endpush
