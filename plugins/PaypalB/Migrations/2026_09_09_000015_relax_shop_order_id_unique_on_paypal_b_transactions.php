<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'paypal_b_transactions';

    private const UNIQUE_INDEX = 'paypal_b_transactions_shop_order_id_unique';

    private const PLAIN_INDEX = 'paypal_b_transactions_shop_order_id_index';

    /**
     * 000010 给 shop_order_id 加的是唯一索引，但同一本站订单会随会话过期、取消或重试
     * 产生多条 local 交易，它们都必须指向同一个订单；跨站镜像订单同样允许被同一订单号的
     * 后续交易复用。“一笔收款只建一条订单”由 createFromTransaction 里的行锁保证，
     * 这里只需要普通查询索引。
     */
    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'shop_order_id')) {
            return;
        }

        $indexes = $this->indexNames();
        Schema::table(self::TABLE, function (Blueprint $table) use ($indexes): void {
            if (in_array(self::UNIQUE_INDEX, $indexes, true)) {
                $table->dropUnique(self::UNIQUE_INDEX);
            }
            if (! in_array(self::PLAIN_INDEX, $indexes, true)) {
                $table->index('shop_order_id', self::PLAIN_INDEX);
            }
        });
    }

    /**
     * 回滚只在没有多条交易指向同一订单时才能成功；存在重复引用时应保留普通索引。
     */
    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'shop_order_id')) {
            return;
        }

        $indexes = $this->indexNames();
        Schema::table(self::TABLE, function (Blueprint $table) use ($indexes): void {
            if (in_array(self::PLAIN_INDEX, $indexes, true)) {
                $table->dropIndex(self::PLAIN_INDEX);
            }
            if (! in_array(self::UNIQUE_INDEX, $indexes, true)) {
                $table->unique('shop_order_id', self::UNIQUE_INDEX);
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function indexNames(): array
    {
        return array_map(
            static fn (array $index): string => strtolower((string) ($index['name'] ?? '')),
            Schema::getIndexes(self::TABLE)
        );
    }
};
