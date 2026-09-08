<?php

namespace Tests\Unit\Meilisearch;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Plugin\Meilisearch\Services\MeilisearchClient;
use Tests\TestCase;

class MeilisearchClientTest extends TestCase
{
    public function test_list_indexes_reads_all_pages(): void
    {
        $handler = new MockHandler([
            new Response(200, [], json_encode([
                'results' => [['uid' => 'beikeshop_products_real_en_v1']],
                'offset'  => 0,
                'limit'   => 1000,
                'total'   => 2,
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'results' => [['uid' => 'beikeshop_products_real_en_v2']],
                'offset'  => 1000,
                'limit'   => 1000,
                'total'   => 2,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new MeilisearchClient(new Client(['handler' => $handler]));

        $this->assertSame([
            ['uid' => 'beikeshop_products_real_en_v1'],
            ['uid' => 'beikeshop_products_real_en_v2'],
        ], $client->listIndexes());
        $this->assertCount(0, $handler);
    }
}
