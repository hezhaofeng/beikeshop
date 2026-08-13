<?php

namespace Plugin\TieredShipping\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;

class TieredShippingService
{
    public const CODE = 'tiered_shipping';

    public const MODE_AMOUNT_FREE = 'amount_free';

    public const MODE_TIERED_AMOUNT = 'tiered_amount';

    public const MODE_QUANTITY_FREE = 'quantity_free';

    /**
     * 按已选商品小计和总件数计算运费，不信任后台存量配置中的无效数据。
     */
    public function calculate(float $orderAmount, int $productQuantity, mixed $setting): float
    {
        $setting     = $this->toArray($setting);
        $mode        = (string) Arr::get($setting, 'calculation_mode', self::MODE_AMOUNT_FREE);
        $standardFee = $this->normalizeMoney(Arr::get($setting, 'standard_fee', 0));

        if ($mode === self::MODE_AMOUNT_FREE) {
            $threshold = $this->normalizeNonNegativeMoney(Arr::get($setting, 'amount_free_threshold'));

            return $threshold !== null && $orderAmount >= $threshold ? 0.0 : $standardFee;
        }

        if ($mode === self::MODE_QUANTITY_FREE) {
            $threshold = $this->normalizePositiveInteger(Arr::get($setting, 'quantity_free_threshold'));

            return $threshold !== null && $productQuantity >= $threshold ? 0.0 : $standardFee;
        }

        if ($mode === self::MODE_TIERED_AMOUNT) {
            return $this->calculateTieredFee($orderAmount, $setting, $standardFee);
        }

        // 配置被手工篡改时回退基础运费，不让异常配置意外变为免运费。
        return $standardFee;
    }

    /**
     * 保存设置前检查不同计费方式各自必填的门槛和阶梯规则。
     */
    public static function validateConfiguration(array $fields): ValidationValidator
    {
        $validator = Validator::make($fields, [
            'calculation_mode'          => ['required', Rule::in([
                self::MODE_AMOUNT_FREE,
                self::MODE_TIERED_AMOUNT,
                self::MODE_QUANTITY_FREE,
            ])],
            'standard_fee'               => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'amount_free_threshold'      => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'quantity_free_threshold'    => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'tiered_rules'               => ['nullable', 'array', 'max:100'],
        ], [], [
            'standard_fee'            => '基础运费',
            'amount_free_threshold'   => '金额免运费门槛',
            'quantity_free_threshold' => '件数免运费门槛',
            'tiered_rules'            => '金额阶梯规则',
        ]);

        $validator->after(function (ValidationValidator $validator) use ($fields): void {
            $mode = (string) ($fields['calculation_mode'] ?? '');

            if ($mode === self::MODE_AMOUNT_FREE && blank($fields['amount_free_threshold'] ?? null)) {
                $validator->errors()->add('amount_free_threshold', '请填写金额免运费门槛。');
            }

            if ($mode === self::MODE_QUANTITY_FREE && blank($fields['quantity_free_threshold'] ?? null)) {
                $validator->errors()->add('quantity_free_threshold', '请填写件数免运费门槛。');
            }

            if ($mode !== self::MODE_TIERED_AMOUNT) {
                return;
            }

            $rules = $fields['tiered_rules'] ?? [];
            if (! is_array($rules) || count($rules) === 0) {
                $validator->errors()->add('tiered_rules', '请至少添加一条金额阶梯规则。');

                return;
            }

            $amounts = [];
            foreach ($rules as $index => $rule) {
                $amount = is_array($rule) ? ($rule['amount'] ?? null) : null;
                $fee    = is_array($rule) ? ($rule['fee'] ?? null) : null;
                if (! is_numeric($amount) || (float) $amount < 0) {
                    $validator->errors()->add("tiered_rules.{$index}.amount", '请填写不小于 0 的阶梯金额。');

                    continue;
                }
                if (! is_numeric($fee) || (float) $fee < 0) {
                    $validator->errors()->add("tiered_rules.{$index}.fee", '请填写不小于 0 的阶梯运费。');
                }

                $key = number_format((float) $amount, 4, '.', '');
                if (isset($amounts[$key])) {
                    $validator->errors()->add("tiered_rules.{$index}.amount", '阶梯金额不能重复。');
                }
                $amounts[$key] = true;
            }
        });

        return $validator;
    }

    /**
     * 阶梯规则取不高于订单金额的最大门槛；未命中时使用基础运费。
     */
    private function calculateTieredFee(float $orderAmount, array $setting, float $standardFee): float
    {
        $matchedFee = null;
        foreach ($this->normalizeTieredRules(Arr::get($setting, 'tiered_rules', [])) as $rule) {
            if ($orderAmount < $rule['amount']) {
                break;
            }
            $matchedFee = $rule['fee'];
        }

        return $matchedFee ?? $standardFee;
    }

    /**
     * 过滤存量配置中的脏数据并保证按门槛升序计算。
     */
    private function normalizeTieredRules(mixed $rules): array
    {
        if (is_string($rules)) {
            $decoded = json_decode($rules, true);
            $rules   = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];
        foreach ($rules as $rule) {
            if (! is_array($rule) || ! is_numeric($rule['amount'] ?? null) || ! is_numeric($rule['fee'] ?? null)) {
                continue;
            }

            $amount = (float) $rule['amount'];
            $fee    = (float) $rule['fee'];
            if ($amount < 0 || $fee < 0) {
                continue;
            }
            $normalized[] = ['amount' => $amount, 'fee' => $fee];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['amount'] <=> $right['amount']);

        return $normalized;
    }

    private function normalizeMoney(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, (float) $value);
    }

    private function normalizeNonNegativeMoney(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (float) $value;
    }

    private function normalizePositiveInteger(mixed $value): ?int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private function toArray(mixed $setting): array
    {
        if (is_array($setting)) {
            return $setting;
        }
        if (is_string($setting)) {
            $decoded = json_decode($setting, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
