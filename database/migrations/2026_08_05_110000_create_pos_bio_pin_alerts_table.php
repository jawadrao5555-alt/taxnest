<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Unmapped biometric PIN alerts (Task #277, Aug 2026).
// One row per (company, device_pin): fires the first time an unmapped PIN
// punches; cleared by mapping (mapped_at) or admin dismiss (dismissed_at).
// Idempotent for cPanel prod (migrate --force).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_bio_pin_alerts')) {
            Schema::create('pos_bio_pin_alerts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('device_pin', 50);
                $table->dateTime('first_seen_at');          // punched_at of the first unmapped punch
                $table->dateTime('dismissed_at')->nullable(); // set when admin dismisses (never re-fires)
                $table->dateTime('mapped_at')->nullable();    // set when PIN is mapped to a user
                $table->timestamps();

                $table->unique(['company_id', 'device_pin'], 'pbpa_company_pin_unique');
                $table->index('company_id', 'pbpa_company_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_bio_pin_alerts');
    }
};
