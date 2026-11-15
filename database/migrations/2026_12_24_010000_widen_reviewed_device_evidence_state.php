<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fbr_pos_submission_evidence') && Schema::hasColumn('fbr_pos_submission_evidence', 'local_environment_proof')) {
            Schema::table('fbr_pos_submission_evidence', function (Blueprint $table) {
                // reviewed_device_evidence is 24 characters. Preserve historical
                // states and the unknown default under strict MariaDB SQL mode.
                $table->string('local_environment_proof', 40)->default('unknown')->change();
            });
        }
    }

    public function down(): void
    {
        // Forward-only: narrowing would truncate existing reviewed evidence.
    }
};
