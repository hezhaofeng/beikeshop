<?php

namespace Plugin\PaypalB\Services;

/**
 * 把 A 站发货单里的承运商编码换算成 PayPal 运单接口认可的 carrier 枚举。
 *
 * 编码全集来自 PayPal OpenAPI 规范（Data/paypal_carrier_codes.php）。不在全集里的编码一律以 OTHER 上报，
 * 并把商家填写的承运商名称放进 carrier_name_other；OTHER 承运商 PayPal 不会自动查询轨迹。
 */
class PaypalBCarrierResolver
{
    public const OTHER = 'OTHER';

    /** 常见写法到 PayPal 编码的别名；只收录 PayPal 编码本身不直观、容易写错的几家。 */
    private const ALIASES = [
        'CANADA_POST'    => 'CA_CANADA_POST',
        'CANADAPOST'     => 'CA_CANADA_POST',
        'FEDEX_CANADA'   => 'FEDEX',
        'FEDEX_GROUND'   => 'FEDEX',
        'FEDEX_EXPRESS'  => 'FEDEX',
        'DHL_EXPRESS'    => 'DHL',
        'DHL_ECOMMERCE'  => 'DHL_GLOBAL_MAIL',
        'LASERSHIP'      => 'US_LASERSHIP',
        'AMAZON_LOGISTICS' => 'AMAZON',
        'AMZL'           => 'AMAZON',
        'CHINA_POST'     => 'CN_CHINA_POST_EMS',
        'CHINAPOST'      => 'CN_CHINA_POST_EMS',
        'SF_EXPRESS'     => 'SF_EX',
        'SFEXPRESS'      => 'SF_EX',
        '4PX'            => 'FOUR_PX_EXPRESS',
        'FOUR_PX'        => 'FOUR_PX_EXPRESS',
        'YUN_EXPRESS'    => 'YUNEXPRESS',
        'YT'             => 'YUNEXPRESS',
        'YANWEN_EXPRESS' => 'YANWEN',
        'CAINIAO_GLOBAL' => 'CAINIAO',
        'ALIEXPRESS_STANDARD' => 'CAINIAO',
    ];

    /** @var array<string, true>|null */
    private static ?array $codes = null;

    /**
     * @return array{carrier: string, carrier_name_other: string|null, recognized: bool}
     */
    public static function resolve(string $code, string $name): array
    {
        $normalized = self::normalize($code);
        $normalized = self::ALIASES[$normalized] ?? $normalized;
        if ($normalized !== '' && $normalized !== self::OTHER && self::isPaypalCode($normalized)) {
            return ['carrier' => $normalized, 'carrier_name_other' => null, 'recognized' => true];
        }

        $other = trim($name) !== '' ? trim($name) : trim($code);

        return [
            'carrier'            => self::OTHER,
            'carrier_name_other' => mb_substr($other !== '' ? $other : 'Unknown', 0, 64),
            'recognized'         => false,
        ];
    }

    public static function isPaypalCode(string $code): bool
    {
        self::$codes ??= array_fill_keys(require __DIR__ . '/../Data/paypal_carrier_codes.php', true);

        return isset(self::$codes[$code]);
    }

    public static function normalize(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/[^A-Z0-9_]+/', '_', $code) ?? '';

        return trim($code, '_');
    }
}
