<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening Cash Balance (owner request via customer suggestion, Jul 2026):
 * cashier records the drawer's opening cash at DAY START; day-close then
 * auto-fills the opening float so the Z-report reconciliation (expected vs
 * counted cash) needs only the evening count. One row per company per
 * business date; locked once that date's day-close report exists.
 * Idempotent (hasTable guard) — prod runs migrate --force.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_day_openings')) {
            return;
        }
        Schema::create('pos_day_openings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('business_date');
            $t->decimal('opening_cash', 15, 2);
            $t->unsignedBigInteger('entered_by')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'business_date'], 'pos_day_openings_company_date_unique');
            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_day_openings');
    }
};
