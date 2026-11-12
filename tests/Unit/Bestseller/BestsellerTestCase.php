<?php

namespace Tests\Unit\Bestseller;

use Beike\Hook\Hook;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use TorMorten\Eventy\Events;

abstract class BestsellerTestCase extends TestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        // 仅装配选品服务依赖，不启动应用提供者或连接项目数据库。
        $this->app = new Application(dirname(__DIR__, 3));
        $this->app->instance('config', new Repository([
            'app'   => ['debug' => false],
            'beike' => ['admin_name' => 'admin'],
        ]));
        $this->app->instance('request', Request::create('/'));
        $this->app->instance('eventy', new Events);
        $this->app->instance('Hook', new Hook);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            $this->app->flush();
            Application::setInstance(null);
            parent::tearDown();
        }
    }
}
