<?php

namespace Plugin\CyberCloak\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\MappingTaskService;
use Plugin\CyberCloak\Services\SkuMappingService;
use RuntimeException;

class RebuildProductMappingsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * 映射会遍历全量商品和 SKU；Worker 超时必须小于队列连接的 retry_after。
     */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public string $taskId)
    {
        $this->onConnection('cyber_cloak')->onQueue('cyber_cloak');
    }

    /**
     * 在队列 Worker 中执行全量映射和可选的商品路由重建，不占用后台 HTTP 请求。
     */
    public function handle(MappingTaskService $tasks, SkuMappingService $mappings, CatalogRouteService $routes): void
    {
        if (! $tasks->claim($this->taskId)) {
            return;
        }

        $task = $tasks->find($this->taskId);
        if (! $task) {
            return;
        }

        try {
            $tasks->progress($this->taskId, 10, '正在生成商品和 SKU 映射');
            if ($task['action'] === 'publish') {
                $version = (string) $task['mapping_version'];
                if (! $mappings->publishConfirmed($version)) {
                    throw new RuntimeException('当前版本仍有待确认或冲突映射');
                }

                $result = [
                    'version'   => $version,
                    'published' => true,
                    'confirmed' => 0,
                    'pending'   => 0,
                    'conflict'  => 0,
                ];
            } else {
                $result = $mappings->rebuild(
                    (string) $task['mode'],
                    null,
                    true,
                    (bool) $task['force']
                );
            }

            if ($task['sync_routes'] && $result['published']) {
                $tasks->progress($this->taskId, 80, '正在重建商品稳定链接');
                $result['product_routes'] = $routes->rebuild('product', (string) $result['version']);
            }

            $message = $result['published']
                ? '商品映射已发布' . ($task['sync_routes'] ? '，商品链接已更新' : '')
                : '商品映射草稿已生成，请处理待确认或冲突项';
            $tasks->succeed($this->taskId, $result, $message);
            Log::info('CyberCloak 队列完成商品映射任务', [
                'task_id'   => $this->taskId,
                'version'   => $result['version'],
                'mode'      => $task['mode'],
                'published' => $result['published'],
            ]);
        } catch (\Throwable $exception) {
            $tasks->fail($this->taskId, $exception);
            Log::error('CyberCloak 队列商品映射任务失败', [
                'task_id' => $this->taskId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Worker 最终失败时再次写入状态，覆盖超时或意外中断后的活动状态。
     */
    public function failed(\Throwable $exception): void
    {
        app(MappingTaskService::class)->fail($this->taskId, $exception);
    }
}
