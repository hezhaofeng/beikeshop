<section id="cyber-cloak-simple-mapping-panel" class="mt-5 pt-4 border-top">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
    <div>
      <h5 class="mb-1">商品与分类映射</h5>
      <p class="text-secondary mb-0">一键补齐当前数据库的启用记录；已配置的真实商品/分类展示目标保持不变。</p>
    </div>
    <span class="text-secondary small" data-field="status">等待操作</span>
  </div>

  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-primary btn-sm" data-action="product">
      <i class="bi bi-box-seam me-1"></i>商品映射一键处理
    </button>
    <button type="button" class="btn btn-outline-primary btn-sm" data-action="category">
      <i class="bi bi-diagram-3 me-1"></i>分类映射一键处理
    </button>
  </div>

  <div class="text-secondary small mt-2">首页商品模块直接使用商品映射，头部分类导航直接使用分类映射，无需单独维护首页映射。</div>
</section>

@push('footer')
<script>
  $(function () {
    const panel = $('#cyber-cloak-simple-mapping-panel');
    const endpoints = {
      product: @json(admin_route('cyber_cloak_simple.mappings.product')),
      category: @json(admin_route('cyber_cloak_simple.mappings.category'))
    };

    // 统一处理一键映射请求，成功后展示新增数量和总映射数量。
    panel.on('click', '[data-action]', function () {
      const action = $(this).data('action');
      const button = $(this);
      button.prop('disabled', true);
      panel.find('[data-field="status"]').text('正在处理...');
      $http.post(endpoints[action], {}, {hload: true}).then(function (response) {
        const data = response.data || response;
        panel.find('[data-field="status"]').text('已处理：新增 ' + (data.added || 0) + '，共 ' + (data.total || 0) + ' 条');
      }).catch(function (error) {
        const message = error?.message || '映射处理失败';
        panel.find('[data-field="status"]').text(message);
      }).finally(function () {
        button.prop('disabled', false);
      });
    });
  });
</script>
@endpush
