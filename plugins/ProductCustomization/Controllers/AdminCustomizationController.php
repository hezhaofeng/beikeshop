<?php

namespace Plugin\ProductCustomization\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Beike\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Plugin\ProductCustomization\Models\CartCustomization;
use Plugin\ProductCustomization\Models\CustomizationField;
use Plugin\ProductCustomization\Models\CustomizationTemplate;
use Plugin\ProductCustomization\Models\OrderCustomization;

class AdminCustomizationController extends Controller
{
    public function index()
    {
        $categories = Category::query()
            ->with('description')
            ->where('active', true)
            ->orderBy('parent_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return view('ProductCustomization::admin.index', [
            'templates'    => CustomizationTemplate::query()->with(['fields', 'categories.description'])->orderByDesc('priority')->orderBy('id')->get(),
            'categoryTree' => $this->buildCategoryTree($categories),
            'types'        => [
                'text'     => '单行文本',
                'textarea' => '多行文本',
                'number'   => '数字',
                'select'   => '下拉选项',
            ],
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $data     = $this->validateTemplate($request);
        $template = CustomizationTemplate::query()->create([
            'name'     => ['zh_cn' => $data['name_zh_cn'], 'en' => $data['name_en'] ?: $data['name_zh_cn']],
            'priority' => $data['priority'],
            'active'   => $request->boolean('active', true),
        ]);
        $template->categories()->sync($data['category_ids']);

        return back()->with('success', '定制模板已创建，请继续添加输入项。');
    }

    public function updateTemplate(Request $request, CustomizationTemplate $template): RedirectResponse
    {
        $data = $this->validateTemplate($request);
        $template->update([
            'name'     => ['zh_cn' => $data['name_zh_cn'], 'en' => $data['name_en'] ?: $data['name_zh_cn']],
            'priority' => $data['priority'],
            'active'   => $request->boolean('active'),
            'version'  => $template->version + 1,
        ]);
        $template->categories()->sync($data['category_ids']);

        return back()->with('success', '定制模板已保存。');
    }

    public function destroyTemplate(CustomizationTemplate $template): RedirectResponse
    {
        if (CartCustomization::query()->where('template_id', $template->id)->exists()
            || OrderCustomization::query()->where('template_id', $template->id)->exists()) {
            return back()->withErrors(['template' => '该模板已被购物车或历史订单使用，只能停用，不能删除。']);
        }

        $template->delete();

        return back()->with('success', '定制模板已删除。');
    }

    public function storeField(Request $request, CustomizationTemplate $template): RedirectResponse
    {
        $data = $this->validateField($request);
        $template->fields()->create($this->fieldData($data));
        $template->increment('version');

        return back()->with('success', '定制输入项已添加。');
    }

    public function updateField(Request $request, CustomizationTemplate $template, CustomizationField $field): RedirectResponse
    {
        abort_unless($field->template_id === $template->id, 404);
        $data = $this->validateField($request, $field);
        $field->update($this->fieldData($data));
        $template->increment('version');

        return back()->with('success', '定制输入项已保存。');
    }

    public function destroyField(CustomizationTemplate $template, CustomizationField $field): RedirectResponse
    {
        abort_unless($field->template_id === $template->id, 404);
        $field->delete();
        $template->increment('version');

        return back()->with('success', '定制输入项已删除。');
    }

    private function validateTemplate(Request $request): array
    {
        $data = $request->validate([
            'name_zh_cn'     => ['required', 'string', 'max:100'],
            'name_en'        => ['nullable', 'string', 'max:100'],
            'priority'       => ['required', 'integer', 'min:0', 'max:999999'],
            'active'         => ['nullable', 'boolean'],
            'category_ids'   => ['nullable', 'array', 'max:500'],
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')],
        ]);
        $data['category_ids'] = array_map('intval', $request->input('category_ids', []));

        return $data;
    }

    private function validateField(Request $request, CustomizationField $field = null): array
    {
        $data = $request->validate([
            'field_key'         => ['required', 'regex:/^[a-z][a-z0-9_]{0,63}$/', 'max:64'],
            'label_zh_cn'       => ['required', 'string', 'max:100'],
            'label_en'          => ['nullable', 'string', 'max:100'],
            'placeholder_zh_cn' => ['nullable', 'string', 'max:100'],
            'placeholder_en'    => ['nullable', 'string', 'max:100'],
            'type'              => ['required', Rule::in(['text', 'textarea', 'number', 'select'])],
            'options_zh_cn'     => ['nullable', 'string', 'max:5000'],
            'options_en'        => ['nullable', 'string', 'max:5000'],
            'options'           => ['nullable', 'string', 'max:5000'],
            'max_length'        => ['nullable', 'integer', 'min:1', 'max:10000'],
            'required'          => ['nullable', 'boolean'],
            'active'            => ['nullable', 'boolean'],
            'sort'              => ['required', 'integer', 'min:0', 'max:999999'],
        ]);

        $duplicate = CustomizationField::query()
            ->where('template_id', $field?->template_id ?? $request->route('template')->id)
            ->where('field_key', $data['field_key'])
            ->when($field, fn ($query) => $query->where('id', '!=', $field->id))
            ->exists();
        if ($duplicate) {
            throw \Illuminate\Validation\ValidationException::withMessages(['field_key' => '同一模板中的字段标识不能重复。']);
        }

        $optionsZhCn = $this->parseOptions($data['options_zh_cn'] ?? $data['options'] ?? '');
        $optionsEn   = $this->parseOptions($data['options_en'] ?? '');
        if ($data['type'] === 'select' && ! $optionsZhCn && ! $optionsEn) {
            throw \Illuminate\Validation\ValidationException::withMessages(['options_zh_cn' => '下拉选项至少填写一项。']);
        }
        if ($optionsZhCn && $optionsEn && count($optionsZhCn) !== count($optionsEn)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['options_en' => '中文和英文下拉选项数量必须一致。']);
        }

        return $data;
    }

    private function fieldData(array $data): array
    {
        $optionsZhCn = $this->parseOptions($data['options_zh_cn'] ?? $data['options'] ?? '');
        $optionsEn   = $this->parseOptions($data['options_en'] ?? '');
        $optionsZhCn = $optionsZhCn ?: $optionsEn;
        $optionsEn   = $optionsEn ?: $optionsZhCn;

        return [
            'field_key'   => $data['field_key'],
            'label'       => ['zh_cn' => $data['label_zh_cn'], 'en' => $data['label_en'] ?: $data['label_zh_cn']],
            'placeholder' => ['zh_cn' => $data['placeholder_zh_cn'] ?? '', 'en' => $data['placeholder_en'] ?? ''],
            'type'        => $data['type'],
            'options'     => $data['type'] === 'select' ? ['zh_cn' => $optionsZhCn, 'en' => $optionsEn] : [],
            'max_length'  => $data['max_length'] ?: null,
            'required'    => ! empty($data['required']),
            'active'      => ! array_key_exists('active', $data) || ! empty($data['active']),
            'sort'        => $data['sort'],
        ];
    }

    private function parseOptions(string $options): array
    {
        $options = preg_split('/\r?\n/', trim($options), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map('trim', $options)));
    }

    /**
     * 将启用分类转为树形数据。父级未启用或不存在时，其子级作为根节点展示。
     */
    private function buildCategoryTree(Collection $categories): array
    {
        $categoriesById   = $categories->keyBy('id');
        $childrenByParent = $categories->groupBy(fn (Category $category): int => (int) $category->parent_id);

        $buildNode = function (Category $category) use (&$buildNode, $childrenByParent): array {
            return [
                'id'       => (int) $category->id,
                'name'     => $category->description->name ?? ('分类 #' . $category->id),
                'children' => $childrenByParent
                    ->get((int) $category->id, collect())
                    ->map($buildNode)
                    ->values()
                    ->all(),
            ];
        };

        return $categories
            ->filter(fn (Category $category): bool => (int) $category->parent_id === 0 || ! $categoriesById->has($category->parent_id))
            ->map($buildNode)
            ->values()
            ->all();
    }
}
