<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacy Mode — missed-sale log (counter-side gap vs Ailaaj / Pharma Pro).
 *
 * When a customer asks for a medicine the shop does not carry (or has run out
 * of), the counter used to remember it in the cashier's head — and purchase
 * decisions ran on that memory. Each row here is one such ask: what was typed,
 * how many were wanted, who was at the till, and whether the owner has since
 * acted on it. Grouped by term on the "Missed sales" report.
 *
 * client_uuid is the offline idempotency key: the sale screen queues these
 * locally when the line is down and replays them on reconnect, so a lost
 * response must never double-count the same ask.
 *
 * Plain company_id (no FK) like the rest of the pharmacy schema — the table is
 * listed in the admin hard-delete purge list instead. hasTable-guarded for the
 * PROD deploy-before-migrate window.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pharmacy_missed_sales')) {
            return;
        }

        Schema::create('pharmacy_missed_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            // What the customer asked for, as typed at the counter.
            $table->string('term', 150);
            // Lower-cased, whitespace-collapsed copy — the grouping key for the
            // report, so "Brufen 400" and "brufen  400" are one line.
            $table->string('term_key', 150);
            $table->decimal('quantity', 10, 2)->nullable();
            // no_match (search found nothing) | out_of_stock (known medicine, no stock)
            $table->string('reason', 20)->default('no_match');
            // The medicine that WAS asked for when the reason is out_of_stock.
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('client_uuid', 64)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'pms_company_created_idx');
            $table->index(['company_id', 'term_key'], 'pms_company_term_idx');
            $table->unique(['company_id', 'client_uuid'], 'pms_company_uuid_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_missed_sales');
    }
};
