<?php

/**
 * bootstrap.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-20 15:35:59
 * @modified   2022-07-20 15:35:59
 */

namespace Plugin\Bestseller;

use Plugin\Bestseller\Repositories\ProductRepo;
use Plugin\Bestseller\Services\BestsellerSelectionService;

class Bootstrap
{
    public function boot()
    {
        app()->singleton(BestsellerSelectionService::class);

        /**
         * Add module for admin design.
         */
        add_hook_filter('admin.design.index.data', function ($data) {
            $data['editors'][] = 'editor-bestseller';
            $data['editors'][] = 'editor-latest_products';

            return $data;
        });

        /**
         * Get module content for home page and preview.
         */
        add_hook_filter('service.design.module.content', function ($data) {
            $module = $data['module_code'] ?? '';

            if ($module == 'bestseller') {
                $data['title']    = $data['title'][locale()] ?? '';
                $data['products'] = ProductRepo::getBestSellerProducts($data['limit']);
            } elseif ($module == 'latest_products') {
                $data['title']    = $data['title'][locale()] ?? '';
                $limit            = max(1, min((int) ($data['limit'] ?? 6), 60));
                $products         = \Beike\Repositories\ProductRepo::getBuilder([
                    'active' => 1,
                    'sort'   => 'products.created_at',
                    'order'  => 'desc',
                ])
                    ->whereHas('masterSku')
                    ->limit($limit)
                    ->get();

                $data['products'] = \Beike\Shop\Http\Resources\ProductSimple::collection($products)->jsonSerialize();
            }

            return $data;
        });

        // 自动取品完成后，按首页模块实例或选项卡应用手动选品。
        add_hook_filter('service.design.module.content.after', function (array $data): array {
            if (! in_array(($data['module_code'] ?? ''), ['bestseller', 'product', 'tab_product'], true)) {
                return $data;
            }

            return app(BestsellerSelectionService::class)->applyHomepageSelection($data);
        }, 30);

        // 合并后只有热卖商品插件提供选品入口，避免出现两份相同的编辑页。
        add_hook_blade('admin.plugin.form.after', function ($callback, $content, array $data = []): string {
            $plugin = $data['plugin'] ?? null;
            if (! $plugin || $plugin->code !== 'bestseller') {
                return (string) $content;
            }

            return (string) $content . view('Bestseller::admin.selector_panel')->render();
        }, 20);
    }
}
