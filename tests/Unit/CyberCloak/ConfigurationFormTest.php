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
            'traffic_blocked_countries',
            'traffic_allowed_languages',
            'traffic_user_agent_blacklist',
        ] as $name) {
            $column = $columns->get($name);

            $this->assertSame('select-multiple', $column['type']);
            $this->assertSame('nullable|array', $column['rules']);
            $this->assertNotEmpty($column['options']);
        }
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
     * 专属配置页按访问路径分组，并保留存量自定义规则的回显能力。
     */
    public function test_configuration_view_contains_grouped_sections_and_custom_value_compatibility(): void
    {
        $viewPath = base_path('plugins/CyberCloak/Views/admin/config_form.blade.php');
        $content  = file_get_contents($viewPath);

        $this->assertFileExists($viewPath);
        $this->assertStringContainsString('真实站准入', $content);
        $this->assertStringContainsString('风险识别', $content);
        $this->assertStringContainsString('频率限制', $content);
        $this->assertStringContainsString('IP 供应商', $content);
        $this->assertStringContainsString('自定义 - ', $content);
        $this->assertStringContainsString('form-multi-select-add', file_get_contents(base_path('resources/beike/admin/views/components/form/select.blade.php')));
        $this->assertStringContainsString('form-multi-select-remove', file_get_contents(base_path('resources/beike/admin/views/components/form/select.blade.php')));
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
}
