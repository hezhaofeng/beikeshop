<?php

namespace Tests\Unit\Meilisearch;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Plugin\Meilisearch\Jobs\DeleteProduct;
use Plugin\Meilisearch\Jobs\RunIndexTask;
use Plugin\Meilisearch\Jobs\SyncProduct;
use Plugin\Meilisearch\Services\IndexTaskService;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class IndexQueueTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer         = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container                       = new Container();
        $container->instance('config', new Repository());
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        foreach (['MEILISEARCH_JOB_TIMEOUT', 'MEILISEARCH_QUEUE_RETRY_AFTER'] as $name) {
            $this->previousEnvironment[$name] = Env::getRepository()->get($name);
            Env::getRepository()->clear($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            if ($value === null) {
                Env::getRepository()->clear($name);
            } else {
                Env::getRepository()->set($name, $value);
            }
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_index_job_reservation_outlasts_timeout_without_changing_default_queue(): void
    {
        $queue = $this->loadQueueConfig();

        $this->assertSame(90, $queue['connections']['database']['retry_after']);
        $this->assertSame(7200, (new RunIndexTask('task-id'))->timeout);
        $this->assertSame(7500, $queue['connections']['meilisearch']['retry_after']);
    }

    public function test_longer_timeout_is_serialized_and_short_retry_window_is_clamped(): void
    {
        Env::getRepository()->set('MEILISEARCH_JOB_TIMEOUT', '14400');
        Env::getRepository()->set('MEILISEARCH_QUEUE_RETRY_AFTER', '90');
        $config = $this->loadQueueConfig();
        $job    = new RunIndexTask('task-id');
        $queue  = new DatabaseQueue($this->createMock(Connection::class), 'jobs', 'meilisearch');
        $queue->setContainer(Container::getInstance());
        $payload = json_decode((new ReflectionMethod($queue, 'createPayload'))->invoke($queue, $job, 'meilisearch'), true);

        $this->assertSame(14400, $payload['timeout']);
        $this->assertSame(1, $payload['maxTries']);
        $this->assertSame(14700, $config['connections']['meilisearch']['retry_after']);
        $this->assertSame('meilisearch', unserialize($payload['data']['command'])->connection);
    }

    public function test_invalid_timeout_cannot_disable_worker_timeout(): void
    {
        Env::getRepository()->set('MEILISEARCH_JOB_TIMEOUT', '0');
        $this->loadQueueConfig();

        $this->assertSame(120, (new RunIndexTask('task-id'))->timeout);
    }

    public function test_product_jobs_use_same_connection_and_preserve_after_commit(): void
    {
        $this->loadQueueConfig();
        foreach ([new SyncProduct(1, 'real'), new DeleteProduct(1, 'public')] as $job) {
            $this->assertSame('meilisearch', $job->connection);
            $this->assertSame('meilisearch', $job->queue);
            $this->assertTrue($job->afterCommit);
            $this->assertGreaterThan($job->timeout, config('queue.connections.meilisearch.retry_after'));
        }
    }

    public function test_timeout_failure_logs_persisted_progress_before_overwriting_message(): void
    {
        $exception = new RuntimeException('RunIndexTask has timed out.');
        $builder   = $this->createMock(Builder::class);
        $builder->method('where')->willReturnSelf();
        $builder->method('whereIn')->willReturnSelf();
        $builder->expects($this->once())->method('update')->with($this->callback(fn ($values) => $values['status'] === 'failed' && $values['active_key'] === null))->willReturn(1);
        $tasks = $this->getMockBuilder(IndexTaskService::class)->onlyMethods(['find', 'table'])->getMock();
        $tasks->method('table')->willReturn($builder);
        $tasks->method('find')->willReturn([
            'catalog' => 'real', 'locales' => ['en'], 'processed' => 900,
            'message' => '语言 en 已写入 900 条商品', 'started_at' => '2026-10-08 12:00:00',
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Meilisearch 索引任务失败', $this->callback(
            fn ($context) => $context['task_id'] === 'task-id'
                && $context['processed']         === 900
                && $context['last_message']      === '语言 en 已写入 900 条商品'
                && $context['exception']         === $exception
        ));
        Log::swap($logger);

        $tasks->fail('task-id', $exception);
    }

    public function test_missing_queue_configuration_does_not_create_a_task_lock(): void
    {
        $tasks = $this->getMockBuilder(IndexTaskService::class)->onlyMethods(['create', 'fail'])->getMock();
        $tasks->expects($this->never())->method('create');
        $tasks->expects($this->never())->method('fail');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('请同步根目录 config/queue.php');
        $tasks->submit('rebuild', 'real', ['en'], null);
    }

    public function test_invalid_retry_window_does_not_create_a_task_lock(): void
    {
        $this->loadQueueConfig();
        config(['queue.connections.meilisearch.retry_after' => 90]);
        $tasks = $this->getMockBuilder(IndexTaskService::class)->onlyMethods(['create'])->getMock();
        $tasks->expects($this->never())->method('create');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('retry_after 必须大于任务超时');
        $tasks->submit('rebuild', 'real', ['en'], null);
    }

    public function test_dispatch_failure_releases_only_the_task_created_by_this_submission(): void
    {
        $this->loadQueueConfig();
        $exception  = new RuntimeException('queue insert failed');
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch')->willThrowException($exception);
        Bus::swap($dispatcher);
        $tasks = $this->getMockBuilder(IndexTaskService::class)->onlyMethods(['create', 'fail'])->getMock();
        $tasks->expects($this->once())->method('create')->willReturn(['id' => 'new-task']);
        $tasks->expects($this->once())->method('fail')->with('new-task', $exception, true);

        try {
            $tasks->submit('rebuild', 'real', ['en'], null);
            $this->fail('应保留原始投递异常');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    public function test_existing_task_conflict_does_not_release_another_task(): void
    {
        $this->loadQueueConfig();
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');
        Bus::swap($dispatcher);
        $tasks = $this->getMockBuilder(IndexTaskService::class)->onlyMethods(['create', 'fail'])->getMock();
        $tasks->method('create')->willThrowException(new RuntimeException('已有索引任务正在执行'));
        $tasks->expects($this->never())->method('fail');

        $this->expectExceptionMessage('已有索引任务正在执行');
        $tasks->submit('rebuild', 'real', ['en'], null);
    }

    public function test_submission_failure_does_not_release_a_task_claimed_by_a_worker(): void
    {
        $builder = $this->createMock(Builder::class);
        $builder->expects($this->once())->method('where')->with('id', 'task-id')->willReturnSelf();
        $builder->expects($this->once())->method('whereIn')->with('status', ['queued'])->willReturnSelf();
        // 模拟任务已变成 running，带 queued 条件的更新不能改动它。
        $builder->expects($this->once())->method('update')->willReturn(0);
        $tasks = $this->getMockBuilder(IndexTaskService::class)->onlyMethods(['find', 'table'])->getMock();
        $tasks->method('find')->willReturn(['status' => 'running']);
        $tasks->method('table')->willReturn($builder);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        Log::swap($logger);

        $tasks->fail('task-id', new RuntimeException('queue event failed'), onlyQueued: true);
    }

    public function test_successful_submission_dispatches_the_created_task(): void
    {
        $this->loadQueueConfig();
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch')->with($this->callback(
            fn ($job) => $job instanceof RunIndexTask && $job->taskId === 'new-task' && $job->connection === 'meilisearch'
        ));
        Bus::swap($dispatcher);
        $task  = ['id' => 'new-task', 'status' => 'queued'];
        $tasks = $this->getMockBuilder(IndexTaskService::class)->onlyMethods(['create', 'fail'])->getMock();
        $tasks->expects($this->once())->method('create')->with('rebuild', 'real', ['en'], 7)->willReturn($task);
        $tasks->expects($this->never())->method('fail');

        $this->assertSame($task, $tasks->submit('rebuild', 'real', ['en'], 7));
    }

    private function loadQueueConfig(): array
    {
        $queue = require __DIR__ . '/../../../config/queue.php';
        config(['queue' => $queue]);

        return $queue;
    }
}
