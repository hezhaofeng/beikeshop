<?php

namespace Plugin\CyberCloak\Services;

class IpProviderResult
{
    /**
     * @param array<int,mixed> $values
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public readonly array $values,
        public readonly array $meta = [],
    ) {
    }
}
