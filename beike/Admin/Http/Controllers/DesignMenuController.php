<?php

namespace Beike\Admin\Http\Controllers;

use Beike\Repositories\SettingRepo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DesignMenuController extends Controller
{
    /**
     * 展示所有模块编辑器
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View
    {
        $data = [
            'design_settings' => $this->normalizeMenuSetting(system_setting('base.menu_setting', [])),
        ];
        $data = hook_filter('admin.design_menu.index.data', $data);

        return view('admin::pages.design.builder.menu', $data);
    }

    /**
     * 更新所有数据
     *
     * @param Request $request
     * @return JsonResponse
     * @throws \Throwable
     */
    public function update(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), true);

        SettingRepo::storeValue('menu_setting', $this->normalizeMenuSetting($content));

        return json_success(trans('common.updated_success'));
    }

    /**
     * 规范化导航配置结构，保证构建器页面依赖的数组字段始终存在
     *
     * 历史数据、手工改库或外部导入可能出现 menus / childrenGroup / children 为 null 或缺失的情况，
     * 前端 Vue 页面会直接对其调用 forEach / length / push，导致整页无法打开。
     *
     * @param mixed $setting
     * @return array
     */
    private function normalizeMenuSetting($setting): array
    {
        $setting = is_array($setting) ? $setting : [];
        $menus   = is_array($setting['menus'] ?? null) ? $setting['menus'] : [];

        $normalizedMenus = [];
        foreach ($menus as $menu) {
            if (! is_array($menu)) {
                continue;
            }

            $menu['name']  = is_array($menu['name'] ?? null) ? $menu['name'] : [];
            $menu['link']  = is_array($menu['link'] ?? null) ? $menu['link'] : ['type' => 'page', 'value' => '', 'text' => []];
            $menu['badge'] = is_array($menu['badge'] ?? null) ? $menu['badge'] : [];
            $menu['badge'] += ['isShow' => false, 'name' => [], 'bg_color' => '#fd560f', 'text_color' => '#ffffff'];

            $groups = is_array($menu['childrenGroup'] ?? null) ? $menu['childrenGroup'] : [];

            $normalizedGroups = [];
            foreach ($groups as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $group['name']     = is_array($group['name'] ?? null) ? $group['name'] : [];
                $group['type']     = $group['type'] ?? 'link';
                $group['children'] = is_array($group['children'] ?? null) ? array_values($group['children']) : [];
                $group['image']    = is_array($group['image'] ?? null) ? $group['image'] : [];
                $group['image'] += ['image' => [], 'link' => ['type' => 'product', 'value' => '', 'text' => []]];

                $normalizedGroups[] = $group;
            }
            $menu['childrenGroup'] = $normalizedGroups;

            $normalizedMenus[] = $menu;
        }
        $setting['menus'] = $normalizedMenus;

        return $setting;
    }
}
