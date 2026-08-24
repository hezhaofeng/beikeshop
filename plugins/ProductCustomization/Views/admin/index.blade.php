@extends('admin::layouts.master')

@section('title', '商品定制')
@section('content-area-class', 'w-max-1200')
@section('page-title-back', admin_route('products.index'))

@section('content')
  @if(session('success'))
    <x-admin-alert type="success" msg="{{ session('success') }}" class="mb-3" />
  @endif
  @if($errors->any())
    <x-admin-alert type="danger" msg="{{ $errors->first() }}" class="mb-3" />
  @endif

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">新建定制模板</h6></div>
    <div class="card-body">
      <form method="post" action="{{ admin_route('product_customization.templates.store') }}">
        @csrf
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">模板名称（中文）</label><input class="form-control" name="name_zh_cn" required maxlength="100"></div>
          <div class="col-md-4"><label class="form-label">模板名称（英文）</label><input class="form-control" name="name_en" maxlength="100"></div>
          <div class="col-md-2"><label class="form-label">优先级</label><input class="form-control" type="number" name="priority" min="0" value="0"></div>
          <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">创建模板</button></div>
        </div>
        <div class="mt-3">
          <label class="form-label">适用分类</label>
          <div class="border rounded p-3 bg-light overflow-auto" style="max-height: 20rem">
            @include('ProductCustomization::admin.category_tree', ['categories' => $categoryTree, 'selectedCategories' => []])
          </div>
          <div class="form-text">选择分类后，当前分类及其全部子孙分类下的商品都会应用模板；多个模板命中时使用优先级最高的模板。</div>
        </div>
      </form>
    </div>
  </div>

  @forelse($templates as $template)
    @php($selectedCategories = $template->categories->pluck('id')->map(fn ($id) => (int) $id)->all())
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">{{ $template->name['zh_cn'] ?? ('模板 #' . $template->id) }}</h6>
        <span class="badge {{ $template->active ? 'bg-success' : 'bg-secondary' }}">{{ $template->active ? '启用' : '停用' }} · v{{ $template->version }}</span>
      </div>
      <div class="card-body">
        <form method="post" action="{{ admin_route('product_customization.templates.update', $template) }}" class="mb-4">
          @csrf @method('put')
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">模板名称（中文）</label><input class="form-control" name="name_zh_cn" value="{{ $template->name['zh_cn'] ?? '' }}" required maxlength="100"></div>
            <div class="col-md-4"><label class="form-label">模板名称（英文）</label><input class="form-control" name="name_en" value="{{ $template->name['en'] ?? '' }}" maxlength="100"></div>
            <div class="col-md-2"><label class="form-label">优先级</label><input class="form-control" type="number" name="priority" min="0" value="{{ $template->priority }}"></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary w-100" type="submit">保存模板</button></div>
          </div>
          <div class="mt-3">
            <label class="form-label">适用分类</label>
            <div class="border rounded p-3 bg-light overflow-auto" style="max-height: 20rem">
              @include('ProductCustomization::admin.category_tree', ['categories' => $categoryTree, 'selectedCategories' => $selectedCategories])
            </div>
            <div class="form-text">选择分类后，当前分类及其全部子孙分类下的商品都会应用模板。</div>
          </div>
          <label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="active" value="1" @checked($template->active)><span class="form-check-label">启用模板</span></label>
        </form>

        <div class="table-responsive border rounded mb-3">
          <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>标识</th><th>名称</th><th>类型</th><th>必填</th><th>排序</th><th class="text-end">操作</th></tr></thead>
            <tbody>
            @forelse($template->fields as $field)
              <tr>
                <td><code>{{ $field->field_key }}</code></td>
                <td>{{ $field->label['zh_cn'] ?? '' }}</td>
                <td>{{ $types[$field->type] ?? $field->type }}</td>
                <td>{{ $field->required ? '是' : '否' }}</td>
                <td>{{ $field->sort }}</td>
                <td class="text-end">
                  <details class="d-inline-block text-start"><summary class="btn btn-sm btn-outline-secondary">编辑</summary>
                    @php($fieldOptions = $field->options ?? [])
                    @php($optionsZhCn = array_is_list($fieldOptions) ? $fieldOptions : ($fieldOptions['zh_cn'] ?? $fieldOptions['en'] ?? []))
                    @php($optionsEn = array_is_list($fieldOptions) ? $fieldOptions : ($fieldOptions['en'] ?? $fieldOptions['zh_cn'] ?? []))
                    <form method="post" action="{{ admin_route('product_customization.fields.update', [$template, $field]) }}" class="border rounded p-3 mt-2 bg-light" style="min-width: 360px">
                      @csrf @method('put')
                      <input class="form-control mb-2" name="field_key" value="{{ $field->field_key }}" placeholder="字段标识" required>
                      <input class="form-control mb-2" name="label_zh_cn" value="{{ $field->label['zh_cn'] ?? '' }}" placeholder="中文名称" required>
                      <input class="form-control mb-2" name="label_en" value="{{ $field->label['en'] ?? '' }}" placeholder="英文名称">
                      <select class="form-select mb-2" name="type">@foreach($types as $type => $label)<option value="{{ $type }}" @selected($field->type === $type)>{{ $label }}</option>@endforeach</select>
                      <textarea class="form-control mb-2" name="options_zh_cn" rows="2" placeholder="中文下拉选项，每行一项">{{ implode("\n", $optionsZhCn) }}</textarea>
                      <textarea class="form-control mb-2" name="options_en" rows="2" placeholder="英文下拉选项，每行一项，顺序须与中文一致">{{ implode("\n", $optionsEn) }}</textarea>
                      <input class="form-control mb-2" type="number" name="max_length" value="{{ $field->max_length }}" min="1" placeholder="最大长度">
                      <input class="form-control mb-2" type="number" name="sort" value="{{ $field->sort }}" min="0" placeholder="排序">
                      <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="required" value="1" @checked($field->required)>必填</label>
                      <button class="btn btn-primary btn-sm" type="submit">保存字段</button>
                    </form>
                  </details>
                  <form method="post" action="{{ admin_route('product_customization.fields.destroy', [$template, $field]) }}" class="d-inline" onsubmit="return confirm('确定删除此输入项吗？')">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger" type="submit">删除</button></form>
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted">暂未配置输入项</td></tr>
            @endforelse
            </tbody>
          </table>
        </div>

        <form method="post" action="{{ admin_route('product_customization.fields.store', $template) }}" class="border rounded p-3 bg-light">
          @csrf
          <div class="fw-bold mb-2">添加输入项</div>
          <div class="row g-2">
            <div class="col-md-2"><input class="form-control" name="field_key" placeholder="字段标识，如 name" required></div>
            <div class="col-md-2"><input class="form-control" name="label_zh_cn" placeholder="中文名称" required></div>
            <div class="col-md-2"><input class="form-control" name="label_en" placeholder="英文名称"></div>
            <div class="col-md-2"><select class="form-select" name="type">@foreach($types as $type => $label)<option value="{{ $type }}">{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-2"><input class="form-control" type="number" name="sort" value="0" min="0" placeholder="排序"></div>
            <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">添加</button></div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-md-6"><textarea class="form-control" name="options_zh_cn" rows="2" placeholder="中文下拉选项，每行一项"></textarea></div>
            <div class="col-md-6"><textarea class="form-control" name="options_en" rows="2" placeholder="英文下拉选项，每行一项，顺序须与中文一致"></textarea></div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-md-6"><input class="form-control" type="number" name="max_length" min="1" placeholder="最大长度"></div>
            <div class="col-md-6 d-flex align-items-center"><label class="form-check"><input class="form-check-input" type="checkbox" name="required" value="1">必填</label></div>
          </div>
        </form>
        <form method="post" action="{{ admin_route('product_customization.templates.destroy', $template) }}" class="mt-3" onsubmit="return confirm('删除模板会同时删除字段配置，确定继续吗？')">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger" type="submit">删除模板</button></form>
      </div>
    </div>
  @empty
    <div class="card"><div class="card-body text-center text-muted">还没有定制模板，请先创建一个。</div></div>
  @endforelse
@endsection

@push('footer')
  <script>
    document.querySelectorAll('[data-category-tree]').forEach((tree) => {
      const nodes = Array.from(tree.querySelectorAll('[data-category-tree-node]'));
      const checkboxFor = (node) => node.querySelector('[data-category-checkbox]');

      const hasSelectedAncestor = (node) => {
        let parent = node.parentElement.closest('[data-category-tree-node]');

        while (parent) {
          if (parent.dataset.directSelected === '1') {
            return true;
          }

          parent = parent.parentElement.closest('[data-category-tree-node]');
        }

        return false;
      };

      const refresh = () => {
        nodes.forEach((node) => {
          const checkbox = checkboxFor(node);
          const inherited = hasSelectedAncestor(node);

          checkbox.checked = inherited || node.dataset.directSelected === '1';
          checkbox.disabled = inherited;
          checkbox.title = inherited ? '由已选父分类覆盖' : '';
        });
      };

      tree.addEventListener('change', (event) => {
        const checkbox = event.target.closest('[data-category-checkbox]');
        if (!checkbox || checkbox.disabled) {
          return;
        }

        checkbox.closest('[data-category-tree-node]').dataset.directSelected = checkbox.checked ? '1' : '0';
        refresh();
      });

      refresh();
    });
  </script>
@endpush
