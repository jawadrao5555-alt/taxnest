<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Existing installs already own the inbox table. Fresh installs get
        // these fields from its creator migration, which runs later.
        if (!Schema::hasTable('agent_core_events')) return;
        Schema::table('agent_core_events', function (Blueprint $table) {
            if (!Schema::hasColumn('agent_core_events', 'event_scope')) $table->json('event_scope')->nullable();
            if (!Schema::hasColumn('agent_core_events', 'projection_status')) $table->string('projection_status', 24)->nullable()->index();
            if (!Schema::hasColumn('agent_core_events', 'projection_result')) $table->json('projection_result')->nullable();
            if (!Schema::hasColumn('agent_core_events', 'projection_error')) $table->text('projection_error')->nullable();
        });
    }
    public function down(): void
    {
        if (!Schema::hasTable('agent_core_events')) return;
        Schema::table('agent_core_events', function (Blueprint $table) {
            $drop = [];
            foreach (['event_scope', 'projection_status', 'projection_result', 'projection_error'] as $column) {
                if (Schema::hasColumn('agent_core_events', $column)) $drop[] = $column;
            }
            if ($drop) $table->dropColumn($drop);
        });
    }
};