<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        foreach (['pos_transactions'=>'fbr_service_charge','pos_day_close_reports'=>'total_fbr_fee'] as $table=>$column) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table,$column))
                Schema::table($table,fn(Blueprint $t)=>$t->decimal($column,12,2)->default(0));
        }
    }
    public function down(): void {
        // Monetary snapshots are deliberately retained on rollback.
    }
};
