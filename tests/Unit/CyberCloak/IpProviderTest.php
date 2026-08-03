<?php

namespace Tests\Unit\CyberCloak;

use Illuminate\Support\Facades\Http;
use Plugin\CyberCloak\Services\HttpIpProvider;
use Plugin\CyberCloak\Services\IpProviderInterface;
use Plugin\CyberCloak\Services\IpProviderRegistry;
use Plugin\CyberCloak\Services\IpProviderResult;
use Plugin\CyberCloak\Services\IpProviderSyncService;
use Plugin\CyberCloak\Services\IpRangeNormalizer;
use Tests\TestCase;

class IpProviderTest extends TestCase
{
    /**
     * IPv4、IPv6、CIDR 会被标准化并去重，非法地址只计入错误数量。
     */
    public function test_normalizer_supports_ipv4_ipv6_and_cidr(): void
    {
        $result = (new IpRangeNormalizer)->normalizeMany([
            '192.0.2.1',
            '192.0.2.1',
            '2001:0db8::1/64',
            'invalid-address',
        ]);

        $this->assertSame(['192.0.2.1', '2001:db8::1/64'], $result['ranges']);
        $this->assertSame(1, $result['invalid_count']);
    }

    /**
     * HTTP 适配器只提取约定字段，不把其他响应内容写入地址列表。
     */
    public function test_http_provider_extracts_common_payload_fields(): void
    {
        Http::fake([
            'https://provider.test/ips' => Http::response([
                'data' => ['192.0.2.0/24', '2001:db8::/32'],
                'ignored' => 'not-an-ip',
            ]),
        ]);

        $result = (new HttpIpProvider)->fetch([
            'endpoint' => 'https://provider.test/ips',
            'timeout'  => 3,
            'token'    => 'fixture-token',
        ]);

        $this->assertSame(['192.0.2.0/24', '2001:db8::/32'], $result->values);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer fixture-token'));
    }

    /**
     * 同步服务支持插件注册的适配器，并返回标准化统计。
     */
    public function test_sync_service_can_test_registered_provider(): void
    {
        $registry = new IpProviderRegistry;
        $registry->register('fixture', new class implements IpProviderInterface {
            public function fetch(array $config): IpProviderResult
            {
                return new IpProviderResult(['192.0.2.1', '192.0.2.1', 'bad']);
            }
        });
        $service = new IpProviderSyncService($registry, new IpRangeNormalizer);

        $result = $service->test(['provider' => 'fixture']);

        $this->assertSame('fixture', $result['provider']);
        $this->assertSame(3, $result['fetched_count']);
        $this->assertSame(['192.0.2.1'], $result['ranges']);
        $this->assertSame(1, $result['invalid_count']);
    }
}
