<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('restaurant_orders', 'edit_revision')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->unsignedInteger('edit_revision')->default(0)->after('status');
            });
        }
        if (!Schema::hasColumn('restaurant_orders', 'last_edit_uuid')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->string('last_edit_uuid', 64)->nullable()->after('edit_revision');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurant_orders', 'last_edit_uuid')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->dropColumn('last_edit_uuid');
            });
        }
        if (Schema::hasColumn('restaurant_orders', 'edit_revision')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->dropColumn('edit_revision');
            });
        }
    }
};