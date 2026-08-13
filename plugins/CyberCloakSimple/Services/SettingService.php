<?php

namespace Plugin\CyberCloakSimple\Services;

use Beike\Models\Setting;

/**
 * 统一读取插件后台设置和环境配置，并兼容文本、JSON 数组两种录入方式。
 */
class SettingService
{
    /**
     * 读取单项配置；测试环境优先使用内存配置，避免依赖 settings 数据表。
     */
    public function value(string $name, mixed $default = null): mixed
    {
        if (app()->environment('testing')) {
            $testingValue = config("bk.plugin.cyber_cloak_simple.{$name}");
            if ($testingValue !== null && $testingValue !== '') {
                return $testingValue;
            }

            if (config()->has("cyber_cloak_simple.{$name}")) {
                return config("cyber_cloak_simple.{$name}", $default);
            }
        }

        try {
            $setting = Setting::query()
                ->where('type', 'plugin')
                ->where('space', 'cyber_cloak_simple')
                ->where('name', $name)
                ->first();
            if ($setting) {
                return $setting->json ? json_decode((string) $setting->value, true) : $setting->value;
            }
        } catch (\Throwable) {
            // 安装早期或纯单元测试没有 settings 表时继续使用配置回退值。
        }

        $value = function_exists('plugin_setting') ? plugin_setting("cyber_cloak_simple.{$name}", null) : null;

        return ($value !== null && $value !== '') ? $value : config("cyber_cloak_simple.{$name}", $default);
    }

    /**
     * 将换行、逗号、分号或 JSON 数组统一为去空白字符串列表。
     *
     * @return array<int,string>
     */
    public function list(string $name): array
    {
        $value = $this->value($name, []);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * 读取 JSON 映射或 key 记录；格式异常按空数组处理，避免单条错误配置中断前台。
     *
     * @return array<int,array<string,mixed>>
     */
    public function records(string $name): array
    {
        $value = $this->value($name, []);
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }
}
