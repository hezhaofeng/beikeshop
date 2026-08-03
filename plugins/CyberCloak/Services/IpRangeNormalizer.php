<?php

namespace Plugin\CyberCloak\Services;

class IpRangeNormalizer
{
    /**
     * 将供应商返回的地址、CIDR 或带字段的记录统一为可比较的字符串。
     *
     * @return array{ranges:array<int,string>,invalid_count:int}
     */
    public function normalizeMany(mixed $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/[\r\n,;]+/', $values, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($values)) {
            return ['ranges' => [], 'invalid_count' => 0];
        }

        $ranges = [];
        $invalidCount = 0;
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = $value['cidr'] ?? $value['ip'] ?? $value['address'] ?? $value['range'] ?? '';
            }
            $normalized = $this->normalize((string) $value);
            if ($normalized === null) {
                if (trim((string) $value) !== '') {
                    $invalidCount++;
                }
                continue;
            }
            $ranges[$normalized] = true;
        }

        return [
            'ranges'        => array_keys($ranges),
            'invalid_count' => $invalidCount,
        ];
    }

    /**
     * 标准化单个 IPv4、IPv6 或 CIDR，并拒绝超出地址族范围的前缀。
     */
    public function normalize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = trim($value, '[]');
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        $packed = @inet_pton($address);
        if ($packed === false) {
            return null;
        }

        $address = inet_ntop($packed);
        if ($address === false) {
            return null;
        }
        if ($prefix === null) {
            return $address;
        }
        if (! ctype_digit((string) $prefix)) {
            return null;
        }

        $prefix = (int) $prefix;
        $maxPrefix = strlen($packed) === 4 ? 32 : 128;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return null;
        }

        return "{$address}/{$prefix}";
    }
}
