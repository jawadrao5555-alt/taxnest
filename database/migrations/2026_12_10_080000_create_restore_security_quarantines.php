<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('restore_security_quarantines')) {
            Schema::create('restore_security_quarantines', function (Blueprint $table) {
                $table->id();
                // The operator reference is never retained in plaintext.
                $table->string('restore_reference_hash', 64)->unique();
                $table->string('status', 20)->index();
                $table->json('revocation_counts')->nullable();
                $table->unsignedBigInteger('agent_pairing_rows')->default(0);
                $table->string('agent_pairing_sha256', 64)->nullable();
                // DATETIME avoids legacy MariaDB implicit TIMESTAMP defaults.
                $table->dateTime('started_at');
                $table->dateTime('completed_at')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restore_security_quarantines');
    }
};
