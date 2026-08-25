<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One "submit these invoices to FBR" run, tracked in the DATABASE.
 *
 * The first version of bulk submit kept its progress in a cache entry. That
 * capped the feature at 1,000 invoices and made it fragile in exactly the way
 * a long run must not be:
 *   - CACHE_STORE=database on live and every deploy runs `cache:clear`, so a
 *     deploy in the middle of a multi-hour run erased the whole batch record
 *     (the queued jobs kept submitting, but progress and the "a run is active"
 *     lock vanished, and the shop was left with no idea what had happened);
 *   - the record held one row per invoice and was read-modify-written under a
 *     lock on every single completion — at 6,000 invoices that blob is ~1MB
 *     rewritten 6,000 times;
 *   - the "already running" lock had a 60-minute TTL, but a 6,000-invoice run
 *     takes hours, so the lock expired mid-run.
 *
 * A row here survives deploys, cache clears, restarts and the browser being
 * closed, and lets the shop come back later to a finished result.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_bulk_submissions')) {
            return;
        }

        Schema::create('invoice_bulk_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();

            // Which tab the run was started from: draft (submit) or failed (retry).
            $table->string('target_status', 20)->default('draft');
            // 'all' = every eligible invoice, 'selected' = the ticked ids only.
            $table->string('scope', 20)->default('all');
            // queued → dispatching → running → completed | cancelled | stalled
            $table->string('state', 20)->default('queued');

            // Only set for scope=selected; 'all' runs walk the table by cursor.
            $table->json('invoice_ids')->nullable();

            // The run is frozen to the invoices that existed when it started, so
            // invoices created during a multi-hour run are never swept in.
            $table->unsignedBigInteger('max_invoice_id')->default(0);
            $table->unsignedBigInteger('cursor_id')->default(0);

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('dispatched')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('success')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('pending')->default(0);

            // Only the problem invoices are kept (capped) — nobody needs to read
            // 6,000 success lines, and the row must stay small.
            $table->json('failures')->nullable();

            $table->boolean('cancel_requested')->default(false);

            $table->timestamp('started_at')->nullable();
            // Heartbeat: if this stops moving the queue worker is not running.
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Set when the shop has seen the finished summary.
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_bulk_submissions');
    }
};
