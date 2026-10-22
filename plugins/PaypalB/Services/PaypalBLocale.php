<?php

namespace Plugin\PaypalB\Services;

/**
 * 站点语言与 PayPal / HTML 语言标记之间的转换。
 *
 * 站点使用 zh_cn 这类小写下划线写法，PayPal SDK 只接受 xx_XX，HTML lang 要求 xx-XX；
 * 托管结账页、本地支付页和政策页都需要这组转换，集中在此避免各自维护一份映射。
 */
final class PaypalBLocale
{
    /**
     * PayPal 只接受带地区的语言标记；单段语言按 PayPal 文档补齐常用地区。
     */
    private const DEFAULT_REGIONS = [
        'zh' => 'zh_CN',
        'ja' => 'ja_JP',
        'ko' => 'ko_KR',
        'de' => 'de_DE',
        'fr' => 'fr_FR',
        'es' => 'es_ES',
        'it' => 'it_IT',
        'ru' => 'ru_RU',
        'id' => 'id_ID',
    ];

    public static function paypalLocale(string $locale): string
    {
        [$language, $region] = self::parts($locale);
        if ($region !== '') {
            return $language . '_' . strtoupper($region);
        }

        return self::DEFAULT_REGIONS[$language] ?? 'en_US';
    }

    public static function htmlLang(string $locale): string
    {
        [$language, $region] = self::parts($locale);

        return $region !== '' ? $language . '-' . strtoupper($region) : $language;
    }

    /**
     * 语言在本站已启用时切换；非法或未启用的语言静默保持默认语言，绝不阻断支付流程。
     */
    public static function activate(mixed $locale): void
    {
        $locale = strtolower(trim((string) $locale));
        if (preg_match('/^[a-z]{2}(?:_[a-z]{2})?$/', $locale) !== 1) {
            return;
        }

        try {
            if (in_array($locale, array_column(locales(), 'code'), true)) {
                app()->setLocale($locale);
            }
        } catch (\Throwable) {
            // 语言表不可读时保持默认语言。
        }
    }

    /**
     * @return array{0: string, 1: string} 小写语言和地区；没有两位地区代码时地区为空串
     */
    private static function parts(string $locale): array
    {
        $segments = explode('_', strtolower(str_replace('-', '_', trim($locale))), 2);
        $language = preg_match('/^[a-z]{2,3}$/', $segments[0])     === 1 ? $segments[0] : 'en';
        $region   = preg_match('/^[a-z]{2}$/', $segments[1] ?? '') === 1 ? $segments[1] : '';

        return [$language, $region];
    }
}
