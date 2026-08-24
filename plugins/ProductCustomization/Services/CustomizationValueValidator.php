<?php

namespace Plugin\ProductCustomization\Services;

use InvalidArgumentException;

/**
 * 校验并规范化商品定制值，保持行标识计算与展示快照一致。
 */
class CustomizationValueValidator
{
    public function validate(array $fields, array $input, int $templateId, int $version): array
    {
        $known = [];
        foreach ($fields as $field) {
            $known[(string) $field['key']] = $field;
        }

        foreach ($input as $key => $value) {
            if (! isset($known[(string) $key])) {
                throw new InvalidArgumentException(__('ProductCustomization::common.invalid_field'));
            }
        }

        $values         = [];
        $snapshotFields = [];
        foreach ($fields as $field) {
            $key     = (string) $field['key'];
            $value   = $this->normalize($input[$key] ?? '');
            $label   = (string) ($field['label'] ?? $key);
            $options = $this->optionLabels($field['options'] ?? []);
            if (! empty($field['required']) && $value === '') {
                throw new InvalidArgumentException(__('ProductCustomization::common.field_required', ['name' => $label]));
            }
            if (! empty($field['max_length']) && mb_strlen($value) > (int) $field['max_length']) {
                throw new InvalidArgumentException(__('ProductCustomization::common.max_length', [
                    'name'   => $label,
                    'length' => $field['max_length'],
                ]));
            }
            if ($value !== '' && ($field['type'] ?? 'text') === 'number' && ! preg_match('/^\d+$/', $value)) {
                throw new InvalidArgumentException(__('ProductCustomization::common.numeric_only', ['name' => $label]));
            }
            if ($value !== '' && ($field['type'] ?? 'text') === 'select' && ! array_key_exists($value, $options)) {
                throw new InvalidArgumentException(__('ProductCustomization::common.invalid_option', ['name' => $label]));
            }

            $values[$key]     = $value;
            $snapshotFields[] = array_filter([
                'key'     => $key,
                'label'   => $label,
                'options' => ($field['type'] ?? 'text') === 'select' ? $options : null,
            ], static fn ($item): bool => $item !== null);
        }

        return [
            'line_key' => hash('sha256', json_encode([
                'template_id' => $templateId,
                'version'     => $version,
                'values'      => $values,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'fields' => $snapshotFields,
            'values' => $values,
        ];
    }

    private function normalize(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            throw new InvalidArgumentException(__('ProductCustomization::common.invalid_value'));
        }

        return trim((string) $value);
    }

    /**
     * 将新旧选项格式转换为“提交值 => 当前语言展示值”映射。
     */
    private function optionLabels(array $options): array
    {
        $labels = [];
        foreach ($options as $option) {
            if (is_array($option)) {
                $value = (string) ($option['value'] ?? '');
                if ($value !== '') {
                    $labels[$value] = (string) ($option['label'] ?? $value);
                }

                continue;
            }

            $labels[(string) $option] = (string) $option;
        }

        return $labels;
    }
}
