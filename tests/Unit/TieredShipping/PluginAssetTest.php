<?php

namespace Tests\Unit\TieredShipping;

use Tests\TestCase;

class PluginAssetTest extends TestCase
{
    /**
     * 配送方式图标必须由当前插件自己的静态目录提供。
     */
    public function test_plugin_declares_a_local_shipping_icon(): void
    {
        $config = json_decode(file_get_contents(base_path('plugins/TieredShipping/config.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('/image/logo.png', $config['icon'] ?? null);
        $this->assertFileExists(base_path('plugins/TieredShipping/Static/image/logo.png'));
    }
}
