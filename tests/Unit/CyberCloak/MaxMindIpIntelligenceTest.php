<?php

namespace Tests\Unit\CyberCloak;

use Plugin\CyberCloak\Services\CloudIpRangeIntelligence;
use Plugin\CyberCloak\Services\MaxMindIpIntelligence;
use Tests\TestCase;

class MaxMindIpIntelligenceTest extends TestCase
{
    /**
     * 关闭 MMDB 查询后，本地云网段汇总库仍可提供数据中心信号。
     */
    public function test_cloud_ranges_work_when_maxmind_is_disabled(): void
    {
        config()->set('cyber_cloak.geoip_enabled', false);
        config()->set('cyber_cloak.cloud_ip_ranges_database', 'fixture.json');
        $cloudRanges = new class extends CloudIpRangeIntelligence
        {
            public function lookup(string $ip, string $path): array
            {
                return [
                    'provider'  => 'AWS',
                    'cidr'      => '198.51.100.0/24',
                    'matched'   => true,
                    'available' => true,
                    'source'    => 'cloud_ranges',
                ];
            }
        };

        $result = (new MaxMindIpIntelligence($cloudRanges))->lookup('198.51.100.7');

        $this->assertTrue($result['available']);
        $this->assertSame('DATACENTER', $result['network_type']);
        $this->assertSame('AWS', $result['cloud_provider']);
    }
}
