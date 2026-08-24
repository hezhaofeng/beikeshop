@php($level = $level ?? 0)
@php($inherited = $inherited ?? false)

<ul class="list-unstyled mb-0 {{ $level > 0 ? 'border-start ms-2 ps-3 mt-1' : '' }}" @if($level === 0) data-category-tree @endif>
  @foreach($categories as $category)
    @php($directlySelected = in_array((int) $category['id'], $selectedCategories, true))
    @php($isInherited = $inherited)
    @php($isSelected = $directlySelected || $isInherited)
    @if($category['children'])
      <li data-category-tree-node data-direct-selected="{{ $directlySelected ? '1' : '0' }}">
        <details class="py-1">
          <summary class="py-1">
            <label class="form-check mb-0 d-inline-block" onclick="event.stopPropagation()">
              <input class="form-check-input" type="checkbox" name="category_ids[]" value="{{ $category['id'] }}" data-category-checkbox @checked($isSelected) @disabled($isInherited)>
              <span class="form-check-label">{{ $category['name'] }}</span>
            </label>
          </summary>
          @include('ProductCustomization::admin.category_tree', ['categories' => $category['children'], 'selectedCategories' => $selectedCategories, 'level' => $level + 1, 'inherited' => $isSelected])
        </details>
      </li>
    @else
      <li class="py-1" data-category-tree-node data-direct-selected="{{ $directlySelected ? '1' : '0' }}">
        <label class="form-check mb-0">
          <input class="form-check-input" type="checkbox" name="category_ids[]" value="{{ $category['id'] }}" data-category-checkbox @checked($isSelected) @disabled($isInherited)>
          <span class="form-check-label">{{ $category['name'] }}</span>
        </label>
      </li>
    @endif
  @endforeach
</ul>
