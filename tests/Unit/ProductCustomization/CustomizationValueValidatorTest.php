<?php

namespace Tests\Unit\ProductCustomization;

use Beike\Models\Category;
use Beike\Models\CategoryDescription;
use InvalidArgumentException;
use Plugin\ProductCustomization\Controllers\AdminCustomizationController;
use Plugin\ProductCustomization\Services\CustomizationValueValidator;
use Tests\TestCase;

class CustomizationValueValidatorTest extends TestCase
{
    private function fields(): array
    {
        return [
            ['key' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'max_length' => 10],
            ['key' => 'number', 'label' => '号码', 'type' => 'number', 'required' => true, 'max_length' => 8],
            ['key' => 'color', 'label' => '颜色', 'type' => 'select', 'required' => false, 'options' => ['红色', '蓝色']],
        ];
    }

    public function test_normalizes_values_and_generates_stable_line_key(): void
    {
        $service = new CustomizationValueValidator;
        $first   = $service->validate($this->fields(), ['name' => ' 张三 ', 'number' => '0012', 'color' => '红色'], 3, 2);
        $second  = $service->validate($this->fields(), ['name' => '张三', 'number' => '0012', 'color' => '红色'], 3, 2);

        $this->assertSame('张三', $first['values']['name']);
        $this->assertSame($first['line_key'], $second['line_key']);
        $this->assertCount(3, $first['fields']);
    }

    public function test_rejects_required_invalid_and_unknown_values(): void
    {
        $service = new CustomizationValueValidator;

        $this->expectException(InvalidArgumentException::class);
        $service->validate($this->fields(), ['name' => '', 'number' => '12x', 'unknown' => 'x'], 3, 2);
    }

    public function test_rejects_missing_required_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CustomizationValueValidator)->validate($this->fields(), [
            'name' => '', 'number' => '0012',
        ], 3, 2);
    }

    public function test_rejects_non_numeric_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CustomizationValueValidator)->validate($this->fields(), [
            'name' => '张三', 'number' => '12x',
        ], 3, 2);
    }

    public function test_rejects_invalid_select_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CustomizationValueValidator)->validate($this->fields(), [
            'name' => '张三', 'number' => '0012', 'color' => '绿色',
        ], 3, 2);
    }

    public function test_uses_stable_values_for_localized_select_options(): void
    {
        $service = new CustomizationValueValidator;
        $english = $service->validate([
            [
                'key'     => 'color',
                'label'   => 'Color',
                'type'    => 'select',
                'options' => [['value' => 'option_0', 'label' => 'Red']],
            ],
        ], ['color' => 'option_0'], 3, 2);
        $chinese = $service->validate([
            [
                'key'     => 'color',
                'label'   => '颜色',
                'type'    => 'select',
                'options' => [['value' => 'option_0', 'label' => '红色']],
            ],
        ], ['color' => 'option_0'], 3, 2);

        $this->assertSame('option_0', $english['values']['color']);
        $this->assertSame($english['line_key'], $chinese['line_key']);
        $this->assertSame('Red', $english['fields'][0]['options']['option_0']);
        $this->assertSame('红色', $chinese['fields'][0]['options']['option_0']);
    }

    public function test_blade_templates_are_compilable(): void
    {
        foreach ([
            'plugins/ProductCustomization/Views/admin/index.blade.php',
            'plugins/ProductCustomization/Views/admin/category_tree.blade.php',
            'plugins/ProductCustomization/Views/shop/fields.blade.php',
            'plugins/ProductCustomization/Views/order/summary.blade.php',
        ] as $path) {
            $this->assertNotSame('', app('blade.compiler')->compileString(file_get_contents(base_path($path))));
        }
    }

    public function test_category_tree_uses_native_disclosure_elements(): void
    {
        $template = file_get_contents(base_path('plugins/ProductCustomization/Views/admin/category_tree.blade.php'));

        $this->assertStringContainsString('<details', $template);
        $this->assertStringContainsString('<summary', $template);
        $this->assertStringContainsString('data-category-tree', $template);
        $this->assertStringContainsString('@disabled($isInherited)', $template);
        $this->assertStringNotContainsString('@if(($level ?? 0) === 0) open', $template);
    }

    public function test_category_tree_refreshes_inherited_selection_without_submitting_descendants(): void
    {
        $template = file_get_contents(base_path('plugins/ProductCustomization/Views/admin/index.blade.php'));

        $this->assertStringContainsString('data-direct-selected', $template);
        $this->assertStringContainsString('checkbox.disabled = inherited', $template);
        $this->assertStringContainsString('checkbox.closest', $template);
    }

    public function test_builds_category_tree_and_promotes_orphaned_visible_categories_to_roots(): void
    {
        $categories = collect([
            $this->category(10, 0, '服装'),
            $this->category(20, 10, '上衣'),
            $this->category(30, 999, '无可见父级的分类'),
        ]);

        $method = new \ReflectionMethod(AdminCustomizationController::class, 'buildCategoryTree');
        $tree   = $method->invoke(new AdminCustomizationController, $categories);

        $this->assertSame([10, 30], array_column($tree, 'id'));
        $this->assertSame('服装', $tree[0]['name']);
        $this->assertSame([20], array_column($tree[0]['children'], 'id'));
        $this->assertSame('上衣', $tree[0]['children'][0]['name']);
    }

    private function category(int $id, int $parentId, string $name): Category
    {
        $category = new Category([
            'parent_id' => $parentId,
            'active'    => true,
        ]);
        $category->setAttribute('id', $id);
        $category->setRelation('description', new CategoryDescription(['name' => $name]));

        return $category;
    }
}
