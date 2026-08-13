<?php

namespace Plugin\BestsellerSelector;

use Plugin\BestsellerSelector\Services\BestsellerSelectionService;

class Bootstrap
{
    /**
     * 注册首页热卖商品覆盖逻辑和后台选品面板。
     */
    public function boot(): void
    {
        app()->singleton(BestsellerSelectionService::class);

        // 核心模块完成商品查询后，再按首页 module_id 覆盖手动选品结果。
        // 因此同类型模块可以分别配置，且原商品模块/选项卡模块的结构保持不变。
        add_hook_filter('service.design.module.content.after', function (array $data): array {
            if (! in_array(($data['module_code'] ?? ''), ['bestseller', 'product', 'tab_product'], true)) {
                return $data;
            }

            return app(BestsellerSelectionService::class)->applyHomepageSelection($data);
        }, 30);

        // 面板同时挂到本插件和原 Bestseller 插件编辑页，减少后台入口切换成本。
        add_hook_blade('admin.plugin.form.after', function ($callback, $content, array $data = []): string {
            $plugin = $data['plugin'] ?? null;
            if (! $plugin || ! in_array($plugin->code, ['bestseller_selector', 'bestseller'], true)) {
                return (string) $content;
            }

            return (string) $content . view('BestsellerSelector::admin.selector_panel')->render();
        }, 20);
    }
}
