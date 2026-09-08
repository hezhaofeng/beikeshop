<?php

namespace Beike\Shop\View\Components;

use Beike\Services\SearchKeywordService;
use Illuminate\View\Component;

class SearchPopover extends Component
{
    public function __construct()
    {
    }

    public function render()
    {
        $currentLocale    = locale();
        $hotKeywords      = app(SearchKeywordService::class)->trending($currentLocale);

        // 新站或统计表暂不可用时使用后台配置冷启动；一旦产生真实搜索数据，完全按近 30 天频次展示。
        if ($hotKeywords !== []) {
            return view('components.search-popover', ['hot_keywords' => $hotKeywords]);
        }

        $keywordsByLocale = system_setting('base.hot_keywords', []);

        // 兼容旧数据（字符串）以及只填写了默认语言的生产配置。后台语言增加后，
        // 若新语言没有单独维护热搜词，回退到默认语言，避免只显示标题而没有内容。
        if (is_array($keywordsByLocale)) {
            $defaultLocale  = (string) system_setting('base.locale', 'en');
            $systemKeywords = '';

            foreach (array_unique([$currentLocale, $defaultLocale]) as $candidateLocale) {
                $candidateKeywords = $keywordsByLocale[$candidateLocale] ?? null;
                if (is_string($candidateKeywords) && trim($candidateKeywords) !== '') {
                    $systemKeywords = $candidateKeywords;

                    break;
                }
            }

            if ($systemKeywords === '') {
                $systemKeywords = (string) (collect($keywordsByLocale)->first(
                    static fn ($value): bool => is_string($value) && trim($value) !== ''
                ) ?? '');
            }
        } else {
            $systemKeywords = (string) $keywordsByLocale;
        }

        // 兜底配置按录入顺序展示；清理空白和重复项时保留该顺序。
        $hotKeywords = collect(preg_split('/[,，]/u', (string) $systemKeywords, -1, PREG_SPLIT_NO_EMPTY))
            ->map(static fn ($keyword): string => trim($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $data = [
            'hot_keywords' => $hotKeywords,
        ];

        return view('components.search-popover', $data);
    }
}
