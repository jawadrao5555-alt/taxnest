<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Biometric Hazri auto-sync (4 Aug 2026).
// Three tables:
//   pos_biometric_devices   — one row per registered device per company.
//   pos_biometric_user_map  — maps device PIN (user-id on the machine) to a POS user.
//   pos_biometric_punches   — raw punches received from the device or imported from CSV.
// All idempotent for cPanel prod (migrate --force).
return new class extends Migration
{
    public function up(): void
    {
        // --- Devices ---
        if (!Schema::hasTable('pos_biometric_devices')) {
            Schema::create('pos_biometric_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('label', 100);            // human-readable name, e.g. "Main Entrance"
                $table->string('device_sn', 100)->nullable(); // device serial (optional, info only)
                $table->string('push_token', 64)->unique(); // URL token for the ADMS endpoint
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index('company_id');
            });
        }

        // --- User PIN mapping ---
        if (!Schema::hasTable('pos_biometric_user_map')) {
            Schema::create('pos_biometric_user_map', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('device_id'); // FK to pos_biometric_devices
                $table->string('device_pin', 50);        // the PIN/employee-id on the biometric machine
                $table->unsignedBigInteger('user_id');   // FK to users
                $table->timestamps();
                $table->unique(['device_id', 'device_pin'], 'pbum_device_pin_unique');
                $table->index('company_id');
                $table->index('user_id');
            });
        }

        // --- Raw punches ---
        if (!Schema::hasTable('pos_biometric_punches')) {
            Schema::create('pos_biometric_punches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('device_id')->nullable(); // null = CSV import (device unknown)
                $table->string('device_pin', 50)->nullable();        // PIN as reported by device
                $table->unsignedBigInteger('user_id')->nullable();   // resolved POS user (null = unmapped)
                $table->dateTime('punched_at');
                $table->enum('punch_type', ['check_in', 'check_out', 'unknown'])->default('unknown');
                $table->string('raw_data', 500)->nullable();         // original line / row for debugging
                $table->string('source', 20)->default('adms');       // 'adms' | 'csv_import'
                $table->timestamps();
                $table->index(['company_id', 'punched_at'], 'pbp_company_punched_idx');
                $table->index(['company_id', 'user_id', 'punched_at'], 'pbp_company_user_punched_idx');
                // Dedup key: same device + pin + timestamp = one punch
                $table->unique(['device_id', 'device_pin', 'punched_at'], 'pbp_device_pin_ts_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_biometric_punches');
        Schema::dropIfExists('pos_biometric_user_map');
        Schema::dropIfExists('pos_biometric_devices');
    }
};
