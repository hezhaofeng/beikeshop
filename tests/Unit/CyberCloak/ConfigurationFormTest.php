<?php

namespace Tests\Unit\CyberCloak;

use Tests\TestCase;

class ConfigurationFormTest extends TestCase
{
    /**
     * 可视化名单字段必须以数组保存，避免多选下拉退化为文本规则。
     */
    public function test_policy_fields_use_multi_select_and_array_validation(): void
    {
        $columns = collect(require base_path('plugins/CyberCloak/columns.php'))->keyBy('name');

        foreach ([
            'traffic_allowed_countries',
            'traffic_allowed_languages',
            'traffic_user_agent_blacklist',
        ] as $name) {
            $column = $columns->get($name);

            $this->assertSame('select-multiple', $column['type']);
            $this->assertSame('nullable|array', $column['rules']);
            $this->assertNotEmpty($column['options']);
        }
        $this->assertFalse($columns->has('traffic_blocked_countries'));
        $this->assertFalse($columns->has('access_keys'));
    }

    /**
     * 后台默认选项只保留公开可识别的抓取器，不把支付网关回调 UA 作为拦截目标。
     */
    public function test_platform_crawler_options_exclude_payment_callbacks(): void
    {
        $columns = collect(require base_path('plugins/CyberCloak/columns.php'))->keyBy('name');
        $values  = collect($columns->get('traffic_user_agent_blacklist')['options'])->pluck('value')->all();

        foreach ([
            'GOOGLEBOT', 'ADSBOT-GOOGLE', 'ADSBOT-GOOGLE-MOBILE', 'STOREBOT-GOOGLE',
            'FACEBOOKEXTERNALHIT', 'FACEBOOKCATALOG', 'INSTAGRAMBOT',
        ] as $crawler) {
            $this->assertContains($crawler, $values);
        }
        foreach (['PAYPAL', 'STRIPE', 'WISE'] as $paymentPlatform) {
            $this->assertNotContains($paymentPlatform, $values);
        }
    }

    /**
     * 配置页按三部分规划分组，并保留存量自定义规则的回显能力。
     */
    public function test_configuration_view_contains_grouped_sections_and_custom_value_compatibility(): void
    {
        $viewPath = base_path('plugins/CyberCloak/Views/admin/config_form.blade.php');
        $content  = file_get_contents($viewPath);

        $this->assertFileExists($viewPath);
        $this->assertStringContainsString('基础设置', $content);
        $this->assertStringContainsString('高级设置', $content);
        $this->assertStringContainsString('cyber-cloak-runtime-status', $content);
        $this->assertStringNotContainsString('IP 供应商', $content);
        $this->assertStringContainsString('cloud_ip_ranges_database', $content);
        $this->assertStringContainsString('风险 IP 审核', $content);
        $this->assertStringContainsString('traffic_behavior_enabled', $content);
        $this->assertStringContainsString('cyber-cloak-access-keys', $content);
        $this->assertStringContainsString('create-access-key', $content);
        $this->assertStringContainsString('share-access-key', $content);
        $this->assertStringContainsString('Route::has($shareRouteName)', $content);
        $this->assertStringContainsString('$shareEndpoint', $content);
        $this->assertStringContainsString("admin_route('cyber_cloak.keys.share'", $content);
        $this->assertStringContainsString("admin_route('cyber_cloak.keys.create')", $content);
        $this->assertStringContainsString("admin_route('cyber_cloak.settings.update')", $content);
        $this->assertStringNotContainsString("'fields' => ['status', 'access_keys'", $content);
        $this->assertStringContainsString('映射管理', file_get_contents(base_path('plugins/CyberCloak/Views/admin/mapping.blade.php')));
        $mappingView = file_get_contents(base_path('plugins/CyberCloak/Views/admin/mapping.blade.php'));
        $this->assertStringContainsString('生成商品映射', $mappingView);
        $this->assertStringContainsString('发布当前草稿', $mappingView);
        $this->assertStringContainsString('data-action="publish"', $mappingView);
        $this->assertStringContainsString("fileUpload: @json(admin_route('file.store'))", $mappingView);
        $this->assertStringContainsString('data-role="banner-image-file"', $mappingView);
        $this->assertStringContainsString("formData.append('type', 'image')", $mappingView);
        $this->assertStringNotContainsString('payload.version = state.selectedVersion', $mappingView);
        $this->assertStringContainsString('自定义 - ', $content);
        $this->assertStringContainsString('form-multi-select-add', file_get_contents(base_path('resources/beike/admin/views/components/form/select.blade.php')));
        $this->assertStringContainsString('form-multi-select-remove', file_get_contents(base_path('resources/beike/admin/views/components/form/select.blade.php')));
        $this->assertStringContainsString('form-multi-select-empty-input', file_get_contents(base_path('resources/beike/admin/views/components/form/select.blade.php')));
        $this->assertStringContainsString(".prop('disabled', true)", file_get_contents(base_path('resources/beike/admin/views/components/form/select.blade.php')));
        $this->assertStringContainsString('normalizeMultiValue', file_get_contents(base_path('plugins/CyberCloak/Controllers/AdminCyberCloakController.php')));
    }

    /**
     * Blade 模板在不依赖运行中插件状态的情况下可被编译。
     */
    public function test_configuration_view_can_be_compiled(): void
    {
        $content  = file_get_contents(base_path('plugins/CyberCloak/Views/admin/config_form.blade.php'));
        $compiled = app('blade.compiler')->compileString($content);

        $this->assertStringContainsString('cyber-cloak-settings-accordion', $compiled);
    }

    /**
     * 配置页的内嵌 PHP 数组必须在真实渲染阶段保持语法正确。
     */
    public function test_configuration_view_can_be_rendered(): void
    {
        $plugin = new class
        {
            public string $code = 'cyber_cloak';

            /**
             * 最小插件对象只提供配置页实际使用的字段接口。
             */
            public function getColumns(): array
            {
                return [];
            }
        };

        $html = view('CyberCloak::admin.config_form', ['plugin' => $plugin])->render();

        $this->assertStringContainsString('cyber-cloak-runtime-status', $html);
        $this->assertStringContainsString('基础设置', $html);
    }
}
