<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Validation\ValidationException;

final class PaypalBMoney
{
    private const ZERO_DECIMAL_CURRENCIES = ['HUF', 'JPY', 'TWD'];

    public static function normalize(mixed $amount, string $currency, string $field = 'amount'): string
    {
        $currency = strtoupper(trim($currency));
        $value    = trim((string) $amount);
        if (! preg_match('/^(0|[1-9]\d{0,11})(?:\.(\d{1,4}))?$/', $value, $matches)) {
            self::invalid($field, '金额格式无效。');
        }

        $scale    = in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            self::invalid($field, '订单金额超过该 PayPal 币种允许的小数精度。');
        }
        if ($scale === 0) {
            return $matches[1];
        }

        return $matches[1] . '.' . str_pad(substr($fraction, 0, $scale), $scale, '0');
    }

    public static function equal(mixed $first, mixed $second, string $currency): bool
    {
        return hash_equals(self::normalize($first, $currency), self::normalize($second, $currency));
    }

    /**
     * 将金额转换为最小货币单位，所有订单一致性计算都走整数，避免浮点误差。
     */
    public static function toMinor(mixed $amount, string $currency, string $field = 'amount'): int
    {
        $normalized           = self::normalize($amount, $currency, $field);
        $scale                = self::scale($currency);
        [$integer, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        return ((int) $integer * (10 ** $scale))
            + (int) str_pad(substr($fraction, 0, $scale), $scale, '0');
    }

    public static function signedToMinor(mixed $amount, string $currency, string $field = 'amount'): int
    {
        $value    = trim((string) $amount);
        $negative = str_starts_with($value, '-');

        return ($negative ? -1 : 1) * self::toMinor(
            $negative ? ltrim($value, '-') : $value,
            $currency,
            $field
        );
    }

    public static function fromMinor(int $minor, string $currency): string
    {
        $scale = self::scale($currency);
        if ($scale === 0) {
            return (string) $minor;
        }

        $base = 10 ** $scale;
        if ($minor < 0) {
            return '-' . self::fromMinor(abs($minor), $currency);
        }

        return intdiv($minor, $base) . '.' . str_pad((string) ($minor % $base), $scale, '0', STR_PAD_LEFT);
    }

    public static function multiply(mixed $unitPrice, int $quantity, string $currency, string $field = 'unit_price'): string
    {
        if ($quantity < 1) {
            self::invalid($field, '数量必须大于零。');
        }

        $unitMinor = self::toMinor($unitPrice, $currency, $field);
        if ($unitMinor > intdiv(PHP_INT_MAX, $quantity)) {
            self::invalid($field, '商品金额超出可计算范围。');
        }

        return self::fromMinor($unitMinor * $quantity, $currency);
    }

    private static function scale(string $currency): int
    {
        return in_array(strtoupper(trim($currency)), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
