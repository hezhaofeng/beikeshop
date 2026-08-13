<section id="bestseller-selector-panel" class="mt-5 pt-4 border-top" aria-live="polite">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
    <div>
      <h5 class="mb-1">首页商品模块批量选品</h5>
      <p class="text-secondary mb-0">分别配置首页中的热卖、商品和选项卡商品模块；切换分类不会清空当前模块已勾选商品。</p>
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="refresh" title="刷新当前热卖选品配置" aria-label="刷新当前热卖选品配置">
      <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
    </button>
  </div>

  <div class="alert alert-info py-2 mb-3" data-field="message">正在读取热卖商品配置...</div>

  <div class="row g-3">
    <div class="col-lg-4">
      <label class="form-label" for="bestseller-selector-module">首页商品模块</label>
      <select id="bestseller-selector-module" class="form-select" data-field="module">
        <option value="">请选择模块</option>
      </select>
      <div class="form-text">每个首页商品模块独立保存；同类型模块不会互相覆盖。</div>
    </div>
    <div class="col-lg-4 d-none" data-field="tab-wrap">
      <label class="form-label" for="bestseller-selector-tab">选项卡</label>
      <select id="bestseller-selector-tab" class="form-select" data-field="tab">
        <option value="">请选择选项卡</option>
      </select>
    </div>
    <div class="col-lg-4">
      <label class="form-label" for="bestseller-selector-category">分类</label>
      <div class="bestseller-selector-category-picker position-relative">
        <input id="bestseller-selector-category" class="form-control" type="search" data-field="category-search" placeholder="输入分类名称或路径筛选" autocomplete="off" aria-autocomplete="list" aria-expanded="false">
        <input type="hidden" data-field="category">
        <div class="list-group bestseller-selector-category-options d-none" data-field="category-options" role="listbox"></div>
      </div>
      <div class="form-text">输入分类名称或完整路径快速筛选；一级和二级分类会包含其下所有子分类商品。</div>
    </div>
    <div class="col-lg-4">
      <label class="form-label" for="bestseller-selector-mode">首页数据来源</label>
      <select id="bestseller-selector-mode" class="form-select" data-field="mode">
        <option value="auto">自动：使用模块原配置</option>
        <option value="manual">手动：使用本页已选商品</option>
      </select>
      <div class="form-text">手动模式按选中顺序展示；自动模式保留热卖模块销量逻辑或其他模块原有商品配置。</div>
    </div>
    <div class="col-lg-4 d-flex align-items-end">
      <button type="button" class="btn btn-primary w-100" data-action="save">
        <i class="bi bi-save me-1" aria-hidden="true"></i>统一保存（<span data-field="selected-count">0</span>）
      </button>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-xl-7">
      <div class="border rounded h-100">
        <div class="d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-bottom bg-light">
          <strong class="small">分类商品</strong>
          <span class="text-secondary small" data-field="product-summary">请选择分类</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th class="text-center" style="width: 56px">选择</th>
                <th>商品</th>
                <th class="text-end">价格</th>
                <th class="text-end">创建时间</th>
              </tr>
            </thead>
            <tbody data-field="product-items">
              <tr><td colspan="4" class="text-center text-secondary py-4">请选择分类</td></tr>
            </tbody>
          </table>
        </div>
        <div class="d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-top" data-field="pagination"></div>
      </div>
    </div>
    <div class="col-xl-5">
      <div class="border rounded h-100">
        <div class="d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-bottom bg-light">
          <strong class="small">已选商品</strong>
          <button type="button" class="btn btn-link btn-sm p-0" data-action="clear" title="清空本次已选商品">清空</button>
        </div>
        <ol class="list-group list-group-numbered list-group-flush" data-field="selected-items">
          <li class="list-group-item text-secondary">暂未选择商品</li>
        </ol>
      </div>
    </div>
  </div>
</section>

@push('footer')
  <style>
    #bestseller-selector-panel .bestseller-selector-image { width: 42px; height: 42px; object-fit: cover; }
    #bestseller-selector-panel .bestseller-selector-name { max-width: 260px; }
    #bestseller-selector-panel .bestseller-selector-date { white-space: nowrap; }
    #bestseller-selector-panel .bestseller-selector-category-options { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: .375rem; box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .12); left: 0; max-height: 280px; overflow-y: auto; position: absolute; right: 0; top: calc(100% + 4px); z-index: 1050; }
    #bestseller-selector-panel .bestseller-selector-category-option { cursor: pointer; }
    #bestseller-selector-panel .bestseller-selector-category-option.is-active { background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); }
  </style>

  <script>
    $(function () {
      const panel = $('#bestseller-selector-panel');
      if (!panel.length) return;

      const endpoints = {
        state: @json(admin_route('bestseller_selector.state')),
        categories: @json(admin_route('bestseller_selector.categories')),
        products: @json(admin_route('bestseller_selector.products')),
        save: @json(admin_route('bestseller_selector.save')),
      };
      const state = {
        categories: [],
        homepageModules: [],
        moduleConfigs: {},
        activeModuleId: '',
        activeTabIndex: '',
        categoryId: '',
        page: 1,
        pagination: null,
        productItems: [],
        selectedProducts: {},
        categoryMatches: [],
        categoryMatchIndex: -1,
      };

      function dataOf(response) {
        return response && response.data ? response.data : {};
      }

      function errorMessage(error) {
        return error?.response?.data?.message || error?.message || '请求处理失败';
      }

      function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
      }

      function message(text, type) {
        panel.find('[data-field="message"]')
          .removeClass('alert-info alert-success alert-danger alert-warning')
          .addClass('alert-' + (type || 'info')).text(text);
      }

      function selected(id) {
        return currentIds().includes(Number(id));
      }

      function activeModule() {
        return state.homepageModules.find(function (module) { return String(module.id) === String(state.activeModuleId); }) || null;
      }

      function ensureModuleConfig(moduleId) {
        if (!state.moduleConfigs[moduleId]) state.moduleConfigs[moduleId] = { mode: 'auto', product_ids: [] };
        return state.moduleConfigs[moduleId];
      }

      function currentConfig() {
        const module = activeModule();
        if (!module) return { mode: 'auto', product_ids: [] };
        const config = ensureModuleConfig(module.id);
        if (module.code === 'tab_product') {
          config.tabs = config.tabs || {};
          config.tabs[String(state.activeTabIndex)] = config.tabs[String(state.activeTabIndex)] || { mode: 'auto', product_ids: [] };
          return config.tabs[String(state.activeTabIndex)];
        }
        return config;
      }

      function currentIds() {
        return (currentConfig().product_ids || []).map(Number);
      }

      function setCurrentIds(ids) {
        currentConfig().product_ids = Array.from(new Set((ids || []).map(Number).filter(function (id) { return id > 0; })));
      }

      function payloadModules() {
        const payload = {};
        Object.keys(state.moduleConfigs || {}).forEach(function (moduleId) {
          const source = state.moduleConfigs[moduleId] || {};
          const module = state.homepageModules.find(function (item) { return String(item.id) === String(moduleId); });
          if (!module) return;

          const config = {
            mode: source.mode === 'manual' ? 'manual' : 'auto',
            product_ids: Array.from(new Set((source.product_ids || []).map(Number).filter(function (id) { return id > 0; }))),
          };
          if (module.code === 'tab_product') {
            config.tabs = {};
            Object.keys(source.tabs || {}).forEach(function (tabIndex) {
              const tab = source.tabs[tabIndex] || {};
              const ids = Array.from(new Set((tab.product_ids || []).map(Number).filter(function (id) { return id > 0; })));
              config.tabs[String(tabIndex)] = {
                mode: tab.mode === 'manual' || (tab.mode == null && ids.length) ? 'manual' : 'auto',
                product_ids: ids,
              };
            });
          }
          payload[String(moduleId)] = config;
        });

        return payload;
      }

      function renderModules() {
        const select = panel.find('[data-field="module"]');
        select.find('option:not(:first)').remove();
        state.homepageModules.forEach(function (module) {
          select.append($('<option>', { value: module.id, text: module.title + '（' + module.code + '）' }));
        });
        select.val(state.activeModuleId || '');
      }

      function renderTabs() {
        const module = activeModule();
        const wrap = panel.find('[data-field="tab-wrap"]');
        const select = panel.find('[data-field="tab"]');
        select.find('option:not(:first)').remove();
        if (!module || module.code !== 'tab_product') {
          wrap.addClass('d-none');
          state.activeTabIndex = '';
          return;
        }
        wrap.removeClass('d-none');
        (module.tabs || []).forEach(function (tab) {
          select.append($('<option>', { value: tab.index, text: tab.title }));
        });
        if (state.activeTabIndex === '' && module.tabs && module.tabs.length) state.activeTabIndex = String(module.tabs[0].index);
        select.val(state.activeTabIndex);
      }

      function renderMode() {
        panel.find('[data-field="mode"]').val(currentConfig().mode || 'auto');
      }

      function renderCategories() {
        renderCategoryMatches('', false);
      }

      // 分类路径由后端一次返回，输入时仅在内存中筛选，不产生额外请求。
      function renderCategoryMatches(keyword, open) {
        const normalizedKeyword = String(keyword || '').trim().toLocaleLowerCase();
        state.categoryMatches = state.categories.filter(function (category) {
          return !normalizedKeyword || String(category.name || '').toLocaleLowerCase().includes(normalizedKeyword);
        });
        state.categoryMatchIndex = state.categoryMatches.length ? 0 : -1;

        const options = panel.find('[data-field="category-options"]');
        options.empty();
        if (state.categoryMatches.length === 0) {
          options.append('<div class="list-group-item text-secondary small">没有匹配的分类</div>');
        } else {
          state.categoryMatches.forEach(function (category, index) {
            options.append('<button type="button" class="list-group-item list-group-item-action bestseller-selector-category-option' + (index === state.categoryMatchIndex ? ' is-active' : '') + '" data-role="category-option" data-id="' + Number(category.id) + '" role="option">' + escapeHtml(category.name) + '</button>');
          });
        }
        options.toggleClass('d-none', open === false);
        panel.find('[data-field="category-search"]').attr('aria-expanded', open === false ? 'false' : 'true');
      }

      function closeCategoryMatches() {
        panel.find('[data-field="category-options"]').addClass('d-none');
        panel.find('[data-field="category-search"]').attr('aria-expanded', 'false');
      }

      function chooseCategory(categoryId) {
        const category = state.categories.find(function (item) { return Number(item.id) === Number(categoryId); });
        if (!category) return;

        state.categoryId = String(category.id);
        panel.find('[data-field="category"]').val(state.categoryId);
        panel.find('[data-field="category-search"]').val(category.name);
        closeCategoryMatches();
        state.pagination = null;
        loadProducts(1);
      }

      function renderSelected() {
        const list = panel.find('[data-field="selected-items"]');
        const ids = currentIds();
        panel.find('[data-field="selected-count"]').text(ids.length);
        list.empty();
        if (ids.length === 0) {
          list.append('<li class="list-group-item text-secondary">暂未选择商品</li>');
          return;
        }

        ids.forEach(function (id) {
          const product = state.selectedProducts[id] || { id: id, name: '商品 #' + id, price_format: '' };
          list.append(
            '<li class="list-group-item d-flex align-items-center gap-2">' +
              '<span class="flex-grow-1 text-truncate">' + escapeHtml(product.name) + '</span>' +
              '<span class="text-secondary small">' + escapeHtml(product.price_format || '') + '</span>' +
              '<button type="button" class="btn btn-outline-danger btn-sm" data-action="remove" data-id="' + Number(id) + '" title="移除商品" aria-label="移除商品"><i class="bi bi-x-lg" aria-hidden="true"></i></button>' +
            '</li>'
          );
        });
      }

      function renderProducts() {
        const body = panel.find('[data-field="product-items"]');
        body.empty();
        if (state.productItems.length === 0) {
          body.append('<tr><td colspan="4" class="text-center text-secondary py-4">该分类暂无可选商品</td></tr>');
          return;
        }

        state.productItems.forEach(function (product) {
          const checked = selected(product.id) ? ' checked' : '';
          const image = product.image_format || product.image || '';
          const imageHtml = image ? '<img class="rounded bestseller-selector-image" src="' + escapeHtml(image) + '" alt="">' : '<div class="rounded bg-light bestseller-selector-image"></div>';
          body.append(
            '<tr>' +
              '<td class="text-center"><input class="form-check-input" type="checkbox" data-role="product" value="' + Number(product.id) + '"' + checked + '></td>' +
              '<td><div class="d-flex align-items-center gap-2">' + imageHtml + '<span class="bestseller-selector-name text-truncate">' + escapeHtml(product.name) + '</span></div></td>' +
              '<td class="text-end text-nowrap">' + escapeHtml(product.price_format || '') + '</td>' +
              '<td class="text-end text-secondary small bestseller-selector-date">' + escapeHtml(product.created_at || '-') + '</td>' +
            '</tr>'
          );
        });
      }

      function renderPagination() {
        const wrap = panel.find('[data-field="pagination"]');
        const page = state.pagination;
        wrap.empty();
        if (!page) return;
        panel.find('[data-field="product-summary"]').text('共 ' + page.total + ' 个，按创建时间从新到旧');
        const previousDisabled = page.current_page <= 1 ? ' disabled' : '';
        const nextDisabled = page.current_page >= page.last_page ? ' disabled' : '';
        wrap.append('<span class="text-secondary small">第 ' + page.current_page + ' / ' + page.last_page + ' 页</span>');
        wrap.append('<div><button type="button" class="btn btn-outline-secondary btn-sm me-1" data-action="page" data-page="' + (page.current_page - 1) + '"' + previousDisabled + '>上一页</button><button type="button" class="btn btn-outline-secondary btn-sm" data-action="page" data-page="' + (page.current_page + 1) + '"' + nextDisabled + '>下一页</button></div>');
      }

      function rememberProducts(products) {
        products.forEach(function (product) {
          state.selectedProducts[Number(product.id)] = product;
        });
      }

      function rememberModuleProducts(modules) {
        Object.keys(modules || {}).forEach(function (moduleId) {
          const module = modules[moduleId] || {};
          rememberProducts(module.products || []);
          Object.keys(module.tabs || {}).forEach(function (tabIndex) {
            rememberProducts((module.tabs[tabIndex] || {}).products || []);
          });
        });
      }

      function loadProducts(page) {
        if (!state.categoryId) return;
        state.page = page || 1;
        panel.find('[data-field="product-items"]').html('<tr><td colspan="4" class="text-center text-secondary py-4">正在读取商品...</td></tr>');
        $http.get(endpoints.products, { category_id: state.categoryId, page: state.page, per_page: 50 }, { hload: true }).then(function (response) {
          const data = dataOf(response);
          state.productItems = data.items || [];
          state.pagination = data.pagination || null;
          rememberProducts(state.productItems);
          renderProducts();
          renderPagination();
        }).catch(function (error) {
          message(errorMessage(error), 'danger');
          state.productItems = [];
          renderProducts();
        });
      }

      function load() {
        return Promise.all([
          $http.get(endpoints.categories, null, { hload: true }),
          $http.get(endpoints.state, null, { hload: true }),
        ]).then(function (responses) {
          state.categories = dataOf(responses[0]) || [];
          const saved = dataOf(responses[1]) || {};
          state.homepageModules = saved.homepage_modules || [];
          state.moduleConfigs = saved.modules || {};
          if (!state.homepageModules.length) {
            message('首页暂未配置商品、热卖或选项卡商品模块', 'warning');
            return;
          }
          state.activeModuleId = String(state.homepageModules[0].id);
          state.activeTabIndex = '';
          state.selectedProducts = {};
          rememberModuleProducts(state.moduleConfigs);
          renderModules();
          renderTabs();
          renderMode();
          renderCategories();
          renderSelected();
          message('热卖商品配置已读取', 'info');
        }).catch(function (error) {
          message(errorMessage(error), 'danger');
        });
      }

      panel.on('focus input', '[data-field="category-search"]', function () {
        renderCategoryMatches($(this).val(), true);
      });

      panel.on('keydown', '[data-field="category-search"]', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          if (!state.categoryMatches.length) return;
          event.preventDefault();
          const direction = event.key === 'ArrowDown' ? 1 : -1;
          state.categoryMatchIndex = (state.categoryMatchIndex + direction + state.categoryMatches.length) % state.categoryMatches.length;
          panel.find('[data-role="category-option"]').removeClass('is-active').eq(state.categoryMatchIndex).addClass('is-active')[0]?.scrollIntoView({ block: 'nearest' });
          return;
        }
        if (event.key === 'Enter' && state.categoryMatchIndex >= 0) {
          event.preventDefault();
          chooseCategory(state.categoryMatches[state.categoryMatchIndex].id);
          return;
        }
        if (event.key === 'Escape') {
          closeCategoryMatches();
        }
      });

      panel.on('click', '[data-role="category-option"]', function () {
        chooseCategory($(this).data('id'));
      });

      panel.on('change', '[data-field="module"]', function () {
        state.activeModuleId = String($(this).val() || '');
        state.activeTabIndex = '';
        renderTabs();
        renderMode();
        state.productItems = [];
        state.pagination = null;
        renderSelected();
        renderProducts();
        renderPagination();
      });

      panel.on('change', '[data-field="tab"]', function () {
        state.activeTabIndex = String($(this).val() || '');
        renderMode();
        state.productItems = [];
        state.pagination = null;
        renderSelected();
        renderProducts();
        renderPagination();
      });

      panel.on('change', '[data-field="mode"]', function () {
        currentConfig().mode = $(this).val() === 'manual' ? 'manual' : 'auto';
      });

      $(document).on('mousedown.bestsellerSelectorCategory', function (event) {
        if (!$(event.target).closest('#bestseller-selector-panel .bestseller-selector-category-picker').length) {
          closeCategoryMatches();
        }
      });

      panel.on('change', '[data-role="product"]', function () {
        const id = Number($(this).val());
        const product = state.productItems.find(function (item) { return Number(item.id) === id; });
        if (this.checked) {
          const ids = currentIds();
          if (!selected(id)) ids.push(id);
          setCurrentIds(ids);
          // 勾选商品即表示当前模块/选项卡使用人工选品，避免保存后仍停留在自动模式。
          currentConfig().mode = 'manual';
          if (product) state.selectedProducts[id] = product;
        } else {
          setCurrentIds(currentIds().filter(function (item) { return item !== id; }));
        }
        renderSelected();
      });

      panel.on('click', '[data-action="remove"]', function () {
        const id = Number($(this).data('id'));
        setCurrentIds(currentIds().filter(function (item) { return item !== id; }));
        renderSelected();
        renderProducts();
      });

      panel.on('click', '[data-action="clear"]', function () {
        setCurrentIds([]);
        renderSelected();
        renderProducts();
      });

      panel.on('click', '[data-action="page"]', function () {
        const page = Number($(this).data('page'));
        if (page > 0) loadProducts(page);
      });

      panel.on('click', '[data-action="save"]', function () {
        $http.post(endpoints.save, {
          modules: payloadModules(),
        }, { hload: true }).then(function (response) {
          const saved = dataOf(response) || {};
          state.homepageModules = saved.homepage_modules || state.homepageModules;
          state.moduleConfigs = saved.modules || state.moduleConfigs;
          state.selectedProducts = {};
          rememberModuleProducts(state.moduleConfigs);
          renderModules();
          renderTabs();
          renderMode();
          renderSelected();
          renderProducts();
          message('热卖商品配置已保存', 'success');
        }).catch(function (error) {
          message(errorMessage(error), 'danger');
        });
      });

      panel.on('click', '[data-action="refresh"]', function () {
        load();
      });

      load();
    });
  </script>
@endpush
