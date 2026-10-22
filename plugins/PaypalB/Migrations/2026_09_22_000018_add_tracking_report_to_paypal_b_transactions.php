<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'paypal_b_transactions';

    private const TRACKING_INDEX = 'paypal_b_transactions_tracking_index';

    private const COLUMNS = [
        'tracking_status',
        'tracking_attempts',
        'tracking_next_attempt_at',
        'tracking_synced_at',
        'tracking_last_error',
        'tracking_payload',
    ];

    /**
     * 运单上报 PayPal 的 outbox 状态：与 A 站回调一样先落状态再投队列，失败按退避重试。
     *
     * MySQL 的 DDL 不在事务内，中途失败会留下部分列；每一列、每个索引都单独判断，重跑时补齐缺失部分。
     */
    public function up(): void
    {
        $columns = [
            'tracking_status'          => fn (Blueprint $table) => $table->string('tracking_status', 16)->default('idle')->after('fulfillment_updated_at')
                ->comment('运单上报 PayPal 状态：idle/pending/sending/sent/failed/abandoned'),
            'tracking_attempts'        => fn (Blueprint $table) => $table->unsignedInteger('tracking_attempts')->default(0)->after('tracking_status'),
            'tracking_next_attempt_at' => fn (Blueprint $table) => $table->timestamp('tracking_next_attempt_at')->nullable()->after('tracking_attempts'),
            // 最近一次把履约证据与 PayPal 侧核对一致的时间；早于 fulfillment_updated_at 说明有新运单待上报。
            'tracking_synced_at'       => fn (Blueprint $table) => $table->timestamp('tracking_synced_at')->nullable()->after('tracking_next_attempt_at'),
            'tracking_last_error'      => fn (Blueprint $table) => $table->text('tracking_last_error')->nullable()->after('tracking_synced_at'),
            // 已上报到 PayPal 的运单：按运单号记录 tracker_id、上报的承运商与状态。
            'tracking_payload'         => fn (Blueprint $table) => $table->json('tracking_payload')->nullable()->after('tracking_last_error'),
        ];
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($definition): void {
                    $definition($table);
                });
            }
        }

        if (! $this->hasIndex(self::TRACKING_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['tracking_status', 'tracking_next_attempt_at'], self::TRACKING_INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex(self::TRACKING_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::TRACKING_INDEX);
            });
        }

        $columns = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => Schema::hasColumn(self::TABLE, $column)
        ));
        if ($columns !== []) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function hasIndex(string $index): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $existing) {
            if (strtolower((string) ($existing['name'] ?? '')) === strtolower($index)) {
                return true;
            }
        }

        return false;
    }
};
