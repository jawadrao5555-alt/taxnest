<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('cnic', 20)->nullable()->after('ntn');
            $table->string('business_activity', 255)->nullable()->after('address');
            $table->string('owner_name', 255)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['cnic', 'business_activity', 'owner_name']);
        });
    }
};
