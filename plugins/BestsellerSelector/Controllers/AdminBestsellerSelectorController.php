<?php

namespace Plugin\BestsellerSelector\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Plugin\BestsellerSelector\Services\BestsellerSelectionService;

class AdminBestsellerSelectorController extends Controller
{
    public function state(BestsellerSelectionService $service): mixed
    {
        return json_success('热卖商品配置已读取', $service->state());
    }

    public function categories(BestsellerSelectionService $service): mixed
    {
        return json_success('分类列表已读取', $service->categories());
    }

    public function products(Request $request, BestsellerSelectionService $service): mixed
    {
        $data = $request->validate([
            'category_id' => 'nullable|integer|min:1',
            'name'        => 'required_without:category_id|nullable|string|max:255',
            'page'        => 'nullable|integer|min:1',
            'per_page'    => 'nullable|integer|min:10|max:100',
        ]);

        return json_success('可选商品已读取', $service->productsByCategory(
            isset($data['category_id']) ? (int) $data['category_id'] : null,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 50),
            trim((string) ($data['name'] ?? '')),
        ));
    }

    public function save(Request $request, BestsellerSelectionService $service): mixed
    {
        // 使用原始输入判断批量配置请求，避免空对象或旧版客户端序列化后被误判为单模块保存。
        $input = $request->all();
        if (array_key_exists('modules', $input)) {
            $data = $request->validate([
                'modules'                        => 'required|array',
                'modules.*.mode'                 => 'nullable|in:auto,manual',
                'modules.*.product_ids'          => 'nullable|array|max:500',
                'modules.*.product_ids.*'        => 'integer|min:1',
                'modules.*.tabs'                 => 'nullable|array',
                'modules.*.tabs.*.mode'          => 'nullable|in:auto,manual',
                'modules.*.tabs.*.product_ids'   => 'nullable|array|max:500',
                'modules.*.tabs.*.product_ids.*' => 'integer|min:1',
            ]);

            return json_success('首页各商品模块配置已保存', $service->saveModules($data['modules']));
        }

        $data = $request->validate([
            'mode'          => 'nullable|in:auto,manual',
            'product_ids'   => 'nullable|array|max:500',
            'product_ids.*' => 'integer|min:1',
        ]);

        // 兼容缓存的旧版面板：未提交 mode 时，有商品按手动模式保存，否则恢复自动模式。
        $ids   = $data['product_ids'] ?? [];
        $mode  = $data['mode']        ?? ($ids !== [] ? 'manual' : 'auto');
        $state = $service->save($mode, $ids);

        return json_success('热卖商品配置已保存', $state);
    }
}
