<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Silent printer routing via the Desktop Sync Agent.
 *
 * - companies.pos_printer_settings JSON:
 *   { silent_print_enabled, receipt_printer, kot_printer,
 *     available_printers: [{name, displayName, isDefault}], printers_reported_at }
 * - pos_print_jobs: queue the agent polls; target_printer is SNAPSHOTTED at
 *   enqueue time so failed jobs show exactly which printer was tried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_printer_settings')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->json('pos_printer_settings')->nullable();
            });
        }

        if (!Schema::hasTable('pos_print_jobs')) {
            Schema::create('pos_print_jobs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('type', 10); // bill | kot
                $table->string('target_printer', 255);
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->unsignedBigInteger('restaurant_order_id')->nullable();
                $table->string('render_query', 255)->nullable(); // e.g. delta=1
                $table->string('status', 12)->default('pending'); // pending|printing|done|failed
                $table->string('claim_token', 64)->nullable();
                // KOT jobs: item ids rendered on the ticket — kot_printed_at is
                // stamped from THIS list only when the agent reports success
                // (stamping at render time would lose items if the print fails).
                $table->json('printed_item_ids')->nullable();
                $table->text('error')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status']);
                $table->index('claim_token');
                // No FKs by convention (shared tables / archived rows).
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_print_jobs');
        if (Schema::hasColumn('companies', 'pos_printer_settings')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_printer_settings');
            });
        }
    }
};
