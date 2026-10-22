<?php

namespace Tests\Unit\PaypalB\Ops;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * PaypalB 运维层单元测试的公共环境：只起一个不连数据库的 Laravel 容器，
 * 绑定日志、时间、配置、异常上报等最小服务，避免依赖本地 MySQL。
 */
abstract class OpsTestCase extends TestCase
{
    protected Application $app;

    protected AbstractLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__, 4));
        $this->app->instance('config', new Repository([
            'app'      => ['timezone' => 'UTC'],
            'cache'    => ['stores' => ['database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null, 'lock_connection' => null]]],
            'database' => ['default' => 'mysql'],
        ]));
        $this->app->instance('date', new DateFactory);
        $this->logger = new class extends AbstractLogger
        {
            /** @var array<int, array{level: string, message: string, context: array}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $this->app->instance('log', $this->logger);
        $this->app->instance(ExceptionHandler::class, new class implements ExceptionHandler
        {
            public function report(\Throwable $e)
            {
            }

            public function shouldReport(\Throwable $e)
            {
                return false;
            }

            public function render($request, \Throwable $e)
            {
                throw $e;
            }

            public function renderForConsole($output, \Throwable $e)
            {
            }
        });

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Model::encryptUsing(new Encrypter(random_bytes(32), 'AES-256-CBC'));
    }

    protected function tearDown(): void
    {
        Model::encryptUsing(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * @return array<int, string>
     */
    protected function loggedMessages(string $level): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record['message'],
            array_filter($this->logger->records, static fn (array $record): bool => $record['level'] === $level)
        ));
    }
}
