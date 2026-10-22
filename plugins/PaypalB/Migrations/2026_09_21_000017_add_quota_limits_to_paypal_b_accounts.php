<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACCOUNTS = 'paypal_b_accounts';

    private const TRANSACTIONS = 'paypal_b_transactions';

    private const USAGE_INDEX = 'paypal_b_transactions_account_paid_index';

    /**
     * MySQL 的 DDL 不在事务内，中途失败会留下部分列；每一列、每个索引都单独判断，重跑时补齐缺失部分。
     */
    public function up(): void
    {
        // 风控限额：全部可空，空表示不限。金额按 B 站默认币种计，用量由已收款会话实时统计，不另存计数。
        $columns = [
            'daily_amount_limit'   => fn (Blueprint $table) => $table->decimal('daily_amount_limit', 16, 4)->nullable()->after('priority')->comment('每日收款金额上限（默认币种），空=不限'),
            'daily_count_limit'    => fn (Blueprint $table) => $table->unsignedInteger('daily_count_limit')->nullable()->after('daily_amount_limit')->comment('每日收款笔数上限，空=不限'),
            'monthly_amount_limit' => fn (Blueprint $table) => $table->decimal('monthly_amount_limit', 16, 4)->nullable()->after('daily_count_limit')->comment('每月收款金额上限（默认币种），空=不限'),
            'monthly_count_limit'  => fn (Blueprint $table) => $table->unsignedInteger('monthly_count_limit')->nullable()->after('monthly_amount_limit')->comment('每月收款笔数上限，空=不限'),
        ];
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn(self::ACCOUNTS, $column)) {
                Schema::table(self::ACCOUNTS, function (Blueprint $table) use ($definition): void {
                    $definition($table);
                });
            }
        }

        // 用量统计按 account_id + 收款时间聚合，补一个覆盖索引避免账号多时全表扫描。
        if (! $this->hasIndex(self::TRANSACTIONS, self::USAGE_INDEX)) {
            Schema::table(self::TRANSACTIONS, function (Blueprint $table): void {
                $table->index(['account_id', 'paid_at'], self::USAGE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex(self::TRANSACTIONS, self::USAGE_INDEX)) {
            Schema::table(self::TRANSACTIONS, function (Blueprint $table): void {
                $table->dropIndex(self::USAGE_INDEX);
            });
        }

        $columns = array_values(array_filter(
            ['daily_amount_limit', 'daily_count_limit', 'monthly_amount_limit', 'monthly_count_limit'],
            static fn (string $column): bool => Schema::hasColumn(self::ACCOUNTS, $column)
        ));
        if ($columns !== []) {
            Schema::table(self::ACCOUNTS, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if (strtolower((string) ($existing['name'] ?? '')) === strtolower($index)) {
                return true;
            }
        }

        return false;
    }
};
