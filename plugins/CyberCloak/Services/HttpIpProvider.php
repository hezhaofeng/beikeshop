<?php

namespace Plugin\CyberCloak\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpIpProvider implements IpProviderInterface
{
    /**
     * 读取常见 JSON 字段或纯文本行，统一交给同步服务做地址校验。
     */
    public function fetch(array $config): IpProviderResult
    {
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new RuntimeException('IP 供应商 endpoint 未配置');
        }

        $request = Http::timeout(max(1, (int) ($config['timeout'] ?? 5)))
            ->acceptJson();
        $token = trim((string) ($config['token'] ?? ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->get($endpoint);
        if ($response->failed()) {
            throw new RuntimeException("IP 供应商请求失败，HTTP {$response->status()}");
        }

        $payload = $response->json();
        if ($payload === null) {
            $payload = $response->body();
        }

        return new IpProviderResult($this->extractValues($payload), [
            'status' => $response->status(),
        ]);
    }

    /**
     * 兼容供应商常见的 ips、cidrs、blacklist、data、items 和 results 字段。
     * 未识别的对象只读取 IP 字段，避免把任意响应字段写入黑名单。
     *
     * @return array<int,mixed>
     */
    private function extractValues(mixed $payload): array
    {
        if (is_string($payload)) {
            return preg_split('/[\r\n,;]+/', $payload, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (! is_array($payload)) {
            return [];
        }

        foreach (['ips', 'cidrs', 'blacklist', 'data', 'items', 'results'] as $key) {
            if (array_key_exists($key, $payload)) {
                return $this->extractValues($payload[$key]);
            }
        }

        if (array_key_exists('ip', $payload) || array_key_exists('cidr', $payload) || array_key_exists('address', $payload) || array_key_exists('range', $payload)) {
            return [$payload];
        }

        return array_values($payload);
    }
}
