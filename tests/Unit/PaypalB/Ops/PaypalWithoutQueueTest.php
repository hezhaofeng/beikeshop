<?php

namespace Tests\Unit\PaypalB\Ops;

use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Plugin\PaypalA\Jobs\PaypalAFulfillmentSyncJob;
use Plugin\PaypalB\Jobs\PaypalBCallbackJob;
use Plugin\PaypalB\Jobs\PaypalBRefundJob;
use Plugin\PaypalB\Jobs\PaypalBTrackingJob;
use Plugin\PaypalB\Jobs\PaypalBWebhookJob;
use Plugin\PaypalB\Models\PaypalBRefund;
use Plugin\PaypalB\Models\PaypalBWebhookEvent;
use Plugin\PaypalB\Services\PaypalBRefundService;
use Plugin\PaypalB\Services\PaypalBTransactionService;
use TorMorten\Eventy\Events;

class PaypalWithoutQueueTest extends OpsTestCase
{
    private $originalResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalResolver = Model::getConnectionResolver();
        $resolver               = new ConnectionResolver(['mysql' => new MySqlConnection(null, 'testing')]);
        $resolver->setDefaultConnection('mysql');
        Model::setConnectionResolver($resolver);
        $this->app->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $this->app));
    }

    protected function tearDown(): void
    {
        if ($this->originalResolver) {
            Model::setConnectionResolver($this->originalResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        parent::tearDown();
    }

    public function test_payment_tasks_run_in_the_request_process_without_a_queue_connection(): void
    {
        $calls = [];
        $bus   = new Dispatcher($this->app, static function () {
            throw new \RuntimeException('不应连接队列');
        });
        $this->app->instance(DispatcherContract::class, $bus);
        $classes = [PaypalAFulfillmentSyncJob::class, PaypalBCallbackJob::class, PaypalBRefundJob::class, PaypalBTrackingJob::class];
        foreach ([...$classes, PaypalBWebhookJob::class] as $class) {
            $this->app->bindMethod([$class, 'handle'], static function ($job) use (&$calls): bool {
                $calls[] = get_class($job);

                return true;
            });
        }

        foreach ($classes as $class) {
            $class::dispatchAfterResponse(1);
        }
        $this->assertSame([], $calls, '普通任务应在响应后执行');
        $this->assertTrue(PaypalBWebhookJob::dispatchSync(1));
        $this->assertSame([PaypalBWebhookJob::class], $calls, 'Webhook 必须在返回成功前处理');
        $this->app->terminate();
        $this->assertSame([PaypalBWebhookJob::class, ...$classes], $calls);
    }

    public function test_paypal_unpaid_orders_are_retained_while_other_methods_keep_native_rules(): void
    {
        $this->app->instance('eventy', new Events);
        $orders = collect([
            (object) ['id' => 1, 'payment_method_code' => 'paypal_a'],
            (object) ['id' => 2, 'payment_method_code' => 'paypal_b'],
            (object) ['id' => 3, 'payment_method_code' => 'stripe'],
        ]);
        $this->assertCount(3, hook_filter('console.process_order.unpaid_orders', $orders));

        foreach ([\Plugin\PaypalA\Bootstrap::class, \Plugin\PaypalB\Bootstrap::class] as $class) {
            $register = new \ReflectionMethod($class, 'registerUnpaidOrderRetention');
            $register->setAccessible(true);
            $register->invoke(new $class);
        }

        $this->assertSame([3], hook_filter('console.process_order.unpaid_orders', $orders)->pluck('id')->all());
        $this->assertCount(3, $orders, '过滤不能修改原集合');
    }

    public function test_active_webhook_lease_is_not_acknowledged_or_overwritten(): void
    {
        $connection = $this->webhookConnection('processing');
        $connection->expects($this->never())->method('update');
        $service = $this->createMock(PaypalBTransactionService::class);
        $service->expects($this->never())->method('findWebhookTransaction');

        $this->assertFalse((new PaypalBWebhookJob(7))->handle($service));
    }

    public function test_processed_webhook_is_acknowledged_without_reapplying_payment(): void
    {
        $connection = $this->webhookConnection('processed');
        $connection->expects($this->never())->method('update');
        $service = $this->createMock(PaypalBTransactionService::class);
        $service->expects($this->never())->method('applyWebhook');

        $this->assertTrue((new PaypalBWebhookJob(7))->handle($service));
    }

    public function test_webhook_failure_is_recorded_and_propagated_for_provider_retry(): void
    {
        $connection = $this->webhookConnection('received');
        $updates    = [];
        $connection->method('update')->willReturnCallback(static function ($sql, $bindings) use (&$updates): int {
            $updates[] = $bindings;

            return 1;
        });
        $service = $this->createMock(PaypalBTransactionService::class);
        $service->method('findWebhookTransaction')->willThrowException(new \RuntimeException('临时故障'));

        try {
            (new PaypalBWebhookJob(7))->handle($service);
            $this->fail('异常必须传回 Webhook 接口');
        } catch (\RuntimeException $exception) {
            $this->assertSame('临时故障', $exception->getMessage());
        }
        $this->assertContains('processing', $updates[0]);
        $this->assertContains('failed', $updates[count($updates) - 1]);
    }

    public function test_stalled_refunds_can_be_recovered_but_active_and_terminal_refunds_cannot(): void
    {
        foreach (['pending', 'processing'] as $status) {
            $refund = (new PaypalBRefund)->forceFill(['status' => $status, 'source' => 'api', 'updated_at' => now()]);
            $this->assertFalse($refund->canRetry());
            $refund->updated_at = now()->subMinutes(6);
            $this->assertTrue($refund->canRetry());
            $refund->source = 'webhook';
            $this->assertFalse($refund->canRetry());
        }
        foreach (['completed', 'failed'] as $status) {
            $refund = (new PaypalBRefund)->forceFill(['status' => $status, 'source' => 'api', 'updated_at' => now()->subDay()]);
            $this->assertFalse($refund->canRetry());
        }
        $this->assertTrue((new PaypalBRefund)->forceFill(['status' => 'reconciling'])->canRetry());
    }

    public function test_refund_retry_reuses_the_original_id_and_checks_the_locked_record(): void
    {
        $refund = (new PaypalBRefund)->forceFill([
            'id'         => 9, 'source' => 'api', 'status' => 'pending', 'request_id' => 'PB-REF-original',
            'updated_at' => now()->subMinutes(6),
        ]);
        $connection = $this->getMockBuilder(MySqlConnection::class)
            ->setConstructorArgs([null, 'testing'])
            ->onlyMethods(['select', 'update', 'transaction'])->getMock();
        $connection->method('select')->willReturnCallback(static fn () => [$refund->getAttributes()]);
        $connection->method('transaction')->willReturnCallback(static fn ($callback) => $callback());
        $connection->expects($this->once())->method('update')->willReturnCallback(function ($sql): int {
            $this->assertStringNotContainsString('request_id', $sql, '重试不应更新 PayPal 幂等号');

            return 1;
        });
        $resolver = new ConnectionResolver(['mysql' => $connection]);
        $resolver->setDefaultConnection('mysql');
        Model::setConnectionResolver($resolver);
        DB::swap($connection);
        $bus = new Dispatcher($this->app);
        $this->app->instance(DispatcherContract::class, $bus);
        $ids = [];
        $this->app->bindMethod([PaypalBRefundJob::class, 'handle'], static function ($job) use (&$ids): void {
            $ids[] = $job->refundId;
        });

        $service = new PaypalBRefundService;
        $service->retry(clone $refund);
        $this->assertSame([], $ids);
        $this->app->terminate();
        $this->assertSame([9], $ids);

        // 页面模型仍为待处理，但数据库已完成退款时，行锁内必须拒绝旧请求。
        $stale          = clone $refund;
        $refund->status = 'completed';
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->retry($stale);
    }

    private function webhookConnection(string $status): MySqlConnection
    {
        $event = (new PaypalBWebhookEvent)->forceFill([
            'id' => 7, 'account_id' => 1, 'status' => $status, 'payload' => ['id' => 'WH-1'], 'updated_at' => now(),
        ]);
        $connection = $this->getMockBuilder(MySqlConnection::class)
            ->setConstructorArgs([null, 'testing'])
            ->onlyMethods(['select', 'update', 'transaction'])->getMock();
        $connection->method('select')->willReturn([$event->getAttributes()]);
        $connection->method('transaction')->willReturnCallback(static fn ($callback) => $callback());
        $resolver = new ConnectionResolver(['mysql' => $connection]);
        $resolver->setDefaultConnection('mysql');
        Model::setConnectionResolver($resolver);
        DB::swap($connection);

        return $connection;
    }
}
