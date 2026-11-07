<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('health_patients')) {
            return;
        }

        Schema::table('health_patients', function (Blueprint $table) {
            if (!Schema::hasColumn('health_patients', 'consent_policy_version')) {
                $table->string('consent_policy_version', 64)->nullable()->after('consent_recorded_by');
            }
            if (!Schema::hasColumn('health_patients', 'consent_withdrawn_at')) {
                $table->timestamp('consent_withdrawn_at')->nullable()->after('consent_policy_version');
            }
            if (!Schema::hasColumn('health_patients', 'consent_withdrawn_by')) {
                $table->unsignedBigInteger('consent_withdrawn_by')->nullable()->after('consent_withdrawn_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('health_patients')) {
            return;
        }

        $columns = array_values(array_filter([
            'consent_policy_version',
            'consent_withdrawn_at',
            'consent_withdrawn_by',
        ], fn (string $column) => Schema::hasColumn('health_patients', $column)));

        if ($columns !== []) {
            Schema::table('health_patients', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
