<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('product_type', 10)->default('di')->after('status');
            $table->timestamp('deleted_at')->nullable()->after('updated_at');
            $table->string('deleted_reason')->nullable()->after('deleted_at');
        });

        DB::table('companies')
            ->whereNotNull('pra_pos_id')
            ->where('pra_pos_id', '!=', '')
            ->update(['product_type' => 'pos']);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['product_type', 'deleted_at', 'deleted_reason']);
        });
    }
};
