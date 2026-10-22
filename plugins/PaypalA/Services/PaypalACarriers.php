<?php

namespace Plugin\PaypalA\Services;

/**
 * PayPal 承运商清单的只读访问：分组用于后台下拉，编码全集用于判断某个编码是否会被 PayPal 自动查询轨迹。
 */
class PaypalACarriers
{
    /** @var array<int, array{label: string, carriers: array<string, string>}>|null */
    private static ?array $groups = null;

    /** @var array<string, true>|null */
    private static ?array $codes = null;

    /**
     * 分组名按当前语言解析：数据文件里只存语言键，后台切换语言时下拉分组随之变化。
     *
     * @return array<int, array{label: string, carriers: array<string, string>}>
     */
    public static function groups(): array
    {
        self::$groups ??= require __DIR__ . '/../Data/paypal_carriers.php';

        return array_map(static fn (array $group): array => [
            'label'    => self::groupLabel((string) $group['label']),
            'carriers' => $group['carriers'],
        ], self::$groups);
    }

    /**
     * 没有翻译器（纯单元测试、极早期启动）或缺少翻译时退回语言键本身，保证分组名永不为空。
     */
    private static function groupLabel(string $key): string
    {
        if (! function_exists('app') || ! app()->bound('translator')) {
            return $key;
        }

        $fullKey = 'PaypalA::common.' . $key;
        $label   = trans($fullKey);

        return is_string($label) && $label !== $fullKey ? $label : $key;
    }

    public static function isPaypalCode(string $code): bool
    {
        self::$codes ??= array_fill_keys(require __DIR__ . '/../Data/paypal_carrier_codes.php', true);

        return isset(self::$codes[strtoupper(trim($code))]);
    }
}
