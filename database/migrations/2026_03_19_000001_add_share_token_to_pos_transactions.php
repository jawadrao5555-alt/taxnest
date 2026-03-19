<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_transactions', 'share_token')) {
                $table->string('share_token', 64)->nullable()->unique()->after('exempt_amount');
            }
            if (!Schema::hasColumn('pos_transactions', 'share_token_created_at')) {
                $table->timestamp('share_token_created_at')->nullable()->after('share_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropColumn(['share_token', 'share_token_created_at']);
        });
    }
};
