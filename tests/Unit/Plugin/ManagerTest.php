<?php

namespace Tests\Unit\Plugin;

use Beike\Plugin\Manager;
use Beike\Plugin\Plugin;
use PHPUnit\Framework\TestCase;

class ManagerTest extends TestCase
{
    public function test_local_development_plugin_skips_marketplace_validation(): void
    {
        $plugin = new Plugin(__DIR__, [
            'code'              => 'ad_tracking',
            'local_development' => true,
        ]);

        $this->assertTrue($this->manager()->exposeShouldSkip($plugin, 'ad_tracking', []));
    }

    public function test_regular_plugin_keeps_marketplace_validation(): void
    {
        $plugin = new Plugin(__DIR__, [
            'code' => 'market_plugin',
        ]);

        $this->assertFalse($this->manager()->exposeShouldSkip($plugin, 'market_plugin', []));
    }

    private function manager(): Manager
    {
        return new class extends Manager
        {
            public function exposeShouldSkip(
                Plugin $plugin,
                string $code,
                array $freePluginCodes
            ): bool {
                return $this->shouldSkipMarketplaceValidation(
                    $plugin,
                    $code,
                    $freePluginCodes
                );
            }
        };
    }
}
