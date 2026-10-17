<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('fbr_pos_transactions') && !Schema::hasColumn('fbr_pos_transactions', 'fbr_agent_claimed_at')) {
            Schema::table('fbr_pos_transactions', fn (Blueprint $t) => $t->timestamp('fbr_agent_claimed_at')->nullable()->index());
        }
    }
    public function down(): void {}
};
