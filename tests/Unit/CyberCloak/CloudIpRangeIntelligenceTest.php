<?php

namespace Tests\Unit\CyberCloak;

use Plugin\CyberCloak\Services\CloudIpRangeIntelligence;
use Tests\TestCase;

class CloudIpRangeIntelligenceTest extends TestCase
{
    /**
     * 汇总库支持厂商映射格式，并返回命中的厂商和网段。
     */
    public function test_provider_ranges_are_matched_from_local_json(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cyber-cloak-cloud-');
        file_put_contents($path, json_encode([
            'version'  => 1,
            'providers' => [
                'AWS'       => ['198.51.100.0/24'],
                'Cloudflare' => ['203.0.113.0/24'],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $result = (new CloudIpRangeIntelligence)->lookup('198.51.100.7', $path);

            $this->assertTrue($result['available']);
            $this->assertTrue($result['matched']);
            $this->assertSame('AWS', $result['provider']);
            $this->assertSame('198.51.100.0/24', $result['cidr']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * 无效文件或未命中的地址返回 unknown，不影响其他 GeoIP 信号。
     */
    public function test_invalid_or_unmatched_file_is_not_a_match(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cyber-cloak-cloud-');
        file_put_contents($path, '{bad json');

        try {
            $result = (new CloudIpRangeIntelligence)->lookup('198.51.100.7', $path);

            $this->assertFalse($result['available']);
            $this->assertFalse($result['matched']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * JSON 写入过程短暂失败后，即使文件时间戳未变化也必须重新加载。
     */
    public function test_failed_load_is_retried_when_mtime_stays_the_same(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cyber-cloak-cloud-');
        file_put_contents($path, '{bad json');
        $mtime   = time();
        $service = new CloudIpRangeIntelligence;
        touch($path, $mtime);

        try {
            $this->assertFalse($service->lookup('198.51.100.7', $path)['available']);
            file_put_contents($path, json_encode(['providers' => ['AWS' => ['198.51.100.0/24']]], JSON_THROW_ON_ERROR));
            touch($path, $mtime);
            clearstatcache(true, $path);

            $result = $service->lookup('198.51.100.7', $path);

            $this->assertTrue($result['matched']);
            $this->assertSame('AWS', $result['provider']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * 记录数组兼容常见的 provider/cidr 与官方前缀字段命名。
     */
    public function test_record_arrays_are_matched_from_local_json(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cyber-cloak-cloud-');
        file_put_contents($path, json_encode([
            ['provider' => 'GCP', 'ipv4Prefix' => '203.0.113.0/24'],
        ], JSON_THROW_ON_ERROR));

        try {
            $result = (new CloudIpRangeIntelligence)->lookup('203.0.113.7', $path);

            $this->assertTrue($result['matched']);
            $this->assertSame('GCP', $result['provider']);
        } finally {
            @unlink($path);
        }
    }
}
