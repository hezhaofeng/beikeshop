<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->string('account_fingerprint', 64)->nullable()->after('account_id');
        });

        // 初始版本把 API Secret 作为普通 text 保存；升级时只转换尚未被 Laravel 加密的值。
        DB::table('paypal_b_accounts')
            ->whereNotNull('client_secret')
            ->select(['id', 'client_secret'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $secret = trim((string) $row->client_secret);
                if ($secret === '') {
                    return;
                }

                try {
                    Crypt::decryptString($secret);

                    return;
                } catch (\Throwable) {
                    // 不是 Laravel 加密格式，按旧版本明文凭据处理。
                }

                DB::table('paypal_b_accounts')
                    ->where('id', $row->id)
                    ->update(['client_secret' => Crypt::encryptString($secret)]);
            });

        // 旧版本若已经写入合规快照，encrypted:array 需要先把原始 JSON 转成加密 JSON。
        foreach (['buyer_snapshot', 'shipping_address', 'fulfillment_evidence'] as $column) {
            DB::table('paypal_b_transactions')
                ->whereNotNull($column)
                ->select(['id', $column])
                ->orderBy('id')
                ->each(function (object $row) use ($column): void {
                    $value = (string) $row->{$column};
                    if ($value === '' || ! is_array(json_decode($value, true))) {
                        return;
                    }

                    DB::table('paypal_b_transactions')
                        ->where('id', $row->id)
                        ->update([$column => Crypt::encryptString($value)]);
                });
        }
    }

    public function down(): void
    {
        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->dropColumn('account_fingerprint');
        });

        // 加密数据不在回滚时降级为明文，避免回滚操作扩大敏感数据暴露面。
    }
};
