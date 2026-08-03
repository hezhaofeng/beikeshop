<?php

namespace Plugin\ProductPositionSort;

use Illuminate\Database\Eloquent\Builder;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_filter('repo.product.builder', function (Builder $builder): Builder {
            // 仅恢复前台分类页的默认排序，后台和其他商品列表保持核心逻辑。
            if (
                is_admin()
                || ! request()->routeIs('shop.categories.show')
                || request()->filled('sort')
                || request()->filled('order')
            ) {
                return $builder;
            }

            // 核心查询已先加入 created_at DESC，必须清除已有排序才能真正按 position 排序。
            return $builder
                ->reorder('products.position', 'asc')
                ->orderBy('products.id', 'desc');
        });
    }
}
