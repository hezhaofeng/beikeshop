<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('paypal_b_transactions', 'presentation')) {
            return;
        }

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            // redirect：买家顶层跳转到 B 站托管页（历史默认）；
            // embedded：A 站支付页以 iframe 内嵌 B 站结账页，页面按会话记录而非请求参数决定形态。
            $table->string('presentation', 16)
                ->default('redirect')
                ->after('source')
                ->comment('结账页呈现方式：redirect=顶层跳转, embedded=A 站内嵌');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('paypal_b_transactions', 'presentation')) {
            return;
        }

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->dropColumn('presentation');
        });
    }
};
