<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1484 — the billing cycle that rides along with a requested package.
 *
 * companies.requested_plan_id already remembers WHICH package a shop clicked
 * on the public pricing table. Digital Invoice is sold per month across four
 * cycles and the pricing page lets the visitor pick one, so the request is a
 * package AND a cycle — otherwise approval would silently charge a cycle the
 * shop never chose. PRA POS / FBR POS are licensed by the year and leave this
 * NULL (approval forces annual for them).
 *
 * Idempotent / self-healing (prod runs `migrate --force`, never seeds).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'requested_billing_cycle')) {
            return;
        }

        $after = Schema::hasColumn('companies', 'requested_plan_id') ? 'requested_plan_id' : null;

        Schema::table('companies', function (Blueprint $table) use ($after) {
            $column = $table->string('requested_billing_cycle', 20)->nullable();
            if ($after) {
                $column->after($after);
            }
        });
    }

    public function down(): void
    {
        // Data column — intentionally irreversible (matches repo convention).
    }
};
