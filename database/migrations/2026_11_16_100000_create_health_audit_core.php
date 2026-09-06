<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner One-Click Audit — the healthcare panel's own evidence spine (Task 1554).
 *
 * Three tables, and they are deliberately three:
 *
 *   health_audit_events    WHAT HAPPENED. One attributable row per important
 *                          act, written as it happens, never rewritten. This is
 *                          the evidence; everything else is an opinion about it.
 *
 *   health_audit_runs      ONE PRESS. The scope somebody audited, the ruleset
 *                          version that judged it, and the totals it produced —
 *                          so the same period can be re-run next month and the
 *                          two answers compared honestly.
 *
 *   health_audit_findings  WHAT LOOKS WRONG. Never a verdict: a finding carries
 *                          the exact rows it was derived from, so the owner
 *                          checks the source rather than trusting the label.
 *
 * The events table has no updated_at on purpose. A row that can be touched
 * after the fact is not evidence, and the model refuses UPDATE and DELETE
 * outright — the migration only makes that refusal cheap to believe by leaving
 * nothing for an edit to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // EVENTS — the attributable record of an act.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_audit_events')) {
            Schema::create('health_audit_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();

                $table->dateTime('occurred_at');

                // clinical | billing | payment | stock | accounts | hr |
                // access | auth | export | record_view | audit
                $table->string('category', 20)->default('clinical');
                // Dotted, stable, machine-readable: billing.charge.reversed
                $table->string('event', 64);
                // created | updated | deleted | approved | reversed | viewed |
                // login | logout | exported | granted | revoked
                $table->string('action', 16)->default('updated');

                // The actor's NAME and ROLE are frozen onto the row. A member
                // who is later renamed, demoted or deleted must not be able to
                // change what the record says they did.
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_name', 150)->nullable();
                $table->string('actor_role', 32)->nullable();

                $table->string('entity_type', 64)->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('entity_label', 190)->nullable();

                $table->unsignedBigInteger('health_patient_id')->nullable();
                $table->unsignedBigInteger('health_doctor_id')->nullable();

                // Money on the act, when it has any. Kept as a column rather
                // than dug out of the JSON so the checks can sum it.
                $table->decimal('amount', 16, 2)->nullable();
                $table->string('reason', 500)->nullable();

                // web | api | console | system | agent
                $table->string('source', 16)->default('web');
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->string('route', 190)->nullable();

                $table->mediumText('old_values')->nullable();
                $table->mediumText('new_values')->nullable();
                $table->mediumText('meta')->nullable();

                // Did this act touch a file the patient asked be kept private?
                // The audit workspace shows THAT it happened to everybody with
                // audit.view, and the clinical content itself to nobody.
                $table->boolean('is_sensitive')->default(false);

                // Tamper evidence. sha256_hash covers this row's own content;
                // prev_hash points at the row written before it for the same
                // organisation. A deleted row therefore leaves a successor
                // whose ancestor cannot be found — which is exactly what the
                // verifier reports.
                $table->char('prev_hash', 64)->nullable();
                $table->char('sha256_hash', 64);

                $table->timestamp('created_at')->nullable();

                $table->index(['company_id', 'occurred_at'], 'health_ae_period_idx');
                $table->index(['company_id', 'category', 'occurred_at'], 'health_ae_cat_idx');
                $table->index(['company_id', 'actor_user_id', 'occurred_at'], 'health_ae_actor_idx');
                $table->index(['company_id', 'entity_type', 'entity_id'], 'health_ae_entity_idx');
                $table->index(['company_id', 'health_patient_id'], 'health_ae_patient_idx');
                $table->index(['company_id', 'event'], 'health_ae_event_idx');
                $table->index('sha256_hash', 'health_ae_hash_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // RUNS — one press of the button.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_audit_runs')) {
            Schema::create('health_audit_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('actor_name', 150)->nullable();
                $table->string('actor_role', 32)->nullable();

                $table->date('date_from');
                $table->date('date_to');
                $table->string('preset', 24)->nullable();

                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_doctor_id')->nullable();
                $table->unsignedBigInteger('subject_user_id')->nullable();

                // The ruleset that judged this period. A finding is only
                // comparable with another finding produced by the same version.
                $table->string('ruleset_version', 16)->default('1');

                // pending | running | ready | failed
                $table->string('status', 12)->default('pending');
                $table->unsignedTinyInteger('progress')->default(0);
                $table->unsignedSmallInteger('rules_run')->default(0);
                $table->unsignedSmallInteger('rules_failed')->default(0);

                $table->unsignedInteger('findings_total')->default(0);
                $table->unsignedInteger('findings_critical')->default(0);
                $table->unsignedInteger('findings_warning')->default(0);
                $table->unsignedInteger('findings_info')->default(0);
                $table->unsignedInteger('events_scanned')->default(0);
                $table->unsignedTinyInteger('risk_score')->default(100);

                // Same scope + same ruleset = same hash. Two runs that share it
                // are answering the same question and may be compared.
                $table->char('filters_hash', 64)->nullable();
                // Hash over the ordered finding fingerprints — "did anything
                // change since last time" in one comparison.
                $table->char('result_hash', 64)->nullable();

                $table->unsignedInteger('duration_ms')->default(0);
                $table->string('error_message', 1000)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();

                // The signed evidence pack built FROM this run. One run, one
                // pack: a pack that could be rebuilt from a different scope
                // than the findings it ships is not evidence of anything.
                $table->string('pack_status', 12)->nullable();
                $table->unsignedTinyInteger('pack_progress')->default(0);
                $table->string('pack_path', 255)->nullable();
                $table->unsignedBigInteger('pack_size')->nullable();
                $table->char('pack_sha256', 64)->nullable();
                $table->string('pack_signature', 128)->nullable();
                $table->timestamp('pack_generated_at')->nullable();
                $table->timestamp('pack_locked_at')->nullable();
                $table->string('pack_error', 500)->nullable();

                $table->timestamps();

                $table->index(['company_id', 'status'], 'health_ar_status_idx');
                $table->index(['company_id', 'date_from', 'date_to'], 'health_ar_period_idx');
                $table->index(['company_id', 'filters_hash'], 'health_ar_scope_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // FINDINGS — what a rule noticed, plus the rows it noticed it from.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_audit_findings')) {
            Schema::create('health_audit_findings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_audit_run_id');

                $table->string('rule_key', 48);
                $table->string('rule_version', 16)->default('1');
                $table->string('category', 20);
                // info | warning | critical
                $table->string('severity', 10)->default('warning');

                $table->date('occurred_on')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_doctor_id')->nullable();
                $table->unsignedBigInteger('subject_user_id')->nullable();
                $table->string('subject_name', 150)->nullable();

                $table->string('entity_type', 64)->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('entity_label', 190)->nullable();

                $table->decimal('amount', 16, 2)->nullable();
                $table->decimal('variance', 16, 2)->nullable();

                // Interpolation values for the finding's lang key, and the
                // exact supporting rows. Never free English: the panel is
                // en / rur / ur from day one.
                $table->mediumText('params')->nullable();
                $table->mediumText('evidence')->nullable();

                // Stable across re-runs of the same scope — this is what lets
                // an acknowledgement survive a rerun instead of reopening.
                $table->char('fingerprint', 64);

                // open | acknowledged | investigating | resolved | false_positive
                $table->string('status', 16)->default('open');
                $table->string('status_note', 500)->nullable();
                $table->unsignedBigInteger('status_by')->nullable();
                $table->string('status_by_name', 150)->nullable();
                $table->timestamp('status_at')->nullable();

                $table->timestamps();

                $table->index(['health_audit_run_id', 'severity'], 'health_af_run_sev_idx');
                $table->index(['health_audit_run_id', 'category'], 'health_af_run_cat_idx');
                $table->index(['company_id', 'fingerprint'], 'health_af_fingerprint_idx');
                $table->index(['company_id', 'status'], 'health_af_status_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // NOTES — the investigation, kept beside the finding.
        //
        // Append-only by design: "we asked the cashier and it was a till
        // error" is worth keeping even after somebody changes their mind, and
        // a note that can be edited is a note nobody can rely on later.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_audit_notes')) {
            Schema::create('health_audit_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_audit_finding_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('actor_name', 150)->nullable();
                $table->string('actor_role', 32)->nullable();
                $table->string('status_from', 16)->nullable();
                $table->string('status_to', 16)->nullable();
                $table->text('body')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['health_audit_finding_id', 'id'], 'health_an_finding_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_audit_notes');
        Schema::dropIfExists('health_audit_findings');
        Schema::dropIfExists('health_audit_runs');
        Schema::dropIfExists('health_audit_events');
    }
};
