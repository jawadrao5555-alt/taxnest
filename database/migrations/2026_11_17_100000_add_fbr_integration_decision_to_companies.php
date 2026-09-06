<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS — optional FBR integration (Sep 2026).
 *
 * FBR reporting used to be switched ON for every new FBR POS shop even though
 * no POS ID / token existed yet, so the very first final bill landed in the
 * fail queue as config_error. Integration is now a CHOICE the shop makes:
 *
 *   fbr_integration_decision   NULL          = not asked yet (decision card shows)
 *                              'connect'     = shop wants FBR (reporting turns ON
 *                                              automatically once configured)
 *                              'without_fbr' = shop runs plain bills + simple QR
 *   fbr_integration_decided_at / _by          = audit of who chose, when
 *
 * Idempotent + hasColumn-guarded (prod schema drift rule): safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'fbr_integration_decision')) {
                $table->string('fbr_integration_decision', 20)->nullable();
            }
            if (!Schema::hasColumn('companies', 'fbr_integration_decided_at')) {
                $table->timestamp('fbr_integration_decided_at')->nullable();
            }
            if (!Schema::hasColumn('companies', 'fbr_integration_decided_by')) {
                $table->unsignedBigInteger('fbr_integration_decided_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            foreach (['fbr_integration_decision', 'fbr_integration_decided_at', 'fbr_integration_decided_by'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
