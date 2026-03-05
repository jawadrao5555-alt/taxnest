<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->string('submission_hash')->nullable()->after('pra_status');
            $table->index('submission_hash');
        });
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropIndex(['submission_hash']);
            $table->dropColumn('submission_hash');
        });
    }
};
