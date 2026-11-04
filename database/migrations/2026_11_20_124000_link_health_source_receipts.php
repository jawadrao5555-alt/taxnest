<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('health_payments', 'collection_source')) {
            Schema::table('health_payments', function (Blueprint $t) {
                $t->string('collection_source', 24)->nullable();
                $t->unsignedBigInteger('collection_source_id')->nullable();
                $t->unique(['company_id','collection_source','collection_source_id','health_bill_id'], 'health_collection_source_bill');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('health_payments', 'collection_source')) {
            Schema::table('health_payments', function (Blueprint $t) {
                $t->dropUnique('health_collection_source_bill');
                $t->dropColumn(['collection_source', 'collection_source_id']);
            });
        }
    }
};
