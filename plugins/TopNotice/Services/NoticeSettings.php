<?php

namespace Plugin\TopNotice\Services;

use DateTimeImmutable;

class NoticeSettings
{
    public static function resolve(array $settings, string $locale, bool $isHome, DateTimeImmutable $now): ?array
    {
        if (empty($settings['status']) || (($settings['scope'] ?? 'home') !== 'all' && ! $isHome)) {
            return null;
        }

        $dates = [];
        foreach (['starts_at', 'ends_at'] as $key) {
            $value = trim((string) ($settings[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $now->getTimezone());
            if (! $date || $date->format('Y-m-d H:i:s') !== $value) {
                return null;
            }
            $dates[$key] = $date;
        }
        if ((isset($dates['starts_at']) && $now < $dates['starts_at'])
            || (isset($dates['ends_at']) && $now >= $dates['ends_at'])) {
            return null;
        }

        $translations = $settings['content'] ?? [];
        if (is_string($translations)) {
            $translations = json_decode($translations, true) ?: [];
        }
        $text = is_array($translations) ? trim((string) ($translations[$locale] ?? '')) : '';
        $text = $text !== '' ? $text : trim((string) ($settings['fallback_content'] ?? ''));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        if ($text === '') {
            return null;
        }

        $link = trim((string) ($settings['link'] ?? ''));
        if (! filter_var($link, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $link = '';
        }

        return [
            'text'       => $text,
            'link'       => $link,
            'overflow'   => ($settings['overflow'] ?? '') === 'ellipsis' ? 'ellipsis' : 'scroll',
            'color'      => self::color($settings['color'] ?? '', '#333333'),
            'background' => self::color($settings['background'] ?? '', 'transparent'),
            'font_size'  => max(10, min(20, (int) (($settings['font_size'] ?? null) ?: 12))),
        ];
    }

    private static function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
    }
}
