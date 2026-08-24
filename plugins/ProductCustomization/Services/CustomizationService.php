<?php

namespace Plugin\ProductCustomization\Services;

use Beike\Models\Product;
use Beike\Models\ProductSku;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Plugin\ProductCustomization\Models\CartCustomization;
use Plugin\ProductCustomization\Models\CustomizationTemplate;
use Plugin\ProductCustomization\Models\OrderCustomization;

/**
 * 商品定制模板解析、输入校验及订单快照服务。
 */
class CustomizationService
{
    public const CODE = 'product_customization';

    public const TYPES = ['text', 'textarea', 'number', 'select'];

    /**
     * 根据商品所属分类及其祖先分类选择优先级最高的启用模板。
     */
    public function templateForProduct(Product|int $product): ?CustomizationTemplate
    {
        if (! $product instanceof Product) {
            $product = Product::query()->find($product);
        }
        if (! $product) {
            return null;
        }

        $categoryIds = $product->categories()->pluck('categories.id')->map(static fn ($id): int => (int) $id)->all();
        if (! $categoryIds) {
            return null;
        }

        $templateIds = DB::table('product_customization_template_categories as template_categories')
            ->join('category_paths as category_paths', 'template_categories.category_id', '=', 'category_paths.path_id')
            ->whereIn('category_paths.category_id', $categoryIds)
            ->distinct()
            ->pluck('template_categories.template_id');
        if ($templateIds->isEmpty()) {
            return null;
        }

        return CustomizationTemplate::query()
            ->where('active', true)
            ->whereIn('id', $templateIds)
            ->with(['fields' => fn ($query) => $query->where('active', true)->orderBy('sort')->orderBy('id')])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();
    }

    /**
     * 返回详情页所需的安全字段定义，不暴露后台表结构。
     */
    public function frontendData(Product|int $product): ?array
    {
        $template = $this->templateForProduct($product);
        if (! $template || $template->fields->isEmpty()) {
            return null;
        }

        return [
            'template_id'      => $template->id,
            'template_version' => $template->version,
            'fields'           => $template->fields->map(fn ($field): array => [
                'key'         => $field->field_key,
                'label'       => $this->localized($field->label),
                'placeholder' => $this->localized($field->placeholder),
                'type'        => $field->type,
                'options'     => $this->localizedOptions($field->options),
                'max_length'  => $field->max_length,
                'required'    => (bool) $field->required,
            ])->values()->all(),
        ];
    }

    /**
     * 加购前重新读取模板并校验请求，防止只依赖前端字段。
     */
    public function buildCartContext(array $context): array
    {
        $sku = $context['sku'] ?? null;
        if (! $sku instanceof ProductSku) {
            return $context;
        }

        $template = $this->templateForProduct($sku->product);
        $input    = $this->decodeInput($context['request']?->input('customization', []));
        if (! $template) {
            if ($input) {
                throw new InvalidArgumentException(__('ProductCustomization::common.unsupported_product'));
            }

            return $context;
        }

        $known  = $template->fields->keyBy('field_key');
        foreach ($input as $key => $value) {
            if (! $known->has((string) $key)) {
                throw new InvalidArgumentException(__('ProductCustomization::common.invalid_field'));
            }
        }

        $definitions = $template->fields->map(fn ($field): array => [
            'key'        => $field->field_key,
            'label'      => $this->localized($field->label),
            'type'       => $field->type,
            'options'    => $this->localizedOptions($field->options),
            'max_length' => $field->max_length,
            'required'   => (bool) $field->required,
        ])->all();
        $validated = (new CustomizationValueValidator)->validate($definitions, $input, $template->id, $template->version);

        $context['line_key']      = $validated['line_key'];
        $context['customization'] = [
            'template_id'      => $template->id,
            'template_version' => $template->version,
            'line_key'         => $validated['line_key'],
            'fields'           => $validated['fields'],
            'values'           => $validated['values'],
        ];

        return $context;
    }

    /**
     * 保存购物车定制值。订单创建后会从该快照复制，不依赖当前配置。
     */
    public function saveCartCustomization($cart, ?array $customization): void
    {
        if (! $cart || empty($customization['line_key'])) {
            return;
        }

        CartCustomization::query()->updateOrCreate(
            ['cart_product_id' => $cart->id],
            [
                'template_id'      => $customization['template_id'],
                'template_version' => $customization['template_version'],
                'line_key'         => $customization['line_key'],
                'fields'           => $customization['fields'],
                'values'           => $customization['values'],
            ]
        );
    }

    /**
     * 在订单商品创建后复制定制快照。
     */
    public function copyToOrder(array $data): void
    {
        $orderProduct = $data['order_product'] ?? null;
        $cartProduct  = $data['cart_product']  ?? [];
        $cartId       = is_array($cartProduct) ? ($cartProduct['cart_id'] ?? null) : ($cartProduct->id ?? null);
        if (! $orderProduct || ! $cartId) {
            return;
        }

        $customization = CartCustomization::query()->where('cart_product_id', $cartId)->first();
        if (! $customization) {
            return;
        }

        OrderCustomization::query()->updateOrCreate(
            ['order_product_id' => $orderProduct->id],
            [
                'template_id'      => $customization->template_id,
                'template_version' => $customization->template_version,
                'line_key'         => $customization->line_key,
                'fields'           => $customization->fields,
                'values'           => $customization->values,
            ]
        );
    }

    public function cartSummary(int $cartId): string
    {
        $customization = CartCustomization::query()->where('cart_product_id', $cartId)->first();

        return $this->summary($customization?->fields, $customization?->values);
    }

    public function orderSummary(int $orderProductId): string
    {
        $customization = OrderCustomization::query()->where('order_product_id', $orderProductId)->first();

        return $this->summary($customization?->fields, $customization?->values);
    }

    private function summary(?array $fields, ?array $values): string
    {
        if (! $fields || ! $values) {
            return '';
        }

        $items = [];
        foreach ($fields as $field) {
            $value = (string) ($values[$field['key']] ?? '');
            if ($value !== '') {
                $options = $field['options'] ?? [];
                $value   = is_array($options) ? (string) ($options[$value] ?? $value) : $value;
                $items[] = __('ProductCustomization::common.summary_item', [
                    'name'  => $field['label'],
                    'value' => $value,
                ]);
            }
        }

        return implode(__('ProductCustomization::common.summary_separator'), $items);
    }

    private function decodeInput(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }

        return is_array($input) ? $input : [];
    }

    private function localized(?array $values): string
    {
        if (! $values) {
            return '';
        }

        foreach ([locale(), system_setting('base.locale'), 'zh_cn', 'en'] as $locale) {
            if ($locale && ! empty($values[$locale])) {
                return (string) $values[$locale];
            }
        }

        return (string) reset($values);
    }

    /**
     * 兼容历史单语言选项，并为新多语言选项生成跨语言稳定的提交值。
     */
    private function localizedOptions(?array $options): array
    {
        if (! $options) {
            return [];
        }

        if (array_is_list($options)) {
            return array_values(array_map(static fn ($option): array => [
                'value' => (string) $option,
                'label' => (string) $option,
            ], $options));
        }

        foreach ([locale(), system_setting('base.locale'), 'zh_cn', 'en'] as $locale) {
            if (! empty($options[$locale]) && is_array($options[$locale])) {
                return array_values(array_map(
                    static fn ($label, int $index): array => [
                        'value' => 'option_' . $index,
                        'label' => (string) $label,
                    ],
                    $options[$locale],
                    array_keys($options[$locale])
                ));
            }
        }

        return [];
    }
}
