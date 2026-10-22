<?php

namespace Tests\Unit\PaypalB\Ops;

use App\Console\Kernel;
use App\Http\Middleware\SetAppTimezone;
use Illuminate\Database\MySqlConnection;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Plugin\PaypalB\Console\ImportPaypalBCatalog;
use Plugin\PaypalB\Controllers\PaypalBAdminController;
use Plugin\PaypalB\Models\PaypalBAccount;
use Plugin\PaypalB\Services\PaypalBAccountQuota;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * 限额查询、桥接限流与时区、后台配置白名单、商品库清空保护、database cache 清理。
 */
class PaypalBOperationsTest extends OpsTestCase
{
    public function test_quota_usage_query_is_index_friendly_and_skips_denied_captures(): void
    {
        $account = (new PaypalBAccount)->forceFill(['id' => 7]);
        $query   = PaypalBAccountQuota::usageQuery(
            $account,
            Carbon::parse('2026-09-01 00:00:00'),
            Carbon::parse('2026-09-25 00:00:00'),
            new MySqlConnection(static fn () => null, 'shop')
        );
        $sql = strtolower($query->toSql());

        $this->assertStringNotContainsString('coalesce', $sql);
        $this->assertStringContainsString('`account_id` = ?', $sql);
        $this->assertStringContainsString('`paypal_capture_id` is not null', $sql);
        $this->assertStringContainsString('`status` <> ?', $sql);
        $this->assertStringContainsString('(`paid_at` >= ? or (`paid_at` is null and `created_at` >= ?))', $sql);
        $this->assertContains('failed', $query->getBindings());
    }

    public function test_bridge_endpoints_use_dedicated_limiter_and_shop_timezone(): void
    {
        $router = new Router($this->app['events'], $this->app);
        Route::swap($router);
        require __DIR__ . '/../../../../plugins/PaypalB/Routes/bridge.php';

        $bridge  = 0;
        $webhook = 0;
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $middleware = $route->middleware();
            $this->assertContains(SetAppTimezone::class, $middleware, $route->uri());
            if (str_contains($route->uri(), '/bridge/')) {
                $bridge++;
                $this->assertContains('throttle:6000,1,paypal_b_bridge', $middleware, $route->uri());
                $this->assertNotContains('throttle:api', $middleware, $route->uri());
            } else {
                $webhook++;
                $this->assertContains('throttle:api', $middleware, $route->uri());
            }
        }

        $this->assertSame(6, $bridge);
        $this->assertSame(2, $webhook);
    }

    public function test_settings_whitelist_matches_columns_and_covers_the_form(): void
    {
        $fields  = (new \ReflectionMethod(PaypalBAdminController::class, 'settingFieldNames'))->invoke(null);
        $columns = array_column(require __DIR__ . '/../../../../plugins/PaypalB/columns.php', 'name');
        $this->assertSame($columns, $fields);

        $form = file_get_contents(__DIR__ . '/../../../../plugins/PaypalB/Views/admin/config_form.blade.php');
        preg_match_all('/name="([a-z_]+)"/', $form, $matches);
        $this->assertSame([], array_values(array_diff(array_unique($matches[1]), $fields)));

        // 已保存的桥接密钥不再回显到页面。
        $this->assertStringNotContainsString("\$value('a_request_verification_secret')", $form);
        $this->assertStringNotContainsString("\$value('a_callback_signing_secret')", $form);
        $this->assertStringContainsString('留空保留原值', $form);
    }

    public function test_truncate_import_requires_force_in_production(): void
    {
        $this->app['env'] = 'production';

        $blocked = $this->runImport(['--truncate' => true]);
        $this->assertSame(1, $blocked->getStatusCode());
        $this->assertStringContainsString('必须同时指定 --force', $blocked->getDisplay());

        // 生产环境即使带 --force 也要交互确认；非交互模式视为取消，不会调用导入器。
        $cancelled = $this->runImport(['--truncate' => true, '--force' => true]);
        $this->assertSame(0, $cancelled->getStatusCode());
        $this->assertStringContainsString('已取消', $cancelled->getDisplay());
        $this->assertStringNotContainsString('导入', $cancelled->getDisplay());
    }

    public function test_expired_database_cache_rows_are_pruned_in_batches(): void
    {
        $database = new FakeCacheDatabase(['cache' => 12001, 'cache_locks' => 3]);
        $this->app->instance('db', $database);
        $this->app->instance('db.schema', $database);

        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(Kernel::class, 'pruneExpiredDatabaseCache'))->invoke($kernel);

        $this->assertSame(['cache' => 3, 'cache_locks' => 1], $database->deleteBatches);
        $this->assertSame(['cache' => 0, 'cache_locks' => 0], $database->expired);

        // 表不存在时跳过，不报错。
        $missing = new FakeCacheDatabase([]);
        $this->app->instance('db', $missing);
        $this->app->instance('db.schema', $missing);
        (new \ReflectionMethod(Kernel::class, 'pruneExpiredDatabaseCache'))->invoke($kernel);
        $this->assertSame([], $missing->deleteBatches);
    }

    private function runImport(array $options): CommandTester
    {
        $command = new ImportPaypalBCatalog;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute(['file' => 'missing.json'] + $options, ['interactive' => false]);

        return $tester;
    }
}

/**
 * 模拟 database cache 表：每次 delete() 最多删除 limit 条已过期记录。
 */
class FakeCacheDatabase
{
    /** @var array<string, int> */
    public array $deleteBatches = [];

    /** @param array<string, int> $expired 表名 => 已过期行数 */
    public function __construct(public array $expired)
    {
    }

    public function connection($name = null): self
    {
        return $this;
    }

    public function getSchemaBuilder(): self
    {
        return $this;
    }

    public function hasTable(string $table): bool
    {
        return array_key_exists($table, $this->expired);
    }

    public function table(string $table): object
    {
        $database = $this;

        return new class($database, $table)
        {
            private int $limit = PHP_INT_MAX;

            public function __construct(private readonly FakeCacheDatabase $database, private readonly string $table)
            {
            }

            public function where(string $column, string $operator, int $value): self
            {
                return $this;
            }

            public function limit(int $limit): self
            {
                $this->limit = $limit;

                return $this;
            }

            public function delete(): int
            {
                $deleted = min($this->limit, $this->database->expired[$this->table]);
                $this->database->expired[$this->table] -= $deleted;
                $this->database->deleteBatches[$this->table] = ($this->database->deleteBatches[$this->table] ?? 0) + 1;

                return $deleted;
            }
        };
    }
}
