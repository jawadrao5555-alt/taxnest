<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the first cut of the healthcare audit trail left implicit.
 *
 * 1. A run remembers the BOUNDARY it was computed inside, not only the filter
 *    the person chose. "No branch filter" by an owner means the whole
 *    organisation; "no branch filter" by a branch-confined accountant means
 *    one branch — and the stored row must tell those two apart, or a reader
 *    from a third branch opens the owner's run and reads everybody's findings.
 *
 * 2. A per-organisation chain anchor, signed with the application key. Each
 *    event already points at its predecessor, which catches an edit or a hole
 *    in the middle — but nothing points at the LAST event, so removing the
 *    tail of the chain left no trace. The anchor is that pointer, and its
 *    signature is what a database-only intruder cannot recompute.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('health_audit_runs')) {
            Schema::table('health_audit_runs', function (Blueprint $table) {
                if (!Schema::hasColumn('health_audit_runs', 'scope_branch_ids')) {
                    // JSON list of branch ids the run was confined to; NULL = every branch.
                    $table->text('scope_branch_ids')->nullable()->after('subject_user_id');
                }
                if (!Schema::hasColumn('health_audit_runs', 'scope_department_ids')) {
                    $table->text('scope_department_ids')->nullable()->after('scope_branch_ids');
                }
            });
        }

        if (!Schema::hasTable('health_audit_chain_anchors')) {
            Schema::create('health_audit_chain_anchors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');

                // The newest event: its id, its hash, and how many rows the
                // chain held when it was written.
                $table->unsignedBigInteger('last_event_id');
                $table->char('tip_hash', 64);
                $table->unsignedBigInteger('event_count');

                // HMAC over (company, last id, tip, count) under the app key.
                $table->char('signature', 64);

                $table->timestamp('updated_at')->nullable();

                $table->unique('company_id', 'health_aca_company_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_audit_chain_anchors');

        if (Schema::hasTable('health_audit_runs')) {
            Schema::table('health_audit_runs', function (Blueprint $table) {
                foreach (['scope_branch_ids', 'scope_department_ids'] as $column) {
                    if (Schema::hasColumn('health_audit_runs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
