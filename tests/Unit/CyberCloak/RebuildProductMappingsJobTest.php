<?php

namespace Tests\Unit\CyberCloak;

use Plugin\CyberCloak\Jobs\RebuildProductMappingsJob;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\MappingTaskService;
use Plugin\CyberCloak\Services\SkuMappingService;
use Tests\TestCase;

class RebuildProductMappingsJobTest extends TestCase
{
    /**
     * 已发布映射任务必须在后台重建商品路由，并把完整结果写回任务状态。
     */
    public function test_job_rebuilds_product_routes_after_publishing_mapping(): void
    {
        $tasks    = $this->tasks(['action' => 'rebuild', 'mode' => 'random', 'sync_routes' => true, 'force' => false]);
        $mappings = new class extends SkuMappingService
        {
            public function rebuild(string $mode = 'exact', string $version = null, bool $publish = false, bool $force = false): array
            {
                return [
                    'version'   => 'v-job-test',
                    'published' => true,
                    'confirmed' => 12,
                    'pending'   => 0,
                    'conflict'  => 0,
                ];
            }
        };
        $routes = new class extends CatalogRouteService
        {
            public function rebuild(string $type, string $version = null, bool $allowUnmapped = false): array
            {
                return ['type' => $type, 'version' => $version, 'routes' => 12, 'unmapped' => 0, 'mapped_public_categories' => 0];
            }
        };

        (new RebuildProductMappingsJob('task-1'))->handle($tasks, $mappings, $routes);

        $this->assertSame([10, 80], $tasks->progressValues);
        $this->assertSame('succeeded', $tasks->status);
        $this->assertSame(12, $tasks->result['product_routes']['routes']);
        $this->assertSame('v-job-test', $tasks->result['version']);
    }

    /**
     * 存在待处理 SKU 时只生成草稿，不应错误地重建已发布商品路由。
     */
    public function test_job_keeps_draft_result_without_rebuilding_routes(): void
    {
        $tasks    = $this->tasks(['action' => 'rebuild', 'mode' => 'candidate', 'sync_routes' => true, 'force' => false]);
        $mappings = new class extends SkuMappingService
        {
            public function rebuild(string $mode = 'exact', string $version = null, bool $publish = false, bool $force = false): array
            {
                return [
                    'version'   => 'v-draft-test',
                    'published' => false,
                    'confirmed' => 8,
                    'pending'   => 2,
                    'conflict'  => 0,
                ];
            }
        };
        $routes = new class extends CatalogRouteService
        {
            public function rebuild(string $type, string $version = null, bool $allowUnmapped = false): array
            {
                throw new \RuntimeException('草稿不应重建路由');
            }
        };

        (new RebuildProductMappingsJob('task-2'))->handle($tasks, $mappings, $routes);

        $this->assertSame('succeeded', $tasks->status);
        $this->assertArrayNotHasKey('product_routes', $tasks->result);
        $this->assertStringContainsString('草稿', $tasks->message);
    }

    /**
     * 使用内存任务状态替代数据库，验证队列 Job 不依赖 HTTP 请求上下文。
     */
    private function tasks(array $task): MappingTaskService
    {
        return new class($task) extends MappingTaskService
        {
            /** @var array<string,mixed> */
            private array $task;

            /** @var array<int,int> */
            public array $progressValues = [];

            public string $status = 'queued';

            /** @var array<string,mixed> */
            public array $result = [];

            public string $message = '';

            /** @param array<string,mixed> $task */
            public function __construct(array $task)
            {
                $this->task = array_merge([
                    'id'              => 'task',
                    'mapping_version' => null,
                    'sync_routes'     => true,
                    'force'           => false,
                    'status'          => 'queued',
                ], $task);
            }

            public function claim(string $id): bool
            {
                $this->status = 'running';

                return true;
            }

            public function find(string $id): ?array
            {
                return $this->task;
            }

            public function progress(string $id, int $progress, string $message): void
            {
                $this->progressValues[] = $progress;
            }

            public function succeed(string $id, array $result, string $message): void
            {
                $this->status  = 'succeeded';
                $this->result  = $result;
                $this->message = $message;
            }

            public function fail(string $id, \Throwable $exception): void
            {
                $this->status = 'failed';
            }
        };
    }
}
