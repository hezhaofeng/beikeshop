<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Services\DesignService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Plugin\CyberCloak\Services\HomeBannerImageMappingService;

class HomeController extends Controller
{
    /**
     * 通过page builder 显示首页
     *
     * @return View
     * @throws \Exception
     */
    public function index(): mixed
    {
        $originalUri = session()->get('originalUri');
        if ($originalUri === '/') {
            if (locale() !== system_setting('base.locale')) {
                return $this->redirect();
            }
        }

        $designSettings = system_setting('base.design_setting');
        $modules        = $designSettings['modules'] ?? [];

        // 展示模式只替换 Banner 图片数据，布局和真实站点的装修模块保持一致。
        if (app()->bound(HomeBannerImageMappingService::class)) {
            $modules = app(HomeBannerImageMappingService::class)->mapModules($modules);
        }

        $moduleItems = [];
        foreach ($modules as $module) {
            $code       = $module['code'] ?? null;
            if (! $code) {
                continue;
            }
            $moduleId   = $module['module_id'] ?? '';
            $content    = $module['content'];
            $viewPath   = $module['view_path'] ?? '';

            if ($viewPath) {
                $plugin = plugin(Str::before($viewPath, '::'));

                if ($plugin && $plugin->type == 'theme' && $plugin->code != system_setting('base.theme')) {
                    continue;
                }
            }

            if (empty($viewPath)) {
                $viewPath = "design.{$code}";
            }

            $paths = explode('::', $viewPath);
            if (count($paths) == 2) {
                $pluginCode = $paths[0];
                if (! app('plugin')->checkActive($pluginCode)) {
                    continue;
                }
            }

            if (view()->exists($viewPath) && $moduleId) {
                $moduleItems[] = [
                    'code'      => $code,
                    'module_id' => $moduleId,
                    'view_path' => $viewPath,
                    // 将装修实例 ID 传入内容处理，允许插件针对首页中的同类模块分别配置数据。
                    'content'   => DesignService::handleModuleContent($code, array_merge((array) $content, ['module_id' => $moduleId])),
                ];
            }
        }

        $data = ['modules' => $moduleItems];

        $data = hook_filter('home.index.data', $data);

        return view('home', $data);
    }

    private function redirect(): RedirectResponse
    {
        $lang = session()->get('locale');
        $host = request()->getSchemeAndHttpHost();

        return redirect()->to($host . '/' . $lang);
    }
}
