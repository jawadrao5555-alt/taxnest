<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_transactions')
            && !Schema::hasColumn('pos_transactions', 'rider_assignment_revision')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->uuid('rider_assignment_revision')->nullable()
                    ->after('rider_assigned_at');
            });
        }
    }

    public function down(): void
    {
        // Assignment revisions participate in immutable completion evidence.
        // Keep the additive column on rollback rather than invalidating queued
        // rider events or making old evidence unverifiable.
    }
};