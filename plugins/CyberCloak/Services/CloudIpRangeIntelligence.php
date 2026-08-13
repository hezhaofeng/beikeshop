<?php

namespace Plugin\CyberCloak\Services;

use Symfony\Component\HttpFoundation\IpUtils;

class CloudIpRangeIntelligence
{
    /** @var array<string,array{provider:string,cidr:string}> */
    private array $ranges = [];

    private string $loadedPath = '';

    private int $loadedMtime = -1;

    private bool $loaded = false;

    /**
     * 从本地云厂商 CIDR 汇总文件识别数据中心地址。
     *
     * 文件只在服务端读取，支持 providers 映射和 provider/cidr 记录两种格式。
     * 解析结果按文件修改时间缓存，更新数据库文件后无需重启 PHP 进程。
     *
     * @return array{provider:?string,cidr:?string,matched:bool,available:bool,source:string}
     */
    public function lookup(string $ip, string $path): array
    {
        $result = [
            'provider'  => null,
            'cidr'      => null,
            'matched'   => false,
            'available' => false,
            'source'    => 'none',
        ];

        if (! filter_var($ip, FILTER_VALIDATE_IP) || trim($path) === '' || ! is_file($path)) {
            return $result;
        }

        $mtime = (int) @filemtime($path);
        $this->load($path, $mtime);
        if (! $this->loaded || $this->ranges === []) {
            return $result;
        }

        $result['available'] = true;
        $result['source']    = 'cloud_ranges';
        foreach ($this->ranges as $range) {
            try {
                if (IpUtils::checkIp($ip, $range['cidr'])) {
                    return array_merge($result, [
                        'provider' => $range['provider'],
                        'cidr'     => $range['cidr'],
                        'matched'  => true,
                    ]);
                }
            } catch (\Throwable) {
                // 单条错误网段只跳过，不影响其他厂商网段继续匹配。
            }
        }

        return $result;
    }

    /**
     * 按文件路径和修改时间加载 JSON，避免每个请求重复读取大体积网段文件。
     */
    private function load(string $path, int $mtime): void
    {
        // 仅成功加载后才允许命中缓存；文件原子替换在同一秒完成时，失败状态必须继续重试。
        if ($this->loaded && $this->loadedPath === $path && $this->loadedMtime === $mtime) {
            return;
        }

        $this->loadedPath  = $path;
        $this->loadedMtime = $mtime;
        $this->loaded      = false;
        $this->ranges      = [];

        try {
            $content = file_get_contents($path);
            $data    = json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                return;
            }

            $ranges        = [];
            $this->collectProviders($data, $ranges);
            $this->collectRecords($data, $ranges);
            $this->ranges  = $this->uniqueRanges($ranges);
            $this->loaded  = true;
        } catch (\Throwable) {
            // 文件更新期间可能短暂不可读；即使修改时间未变化，下次请求也会重试。
        }
    }

    /**
     * 解析推荐的 providers: {"AWS":["1.2.3.0/24"]} 结构。
     *
     * @param array<string,mixed> $data
     * @param array<int,array{provider:string,cidr:string}> $ranges
     */
    private function collectProviders(array $data, array &$ranges): void
    {
        $providers = $data['providers'] ?? null;
        if (! is_array($providers)) {
            return;
        }

        foreach ($providers as $provider => $values) {
            $this->collectValues($values, $this->providerName((string) $provider), $ranges);
        }
    }

    /**
     * 解析带 provider/cidr、ipv4Prefix 或 ipv6Prefix 字段的记录。
     *
     * @param array<string,mixed> $data
     * @param array<int,array{provider:string,cidr:string}> $ranges
     */
    private function collectRecords(array $data, array &$ranges): void
    {
        $records = array_is_list($data)
            ? $data
            : array_merge(
                is_array($data['ranges'] ?? null) ? $data['ranges'] : [],
                is_array($data['entries'] ?? null) ? $data['entries'] : [],
                is_array($data['prefixes'] ?? null) ? $data['prefixes'] : []
            );

        foreach ($records as $record) {
            if (is_string($record)) {
                $this->appendRange($ranges, 'CLOUD', $record);

                continue;
            }
            if (! is_array($record)) {
                continue;
            }

            $provider = $this->providerName((string) ($record['provider'] ?? $record['cloud_provider'] ?? $record['source'] ?? 'CLOUD'));
            foreach (['cidr', 'network', 'prefix', 'ip_prefix', 'ipv4Prefix', 'ipv6_prefix', 'ipv6Prefix'] as $key) {
                if (isset($record[$key])) {
                    $this->collectValues($record[$key], $provider, $ranges);
                }
            }
            foreach (['ipv4_cidrs', 'ipv6_cidrs', 'networks', 'prefixes', 'ranges'] as $key) {
                if (isset($record[$key])) {
                    $this->collectValues($record[$key], $provider, $ranges);
                }
            }
        }
    }

    /**
     * 递归收集字符串网段，兼容厂商把 CIDR 放在数组或嵌套对象中的格式。
     *
     * @param mixed $values
     * @param array<int,array{provider:string,cidr:string}> $ranges
     */
    private function collectValues(mixed $values, string $provider, array &$ranges): void
    {
        if (is_string($values)) {
            $this->appendRange($ranges, $provider, $values);

            return;
        }
        if (! is_array($values)) {
            return;
        }

        foreach ($values as $value) {
            if (is_array($value)) {
                $childProvider = $this->providerName((string) ($value['provider'] ?? $value['cloud_provider'] ?? $provider));
                foreach (['cidr', 'network', 'prefix', 'ip_prefix', 'ipv4Prefix', 'ipv6_prefix', 'ipv6Prefix'] as $key) {
                    if (isset($value[$key])) {
                        $this->collectValues($value[$key], $childProvider, $ranges);
                    }
                }
                foreach (['ipv4_cidrs', 'ipv6_cidrs', 'networks', 'prefixes', 'ranges'] as $key) {
                    if (isset($value[$key])) {
                        $this->collectValues($value[$key], $childProvider, $ranges);
                    }
                }
                if (array_is_list($value)) {
                    $this->collectValues($value, $childProvider, $ranges);
                }
            } else {
                $this->appendRange($ranges, $provider, (string) $value);
            }
        }
    }

    /**
     * 只保留合法 IPv4/IPv6 地址或 CIDR，防止错误配置进入运行时匹配。
     *
     * @param array<int,array{provider:string,cidr:string}> $ranges
     */
    private function appendRange(array &$ranges, string $provider, string $cidr): void
    {
        $cidr = trim($cidr);
        [$address, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return;
        }
        $maximumPrefix = str_contains($address, ':') ? 128 : 32;
        if ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > $maximumPrefix)) {
            return;
        }

        $ranges[] = [
            'provider' => $this->providerName($provider),
            'cidr'     => $cidr,
        ];
    }

    /**
     * 规范化厂商名称，避免日志和后台信号被超长文本污染。
     */
    private function providerName(string $provider): string
    {
        $provider = trim($provider);

        return $provider !== '' ? substr($provider, 0, 64) : 'CLOUD';
    }

    /**
     * 去重并保持文件顺序，优先返回最早声明的厂商网段。
     *
     * @param array<int,array{provider:string,cidr:string}> $ranges
     * @return array<int,array{provider:string,cidr:string}>
     */
    private function uniqueRanges(array $ranges): array
    {
        $unique = [];
        foreach ($ranges as $range) {
            $key = strtolower($range['provider'] . '|' . $range['cidr']);
            $unique[$key] ??= $range;
        }

        return array_values($unique);
    }
}
