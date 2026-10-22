<?php

namespace Plugin\PaypalB\Console;

use Beike\Repositories\SettingRepo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugin\PaypalB\Models\PaypalBTransaction;

class PurgePaypalBData extends Command
{
    protected $signature = 'paypal-b:purge
        {--accounts : 同时删除收款账号池，之后需要重新录入 Client ID、Secret 和 Webhook ID}
        {--settings : 同时删除插件配置，插件会回到未配置、未启用状态}
        {--dry-run : 只统计将被清除的数据，不执行删除}
        {--force : 不再交互确认；生产环境必须显式指定}';

    protected $description = '清空 PaypalB 产生的全部数据：跨站镜像订单、支付会话、Webhook 事件、退款记录、队列任务与缓存。';

    private const PLUGIN_TABLES = [
        'paypal_b_refunds',
        'paypal_b_webhook_events',
        'paypal_b_transactions',
        'paypal_b_rotation_states',
        'paypal_b_accounts',
    ];

    /**
     * 镜像订单是插件写进核心订单表的副产物，必须先于交易表删除：
     * shop_order_id 关联一旦随交易表清掉，这些订单就再也无法与本站真实订单区分。
     */
    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('生产环境会同时删除拒付举证快照和退款幂等记录，必须显式加 --force 才能执行。');

            return self::FAILURE;
        }
        foreach (self::PLUGIN_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("未找到数据表 {$table}，PaypalB 可能尚未安装或迁移未执行完整。");

                return self::FAILURE;
            }
        }

        $mirrorOrderIds = $this->mirrorOrderIds();
        $this->table(
            ['将清除', '行数'],
            collect($this->countRows($mirrorOrderIds))
                ->map(static fn (int $count, string $label): array => [$label, $count])
                ->values()
                ->all()
        );
        $this->line('本站真实订单不会被删除，只清除它们在插件里的支付会话记录。');

        if ($this->option('dry-run')) {
            $this->info('dry-run：未做任何修改。');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('以上数据将被永久删除且无法恢复，确认继续？')) {
            $this->warn('已取消。');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($mirrorOrderIds): void {
            $this->deleteMirrorOrders($mirrorOrderIds);

            DB::table('paypal_b_refunds')->delete();
            DB::table('paypal_b_webhook_events')->delete();
            DB::table('paypal_b_transactions')->delete();
            // 轮询状态行必须保留，账号选择器依赖它存在；只把指针归零。
            DB::table('paypal_b_rotation_states')->update(['last_account_id' => null, 'updated_at' => now()]);

            if ($this->option('accounts')) {
                DB::table('paypal_b_accounts')->delete();
            } else {
                DB::table('paypal_b_accounts')->update([
                    'failure_count'          => 0,
                    'failure_cooldown_until' => null,
                    'last_error'             => null,
                    'last_selected_at'       => null,
                    'last_success_at'        => null,
                    'updated_at'             => now(),
                ]);
            }

            $this->deleteQueueEntries();
            $this->deleteCacheEntries();

            if ($this->option('settings')) {
                DB::table('settings')->where('type', 'plugin')->where('space', 'paypal_b')->delete();
            }
        });

        if ($this->option('settings')) {
            // 插件配置在启动时缓存进 config，不清缓存的话旧配置会继续生效。
            SettingRepo::clearCache();
        }

        $this->info('PaypalB 数据已清空。');
        if (! $this->option('accounts')) {
            $this->line('收款账号池已保留并重置故障计数；如需一并删除请加 --accounts。');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function mirrorOrderIds(): array
    {
        $linked = PaypalBTransaction::query()
            ->where('source', '<>', PaypalBTransaction::SOURCE_LOCAL)
            ->whereNotNull('shop_order_id')
            ->pluck('shop_order_id');

        // 旧版本建过、但交易表已丢失关联的镜像单，只能靠插件早期写入订单备注的固定文案识别；
        // 新版镜像订单备注不再包含来源站信息，改由交易表 shop_order_id 关联。
        $orphaned = DB::table('orders')
            ->where('payment_method_code', 'paypal_b')
            ->where('customer_id', 0)
            ->where('comment', 'like', '%A 站订单号：%')
            ->pluck('id');

        return $linked->merge($orphaned)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, int> $orderIds
     */
    private function deleteMirrorOrders(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        foreach (['order_payments', 'order_histories', 'order_totals', 'order_products', 'order_shipments'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('order_id', $orderIds)->delete();
            }
        }
        DB::table('orders')->whereIn('id', $orderIds)->delete();
    }

    private function deleteQueueEntries(): void
    {
        if (Schema::hasTable('jobs')) {
            DB::table('jobs')->where('queue', 'paypal_b')->delete();
        }
        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')->where('payload', 'like', '%PaypalB%')->delete();
        }
    }

    /**
     * 插件固定使用 database cache 存 OAuth token、限流计数、签名 nonce 和订单锁，
     * 键名都带 paypal_b 前缀；LIKE 里的下划线要转义成字面量。
     */
    private function deleteCacheEntries(): void
    {
        foreach ($this->cacheTables() as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('key', 'like', '%paypal\\_b%')->delete();
            }
        }
    }

    /**
     * @param array<int, int> $mirrorOrderIds
     * @return array<string, int>
     */
    private function countRows(array $mirrorOrderIds): array
    {
        $cacheEntries = 0;
        foreach ($this->cacheTables() as $table) {
            if (Schema::hasTable($table)) {
                $cacheEntries += DB::table($table)->where('key', 'like', '%paypal\\_b%')->count();
            }
        }

        $rows = [
            '跨站镜像订单（orders 及其明细）'                => count($mirrorOrderIds),
            '支付会话 paypal_b_transactions'         => DB::table('paypal_b_transactions')->count(),
            'Webhook 事件 paypal_b_webhook_events' => DB::table('paypal_b_webhook_events')->count(),
            '退款记录 paypal_b_refunds'              => DB::table('paypal_b_refunds')->count(),
            '队列任务 jobs（paypal_b）'                => Schema::hasTable('jobs') ? DB::table('jobs')->where('queue', 'paypal_b')->count() : 0,
            '失败任务 failed_jobs'                   => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('payload', 'like', '%PaypalB%')->count() : 0,
            '缓存条目 cache / cache_locks'           => $cacheEntries,
        ];
        if ($this->option('accounts')) {
            $rows['收款账号 paypal_b_accounts'] = DB::table('paypal_b_accounts')->count();
        }
        if ($this->option('settings')) {
            $rows['插件配置 settings'] = DB::table('settings')->where('type', 'plugin')->where('space', 'paypal_b')->count();
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function cacheTables(): array
    {
        return array_values(array_unique([
            (string) config('cache.stores.database.table', 'cache'),
            (string) config('cache.stores.database.lock_table', 'cache_locks'),
        ]));
    }
}
