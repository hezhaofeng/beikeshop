<?php

namespace Plugin\HomepageCarouselLink\Services;

use Beike\Repositories\CategoryRepo;
use Illuminate\Support\Str;

class HomepageCarouselLinkService
{
    public const CODE = 'homepage_carousel_link';

    public const SLIDES_SETTING = 'slides';

    public const MODULE_CODES = [
        'slideshow',
        'img_text_slideshow',
        'img_text_slideshow_2',
    ];

    /**
     * 首页与装修预览都走同一份轮播视图。
     */
    public function applyHomepageViews(array $data): array
    {
        foreach ((array) ($data['modules'] ?? []) as $index => $module) {
            $code = (string) ($module['code'] ?? '');
            if (! $this->supports($code)) {
                continue;
            }

            $defaultView = "design.{$code}";
            if (($module['view_path'] ?? '') === '' || ($module['view_path'] ?? '') === $defaultView) {
                $data['modules'][$index]['view_path'] = $this->viewPath($code);
            }

            $moduleId = (string) ($module['module_id'] ?? '');
            if ($moduleId !== '') {
                $data['modules'][$index]['content'] = $this->applyConfiguredLinksToContent(
                    $moduleId,
                    (array) ($module['content'] ?? [])
                );
            }
        }

        return $data;
    }

    /**
     * 让后台预览和首页渲染保持一致。
     */
    public function applyPreviewView(array $data): array
    {
        $code = (string) ($data['code'] ?? '');
        if ($this->supports($code)) {
            $data['view_path'] = $this->viewPath($code);
        }

        $moduleId = (string) ($data['module_id'] ?? '');
        if ($moduleId !== '') {
            $data['content'] = $this->applyConfiguredLinksToContent(
                $moduleId,
                (array) ($data['content'] ?? [])
            );
        }

        return $data;
    }

    /**
     * 编辑页需要的模块、分类和当前保存值。
     */
    public function editorData(): array
    {
        $designSettings = system_setting('base.design_setting', ['modules' => []]);

        return [
            'modules'    => $this->editorModules((array) ($designSettings['modules'] ?? [])),
            'categories' => $this->categories(),
            'link_types' => $this->linkTypeOptions(),
        ];
    }

    public function supports(string $code): bool
    {
        return in_array($code, self::MODULE_CODES, true);
    }

    public function viewPath(string $code): string
    {
        return 'HomepageCarouselLink::design.' . $code;
    }

    /**
     * 将设计器中的轮播模块转成插件配置页可直接编辑的数据。
     */
    public function editorModules(array $modules, ?array $savedSlides = null): array
    {
        $savedSlides = $this->normalizeSlides($savedSlides ?? $this->storedSlides());
        $result      = [];

        foreach ($modules as $module) {
            $code = (string) ($module['code'] ?? '');
            if (! $this->supports($code)) {
                continue;
            }

            $moduleId = trim((string) ($module['module_id'] ?? ''));
            if ($moduleId === '') {
                continue;
            }

            $content = (array) ($module['content'] ?? []);
            $images  = [];
            foreach ((array) ($content['images'] ?? []) as $index => $image) {
                $images[] = [
                    'index'      => (int) $index,
                    'title'      => $this->slideTitle($image, (int) $index),
                    'image_path' => $this->extractImagePath($image['image'] ?? null),
                    'image'      => $this->previewImage($this->extractImagePath($image['image'] ?? null)),
                    'link'       => $this->resolveLinkForEditor(
                        (array) ($image['link'] ?? []),
                        $savedSlides[$moduleId][(int) $index] ?? null
                    ),
                ];
            }

            $result[] = [
                'module_id' => $moduleId,
                'code'      => $code,
                'title'     => $this->moduleLabel($code),
                'images'    => $images,
            ];
        }

        return $result;
    }

    /**
     * 保存到 settings 表的轮播链接数组，统一清洗为 module_id => [index => link]。
     */
    public function normalizeSlides(mixed $slides): array
    {
        if (! is_array($slides)) {
            return [];
        }

        $result = [];
        foreach ($slides as $moduleId => $items) {
            if (! is_array($items)) {
                continue;
            }

            $moduleKey = (string) $moduleId;
            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $result[$moduleKey][(int) $index] = [
                    'type'       => (string) ($item['type'] ?? ''),
                    'value'      => $this->normalizeLinkValue($item['value'] ?? ''),
                    'new_window' => $this->normalizeBoolean($item['new_window'] ?? false),
                ];
            }
        }

        return $result;
    }

    /**
     * 仅展示分类和自定义链接两种入口。
     */
    public function linkTypeOptions(): array
    {
        return [
            ['value' => 'category', 'label' => '分类'],
            ['value' => 'custom', 'label' => '自定义 URL'],
        ];
    }

    /**
     * 插件配置页的分类下拉。
     */
    public function categories(): array
    {
        return CategoryRepo::flatten(admin_locale(), false)->map(function ($category): array {
            return [
                'value' => (int) $category->id,
                'label' => (string) $category->name,
            ];
        })->values()->all();
    }

    /**
     * 将已保存的链接覆盖回首页轮播模块。
     */
    public function applyConfiguredLinksToContent(string $moduleId, array $content, ?array $savedSlides = null): array
    {
        $savedSlides = $this->normalizeSlides($savedSlides ?? $this->storedSlides());
        if (empty($content['images']) || empty($savedSlides[$moduleId])) {
            return $content;
        }

        foreach ((array) $content['images'] as $index => $image) {
            if (! array_key_exists($index, $savedSlides[$moduleId])) {
                continue;
            }

            $content['images'][$index]['link'] = $this->buildLink(
                (array) $savedSlides[$moduleId][$index],
                (array) ($image['link'] ?? [])
            );
        }

        return $content;
    }

    private function storedSlides(): array
    {
        return (array) plugin_setting(self::CODE . '.' . self::SLIDES_SETTING, []);
    }

    private function resolveLinkForEditor(array $fallback, ?array $override): array
    {
        return $override === null
            ? $this->buildEditorLink($fallback)
            : $this->buildEditorLink($override, $fallback);
    }

    private function buildLink(array $link, array $fallback = []): array
    {
        $data = $link ?: $fallback;
        if (! is_array($data)) {
            $data = [];
        }

        $type       = (string) ($data['type'] ?? '');
        $value      = $this->normalizeLinkValue($data['value'] ?? '');
        $newWindow  = $this->normalizeBoolean($data['new_window'] ?? false);
        $linkUrl    = (string) ($data['link'] ?? '');

        if ($linkUrl === '') {
            $linkUrl = $type === 'custom'
                ? $this->customUrl($value)
                : type_route($type, $value);
        }

        return [
            'type'       => $type,
            'value'      => $value,
            'new_window' => $newWindow,
            'link'       => $linkUrl,
        ];
    }

    private function buildEditorLink(array $link, array $fallback = []): array
    {
        $data = $link ?: $fallback;
        if (! is_array($data)) {
            $data = [];
        }

        $type      = (string) ($data['type'] ?? '');
        $value     = $this->normalizeLinkValue($data['value'] ?? '');
        $newWindow = $this->normalizeBoolean($data['new_window'] ?? false);
        $linkUrl   = (string) ($data['link'] ?? '');

        if ($type === 'category' || $type === 'custom') {
            if ($linkUrl === '') {
                $linkUrl = $type === 'custom'
                    ? $this->customUrl($value)
                    : type_route($type, $value);
            }

            return [
                'type'       => $type,
                'value'      => $value,
                'new_window' => $newWindow,
                'link'       => $linkUrl,
            ];
        }

        if ($linkUrl === '') {
            $linkUrl = type_route($type, $value);
        }

        return [
            'type'       => 'custom',
            'value'      => $linkUrl,
            'new_window' => $newWindow,
            'link'       => $linkUrl,
        ];
    }

    private function customUrl(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (Str::startsWith($value, ['http://', 'https://', '//', '/'])) {
            return $value;
        }

        return '//' . ltrim($value, '/');
    }

    private function moduleLabel(string $code): string
    {
        $labels = [
            'slideshow'           => '幻灯片',
            'img_text_slideshow'  => '图文幻灯片',
            'img_text_slideshow_2'=> '图文幻灯片2',
        ];

        if (app()->bound('translator')) {
            return trans('admin/design_builder.module_' . $code);
        }

        return $labels[$code] ?? $code;
    }

    private function slideTitle(array $image, int $index): string
    {
        return (string) (
            data_get($image, 'title.' . $this->safeLocale())
            ?: data_get($image, 'title.' . $this->safeAdminLocale())
            ?: ('第 ' . ($index + 1) . ' 张')
        );
    }

    private function extractImagePath(mixed $image): string
    {
        if (is_string($image)) {
            return $image;
        }

        if (is_array($image)) {
            if (isset($image['src'])) {
                return is_array($image['src']) ? (string) ($image['src'][$this->safeLocale()] ?? '') : (string) $image['src'];
            }

            return (string) ($image[$this->safeLocale()] ?? '');
        }

        return '';
    }

    private function previewImage(string $imagePath): string
    {
        if ($imagePath === '') {
            return '';
        }

        if (! app()->bound('config')) {
            return $imagePath;
        }

        return image_origin($imagePath);
    }

    private function normalizeLinkValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? '';
        }

        return trim((string) $value);
    }

    private function normalizeBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function safeLocale(): string
    {
        return app()->bound('config') ? locale() : 'zh_cn';
    }

    private function safeAdminLocale(): string
    {
        return app()->bound('config') ? admin_locale() : 'zh_cn';
    }
}
