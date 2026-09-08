<?php

namespace Plugin\PaypalA\Services;

use Illuminate\Validation\ValidationException;

final class PaypalAMoney
{
    /**
     * PayPal 这些常用币种按零小数位传值；其余本插件按两位小数处理。
     */
    private const ZERO_DECIMAL_CURRENCIES = ['HUF', 'JPY', 'TWD'];

    public static function normalize(mixed $amount, string $currency, string $field = 'amount'): string
    {
        $currency = strtoupper(trim($currency));
        $value    = trim((string) $amount);
        if (! preg_match('/^(0|[1-9]\d{0,11})(?:\.(\d{1,4}))?$/', $value, $matches)) {
            self::invalid($field, '金额格式无效。');
        }

        $scale    = in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
        $integer  = $matches[1];
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            self::invalid($field, '订单金额超过该 PayPal 币种允许的小数精度。');
        }

        $fraction = substr($fraction, 0, $scale);
        if ($scale === 0) {
            return $integer;
        }

        return $integer . '.' . str_pad($fraction, $scale, '0');
    }

    public static function equal(mixed $first, mixed $second, string $currency): bool
    {
        return hash_equals(self::normalize($first, $currency), self::normalize($second, $currency));
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
