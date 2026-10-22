<?php

namespace Tests\Unit\PaypalB\Ops;

use Illuminate\Database\Schema\Blueprint;

/**
 * 修复迁移回滚不删列；000017 / 000018 逐列、逐索引补齐，部分失败后重跑不会被整体跳过。
 */
class PaypalBMigrationsTest extends OpsTestCase
{
    private const DIR = __DIR__ . '/../../../../plugins/PaypalB/Migrations/';

    public function test_repair_migrations_do_not_drop_columns_on_rollback(): void
    {
        foreach ([
            '2026_09_09_000013_repair_source_on_paypal_b_transactions.php',
            '2026_09_09_000014_repair_buyer_locale_on_paypal_b_transactions.php',
        ] as $file) {
            $schema = $this->fakeSchema(['paypal_b_transactions' => ['source', 'buyer_locale']]);
            (require self::DIR . $file)->down();

            $this->assertSame([], $schema->calls, $file . ' 的 down() 不应触碰表结构');
        }
    }

    public function test_quota_migration_adds_only_missing_columns_and_index(): void
    {
        // 模拟上次在第二列后中断：前两列已存在，后两列和索引缺失。
        $schema = $this->fakeSchema(
            ['paypal_b_accounts' => ['priority', 'daily_amount_limit', 'daily_count_limit'], 'paypal_b_transactions' => ['account_id', 'paid_at']],
            ['paypal_b_transactions' => ['primary']]
        );

        (require self::DIR . '2026_09_21_000017_add_quota_limits_to_paypal_b_accounts.php')->up();

        $this->assertSame(['monthly_amount_limit', 'monthly_count_limit'], $schema->addedColumns('paypal_b_accounts'));
        $this->assertSame(['paypal_b_transactions_account_paid_index'], $schema->addedIndexes('paypal_b_transactions'));

        // 全部已存在时重跑不做任何变更。
        $schema = $this->fakeSchema(
            ['paypal_b_accounts' => ['daily_amount_limit', 'daily_count_limit', 'monthly_amount_limit', 'monthly_count_limit']],
            ['paypal_b_transactions' => ['PAYPAL_B_TRANSACTIONS_ACCOUNT_PAID_INDEX']]
        );
        (require self::DIR . '2026_09_21_000017_add_quota_limits_to_paypal_b_accounts.php')->up();
        $this->assertSame([], $schema->tableCalls());
    }

    public function test_tracking_migration_adds_only_missing_columns_and_index(): void
    {
        $schema = $this->fakeSchema(
            ['paypal_b_transactions' => ['fulfillment_updated_at', 'tracking_status', 'tracking_attempts', 'tracking_next_attempt_at']],
            ['paypal_b_transactions' => ['primary']]
        );

        (require self::DIR . '2026_09_22_000018_add_tracking_report_to_paypal_b_transactions.php')->up();

        $this->assertSame(['tracking_synced_at', 'tracking_last_error', 'tracking_payload'], $schema->addedColumns('paypal_b_transactions'));
        $this->assertSame(['paypal_b_transactions_tracking_index'], $schema->addedIndexes('paypal_b_transactions'));
    }

    /**
     * @param array<string, array<int, string>> $columns
     * @param array<string, array<int, string>> $indexes
     */
    private function fakeSchema(array $columns, array $indexes = []): FakeSchemaBuilder
    {
        $schema = new FakeSchemaBuilder($columns, $indexes);
        $this->app->instance('db.schema', $schema);

        return $schema;
    }
}

/**
 * 只实现迁移用到的 Schema 方法；table() 用真实 Blueprint 执行闭包，记录新增的列与索引。
 */
class FakeSchemaBuilder
{
    /** @var array<int, array{0: string, 1: string, 2?: Blueprint}> */
    public array $calls = [];

    /**
     * @param array<string, array<int, string>> $columns
     * @param array<string, array<int, string>> $indexes
     */
    public function __construct(private array $columns, private array $indexes)
    {
    }

    public function hasColumn(string $table, string $column): bool
    {
        $this->calls[] = ['hasColumn', $table];

        return in_array($column, $this->columns[$table] ?? [], true);
    }

    public function getIndexes(string $table): array
    {
        $this->calls[] = ['getIndexes', $table];

        return array_map(static fn (string $name): array => ['name' => $name], $this->indexes[$table] ?? []);
    }

    public function table(string $table, \Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $this->calls[] = ['table', $table, $blueprint];
        foreach ($blueprint->getAddedColumns() as $column) {
            $this->columns[$table][] = $column->name;
        }
    }

    /**
     * @return array<int, array>
     */
    public function tableCalls(): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === 'table'));
    }

    /**
     * @return array<int, string>
     */
    public function addedColumns(string $table): array
    {
        $names = [];
        foreach ($this->tableCalls() as $call) {
            if ($call[1] === $table) {
                foreach ($call[2]->getAddedColumns() as $column) {
                    $names[] = $column->name;
                }
            }
        }

        return $names;
    }

    /**
     * @return array<int, string>
     */
    public function addedIndexes(string $table): array
    {
        $names = [];
        foreach ($this->tableCalls() as $call) {
            if ($call[1] === $table) {
                foreach ($call[2]->getCommands() as $command) {
                    if ($command->name === 'index') {
                        $names[] = $command->index;
                    }
                }
            }
        }

        return $names;
    }
}
