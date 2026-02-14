<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'registration_no')) {
                $table->string('registration_no', 100)->nullable()->after('owner_name');
            }
            if (!Schema::hasColumn('companies', 'mobile')) {
                $table->string('mobile', 50)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('companies', 'city')) {
                $table->string('city', 100)->nullable()->after('address');
            }
            if (!Schema::hasColumn('companies', 'website')) {
                $table->string('website', 255)->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['registration_no', 'mobile', 'city', 'website']);
        });
    }
};
