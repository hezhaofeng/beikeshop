<?php

namespace Plugin\Meilisearch\Console;

use Illuminate\Console\Command;
use Plugin\Meilisearch\Services\MeilisearchClient;
use Plugin\Meilisearch\Services\Settings;

class Health extends Command
{
    protected $signature = 'meilisearch:health';

    protected $description = '检查 Meilisearch 服务连接状态';

    public function handle(MeilisearchClient $client): int
    {
        try {
            $health = $client->health();
            $this->info('Meilisearch 连接正常：' . Settings::host());
            $this->line(json_encode($health, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Meilisearch 连接失败：' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
