<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_organizations', function (Blueprint $table) {
            $table->foreignId('linked_company_id')->nullable()->unique()->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_organizations', function (Blueprint $table) {
            $table->dropForeign(['linked_company_id']);
            $table->dropUnique(['linked_company_id']);
            $table->dropColumn('linked_company_id');
        });
    }
};
