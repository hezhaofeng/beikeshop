<?php

namespace Tests\Unit\CyberCloak;

use Plugin\CyberCloak\Controllers\AdminCyberCloakController;
use Plugin\CyberCloak\Services\AccessKeyService;
use Tests\TestCase;

class AccessKeySharingTest extends TestCase
{
    /**
     * 分享链接使用保存的原文 key，只有摘要的历史记录不能分享原文。
     */
    public function test_share_link_uses_plain_key_and_rejects_hash_only_records(): void
    {
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        config()->set('app.url', 'https://www.xxx.com');
        config()->set('cyber_cloak', [
            'key_parameter' => 'key',
            'access_keys'   => [
                [
                    'id'         => 'shareable-key',
                    'key'        => 'plain-key-123',
                    'hash'       => hash('sha256', 'plain-key-123'),
                    'status'     => 'enabled',
                    'expires_at' => null,
                ],
                [
                    'id'         => 'hash-only-key',
                    'hash'       => hash('sha256', 'unrecoverable'),
                    'status'     => 'enabled',
                    'expires_at' => null,
                ],
            ],
        ]);
        config()->set('bk.plugin.cyber_cloak.access_keys', config('cyber_cloak.access_keys'));

        $keys       = new AccessKeyService;
        $controller = new AdminCyberCloakController;
        $response   = $controller->shareKey('shareable-key', $keys)->getData(true);

        $this->assertSame('success', $response['status']);
        $this->assertSame('https://www.xxx.com/?key=plain-key-123', $response['data']['url']);
        $this->assertSame('plain-key-123', $keys->plainKeyForShare('shareable-key'));
        $this->assertNull($keys->plainKeyForShare('hash-only-key'));
        $this->assertSame('fail', $controller->shareKey('hash-only-key', $keys)->getData(true)['status']);
    }
}
