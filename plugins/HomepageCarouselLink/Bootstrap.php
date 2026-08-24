<?php

namespace Plugin\HomepageCarouselLink;

use Plugin\HomepageCarouselLink\Services\HomepageCarouselLinkService;

class Bootstrap
{
    /**
     * 将默认首页轮播模块切换到插件模板，避免修改核心主题文件。
     */
    public function boot(): void
    {
        $service = new HomepageCarouselLinkService;

        add_hook_filter('home.index.data', [$service, 'applyHomepageViews'], 20);
        add_hook_filter('admin.design.preview.data', [$service, 'applyPreviewView'], 20);
        add_hook_filter('admin.plugin.edit.data', function (array $data) use ($service): array {
            $plugin = $data['plugin'] ?? null;
            if (! $plugin || ($plugin->code ?? '') !== HomepageCarouselLinkService::CODE) {
                return $data;
            }

            $data['homepageCarousel'] = $service->editorData();

            return $data;
        });
    }
}
