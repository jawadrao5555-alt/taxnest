<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('agent_core_events')) return;

        Schema::table('agent_core_events', function (Blueprint $table): void {
            if (!Schema::hasColumn('agent_core_events', 'event_scope')) {
                $table->json('event_scope')->nullable();
            }
            if (!Schema::hasColumn('agent_core_events', 'projection_status')) {
                $table->string('projection_status', 24)->nullable()->index();
            }
            if (!Schema::hasColumn('agent_core_events', 'projection_result')) {
                $table->json('projection_result')->nullable();
            }
            if (!Schema::hasColumn('agent_core_events', 'projection_error')) {
                $table->text('projection_error')->nullable();
            }
            if (!Schema::hasColumn('agent_core_events', 'projection_dependency')) {
                $table->string('projection_dependency', 191)->nullable();
            }
            if (!Schema::hasColumn('agent_core_events', 'projection_attempts')) {
                $table->unsignedInteger('projection_attempts')->default(0);
            }
            if (!Schema::hasColumn('agent_core_events', 'projected_at')) {
                $table->timestamp('projected_at')->nullable();
            }
        });

        if (Schema::hasColumn('agent_core_events', 'projection_status')) {
            DB::table('agent_core_events')->whereNull('projection_status')->update(['projection_status' => 'received']);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('agent_core_events')) return;
        Schema::table('agent_core_events', function (Blueprint $table): void {
            $columns = array_filter(
                ['projection_dependency', 'projection_attempts', 'projected_at'],
                fn ($column) => Schema::hasColumn('agent_core_events', $column)
            );
            if ($columns) $table->dropColumn($columns);
        });
    }
};