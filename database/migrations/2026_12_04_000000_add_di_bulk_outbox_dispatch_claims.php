<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A committed outbox row is only marked dispatched after Queue::dispatch().
 * These short leases stop two live dispatcher processes from handing the same
 * row to the queue concurrently, while allowing the next worker to recover a
 * predecessor that died between claiming and hand-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoice_bulk_submission_outbox')) {
            return;
        }
        Schema::table('invoice_bulk_submission_outbox', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_bulk_submission_outbox', 'dispatch_claim_token')) {
                $table->string('dispatch_claim_token', 64)->nullable()->after('dispatched_at');
            }
            if (!Schema::hasColumn('invoice_bulk_submission_outbox', 'dispatch_claimed_at')) {
                $table->timestamp('dispatch_claimed_at')->nullable()->after('dispatch_claim_token');
            }
            $table->index(['batch_id', 'dispatch_claimed_at'], 'bulk_outbox_claim_recovery');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoice_bulk_submission_outbox')) {
            return;
        }
        Schema::table('invoice_bulk_submission_outbox', function (Blueprint $table) {
            $table->dropIndex('bulk_outbox_claim_recovery');
            if (Schema::hasColumn('invoice_bulk_submission_outbox', 'dispatch_claimed_at')) {
                $table->dropColumn('dispatch_claimed_at');
            }
            if (Schema::hasColumn('invoice_bulk_submission_outbox', 'dispatch_claim_token')) {
                $table->dropColumn('dispatch_claim_token');
            }
        });
    }
};