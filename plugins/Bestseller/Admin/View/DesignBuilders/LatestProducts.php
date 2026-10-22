<?php

namespace Plugin\Bestseller\Admin\View\DesignBuilders;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class LatestProducts extends Component
{
    public function render(): View
    {
        $data['register'] = [
            'code'      => 'latest_products',
            'sort'      => 1,
            'name'      => trans('Bestseller::common.latest_products_module_name'),
            'icon'      => plugin_asset('Bestseller', 'image/icon.png'),
            'image'     => asset('image/module/bestseller_preview.webp'),
            'view_path' => 'Bestseller::shop/design_module_latest_products',
        ];

        return view('Bestseller::admin/design_module_latest_products', $data);
    }
}
