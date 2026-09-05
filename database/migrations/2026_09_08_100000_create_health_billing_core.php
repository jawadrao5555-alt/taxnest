<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare ERP — unified billing and FBR fiscalization (Task 1551).
 *
 * Until now every healthcare module kept its own money: the OPD fee sat on the
 * visit, the pharmacy total on the sale, the stay's charges on the admission.
 * That is three answers to "what does this patient owe", and a hospital cannot
 * hand a patient three answers.
 *
 * This adds ONE ledger every module feeds and ONE bill every counter prints:
 *
 *   health_tax_categories     the hospital's own tax rulebook — what is local,
 *                             what is exempt, what is reported to FBR
 *   health_charges            the immutable, source-linked charge ledger
 *   health_charge_adjustments every decision taken against a charge
 *   health_bills              estimate / invoice / combined statement
 *   health_bill_lines         the frozen snapshot of a charge on a bill
 *   health_payments           deposits, part payments, refunds, credit
 *   health_cashier_shifts     the counter's own open→close reconciliation
 *   health_fbr_submissions    every regulator attempt, payload and response
 *
 * Rules the shape enforces rather than documents:
 *
 *  - A charge is never edited and never deleted. It is reversed or adjusted,
 *    and every row of that history survives. `source_type`/`source_id` keep the
 *    visit, prescription, admission, operation or pharmacy sale behind the line
 *    reachable forever.
 *  - Tax treatment is a DECISION, not a derivation. It is stamped on the charge
 *    and frozen (`tax_locked_at`) the moment a bill is finalized, so a local or
 *    exempt charge can never quietly become an FBR-reported one afterwards.
 *  - Money is three numbers on the way in (gross, concession, net) and two more
 *    on the way out (tax, total). Nothing collapses to "the amount we took".
 *  - Every table is company_id-scoped with NO FK cascade — same convention as
 *    the rest of the healthcare panel — so each is registered in the admin
 *    hard-delete purge list instead.
 *  - Every create is hasTable-guarded and every column add is hasColumn-guarded:
 *    the owner's live box has a history of migrations marked "Ran" whose columns
 *    never landed, so a re-run has to be able to finish the job.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── Tax rulebook ────────────────────────────────────────────────────
         * The hospital's accountant configures this; the software never guesses.
         * A charge whose category matches no configured rule stays LOCAL at 0% —
         * the safe answer, because filing something with the regulator that the
         * hospital never agreed to file is not recoverable.
         *
         * `applies_to` is a JSON list of charge categories the rule covers, so
         * "consultation is exempt, pharmacy is 18% FBR, room is local" is three
         * rows and no code change.
         */
        if (!Schema::hasTable('health_tax_categories')) {
            Schema::create('health_tax_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');

                $table->string('name', 120);
                $table->string('code', 40)->nullable();
                // local | exempt | fbr — the ONLY three regulatory outcomes.
                $table->string('treatment', 10)->default('local');
                $table->decimal('tax_rate', 6, 2)->default(0);
                // FBR IMS PCTCode (HS code). Blank falls back to all-zeros.
                $table->string('pct_code', 8)->nullable();
                $table->string('sro_reference', 80)->nullable();
                // JSON list of health_charges.category values this rule covers.
                $table->text('applies_to')->nullable();
                // The rule used when nothing else matches.
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('notes', 300)->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'code'], 'health_taxcat_code_unique');
                $table->index(['company_id', 'is_active'], 'health_taxcat_active');
                $table->index(['company_id', 'treatment'], 'health_taxcat_treatment');
            });
        }

        /*
         * ── The unified charge ledger ───────────────────────────────────────
         * One row per billable thing that happened to a patient, whichever
         * module produced it. This is the single source every bill, receipt,
         * statement and reconciliation reads.
         *
         * `dedupe_key` is what lets a module post its charge again after a
         * crash, a retry or a double click without charging the patient twice:
         * the unique index refuses the second attempt, and the caller treats
         * that as success because the outcome it wanted already exists.
         */
        if (!Schema::hasTable('health_charges')) {
            Schema::create('health_charges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_patient_id');

                // Encounter context — whichever applies. Both may be set: an
                // operation during a stay that started from an OPD visit.
                $table->unsignedBigInteger('health_visit_id')->nullable();
                $table->unsignedBigInteger('health_admission_id')->nullable();

                $table->string('charge_no', 32);
                $table->date('charge_date');

                // opd | pharmacy | lab | room | nursing | operation | procedure |
                // doctor | consumable | investigation | service | misc
                $table->string('category', 20)->default('service');
                $table->string('description', 300);
                $table->string('reference', 120)->nullable();

                // WHAT PRODUCED THIS LINE. Never null in practice for module
                // charges; 'manual' for a hand-posted one.
                // visit | prescription | pharmacy_sale | admission_charge |
                // operation | lab_order | manual
                $table->string('source_type', 32)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                // The human identifier of that source (V000123, OT000045…),
                // frozen so a receipt printed years later still points home.
                $table->string('source_reference', 120)->nullable();

                $table->decimal('unit_price', 14, 2)->default(0);
                $table->decimal('quantity', 12, 3)->default(1);
                $table->decimal('gross_amount', 14, 2)->default(0);
                $table->decimal('concession_amount', 14, 2)->default(0);
                $table->string('concession_reason', 300)->nullable();
                $table->unsignedBigInteger('concession_approved_by')->nullable();
                // Taxable value = gross - concession. Tax sits on top of it.
                $table->decimal('net_amount', 14, 2)->default(0);

                // ── Regulatory decision, stamped at post time ──
                $table->unsignedBigInteger('health_tax_category_id')->nullable();
                $table->string('tax_treatment', 10)->default('local'); // local | exempt | fbr
                $table->decimal('tax_rate', 6, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);    // net + tax
                $table->string('pct_code', 8)->nullable();
                // Set when a bill carrying this charge is FINALIZED. From that
                // moment the treatment cannot be changed by anybody.
                $table->timestamp('tax_locked_at')->nullable();
                $table->unsignedBigInteger('tax_locked_by')->nullable();

                // Which bill claimed it (NULL = still unbilled).
                $table->unsignedBigInteger('health_bill_id')->nullable();
                $table->timestamp('billed_at')->nullable();

                $table->string('status', 12)->default('posted'); // posted | billed | reversed
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversal_reason', 300)->nullable();

                $table->string('dedupe_key', 180)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'charge_no'], 'health_led_no_unique');
                $table->unique(['company_id', 'dedupe_key'], 'health_led_dedupe_unique');
                $table->index(['company_id', 'health_patient_id'], 'health_led_patient');
                $table->index(['company_id', 'status'], 'health_led_status');
                $table->index(['company_id', 'charge_date'], 'health_led_date');
                $table->index(['company_id', 'health_bill_id'], 'health_led_bill');
                $table->index(['company_id', 'health_admission_id'], 'health_led_admission');
                $table->index(['company_id', 'health_visit_id'], 'health_led_visit');
                $table->index(['company_id', 'category'], 'health_led_category');
                $table->index(['company_id', 'tax_treatment'], 'health_led_treatment');
            });
        }

        /*
         * ── Adjustments ─────────────────────────────────────────────────────
         * Append-only. Every decision taken against a charge after it was
         * posted — a concession granted, a reclassification, a reversal — leaves
         * a row here naming the amount, the reason and the person. This is what
         * makes "the treatment cannot silently switch" true: it cannot switch
         * without writing down who switched it.
         */
        if (!Schema::hasTable('health_charge_adjustments')) {
            Schema::create('health_charge_adjustments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_charge_id');

                // concession | correction | reversal | reclassify | write_off
                $table->string('kind', 20);
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('from_value', 120)->nullable();
                $table->string('to_value', 120)->nullable();
                $table->string('reason', 300)->nullable();

                $table->unsignedBigInteger('approved_by')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('actor_name', 150)->nullable(); // frozen: staff leave
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_charge_id'], 'health_ledadj_charge');
                $table->index(['company_id', 'kind'], 'health_ledadj_kind');
            });
        }

        /*
         * ── Bills ───────────────────────────────────────────────────────────
         * An ESTIMATE quotes; an INVOICE is owed. `scope` says whether this is
         * one department's own receipt, a combined statement across departments,
         * or the final settlement of a stay — the same engine, three shapes,
         * because a patient may legitimately be handed any of them.
         *
         * The FBR columns mirror the proven FBR POS transaction contract on
         * purpose (invoice number, status, response code, error, retry count),
         * so the fiscal half of this module behaves exactly like the retail half
         * the platform already runs.
         */
        if (!Schema::hasTable('health_bills')) {
            Schema::create('health_bills', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                // NULL for a combined bill: it belongs to no single department.
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_patient_id');
                $table->unsignedBigInteger('health_visit_id')->nullable();
                $table->unsignedBigInteger('health_admission_id')->nullable();

                $table->string('bill_no', 32);
                $table->string('doc_type', 12)->default('invoice');   // estimate | invoice
                $table->string('scope', 16)->default('department');   // department | combined | final
                $table->string('status', 16)->default('draft');       // draft | finalized | settled | cancelled

                $table->date('bill_date');
                $table->date('business_date')->nullable();
                $table->date('due_date')->nullable();

                $table->decimal('gross_amount', 14, 2)->default(0);
                $table->decimal('concession_amount', 14, 2)->default(0);
                $table->decimal('net_amount', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);

                // Who carries which slice of the total. patient_payable is what
                // the counter may actually collect.
                $table->decimal('insurance_amount', 14, 2)->default(0);
                $table->decimal('corporate_amount', 14, 2)->default(0);
                $table->decimal('patient_payable', 14, 2)->default(0);

                $table->decimal('deposit_applied', 14, 2)->default(0);
                $table->decimal('paid_amount', 14, 2)->default(0);
                $table->decimal('refunded_amount', 14, 2)->default(0);
                $table->decimal('outstanding_amount', 14, 2)->default(0);

                $table->string('payer_type', 20)->default('self');   // self | panel | insurance | corporate | charity | government
                $table->string('payer_name', 150)->nullable();
                $table->string('payer_reference', 80)->nullable();

                // {"local": 0.00, "exempt": 0.00, "fbr": 0.00} — the split the
                // receipt prints so local and reported money stay visibly apart.
                $table->text('treatment_totals')->nullable();

                $table->boolean('fbr_eligible')->default(false);
                // null | pending | submitted | failed | config_error | not_applicable
                $table->string('fbr_status', 20)->nullable();
                $table->string('fbr_invoice_number', 64)->nullable();
                $table->string('fbr_response_code', 16)->nullable();
                $table->string('fbr_error_message', 1000)->nullable();
                $table->timestamp('fbr_submitted_at')->nullable();
                $table->unsignedInteger('fbr_retry_count')->default(0);
                // The mirror fbr_pos_transactions row this bill files through.
                $table->unsignedBigInteger('fbr_pos_transaction_id')->nullable();

                $table->string('share_token', 64)->nullable();
                $table->string('notes', 500)->nullable();

                $table->timestamp('finalized_at')->nullable();
                $table->unsignedBigInteger('finalized_by')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->unsignedBigInteger('settled_by')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->string('cancel_reason', 300)->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'bill_no'], 'health_bill_no_unique');
                $table->index(['company_id', 'status'], 'health_bill_status');
                $table->index(['company_id', 'health_patient_id'], 'health_bill_patient');
                $table->index(['company_id', 'bill_date'], 'health_bill_date');
                $table->index(['company_id', 'fbr_status'], 'health_bill_fbr');
                $table->index(['company_id', 'health_admission_id'], 'health_bill_admission');
                $table->index(['company_id', 'branch_id', 'business_date'], 'health_bill_day');
                $table->index('share_token', 'health_bill_share');
            });
        }

        /*
         * ── Bill lines ──────────────────────────────────────────────────────
         * A FROZEN copy of the charge as it stood when the bill was made. The
         * charge stays the ledger's truth; the line is what was printed and
         * handed over, and a later concession on the ledger must never rewrite
         * a receipt somebody is holding.
         */
        if (!Schema::hasTable('health_bill_lines')) {
            Schema::create('health_bill_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_bill_id');
                $table->unsignedBigInteger('health_charge_id')->nullable();

                $table->unsignedInteger('line_no')->default(1);
                $table->string('category', 20)->default('service');
                $table->string('description', 300);
                $table->string('reference', 120)->nullable();

                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->string('department_name', 150)->nullable();   // frozen

                $table->string('source_type', 32)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('source_reference', 120)->nullable();

                $table->decimal('unit_price', 14, 2)->default(0);
                $table->decimal('quantity', 12, 3)->default(1);
                $table->decimal('gross_amount', 14, 2)->default(0);
                $table->decimal('concession_amount', 14, 2)->default(0);
                $table->decimal('net_amount', 14, 2)->default(0);

                $table->string('tax_treatment', 10)->default('local');
                $table->decimal('tax_rate', 6, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('pct_code', 8)->nullable();

                $table->timestamps();

                $table->index(['company_id', 'health_bill_id'], 'health_billline_bill');
                $table->index(['company_id', 'health_charge_id'], 'health_billline_charge');
                $table->index(['company_id', 'tax_treatment'], 'health_billline_treatment');
            });
        }

        /*
         * ── Money in and out ────────────────────────────────────────────────
         * A DEPOSIT with no bill is an advance sitting on the patient account;
         * the same row gains a health_bill_id the moment it is applied. Refunds
         * are their own kind rather than a negative payment, because "collected"
         * and "given back" are two questions the counter is asked separately.
         *
         * Every row can name the shift that took it, which is what makes the
         * cashier's drawer reconcile to the billing ledger rather than to a
         * separately-kept tally.
         */
        if (!Schema::hasTable('health_payments')) {
            Schema::create('health_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_patient_id');
                $table->unsignedBigInteger('health_bill_id')->nullable();
                $table->unsignedBigInteger('health_admission_id')->nullable();
                $table->unsignedBigInteger('health_cashier_shift_id')->nullable();

                $table->string('receipt_no', 32);
                // deposit | payment | refund | insurance | corporate | write_off
                $table->string('kind', 16)->default('payment');
                $table->decimal('amount', 14, 2)->default(0);
                // cash | card | online | cheque | insurance | corporate | credit | other
                $table->string('method', 16)->default('cash');
                $table->string('reference', 120)->nullable();
                $table->string('note', 300)->nullable();

                $table->timestamp('received_at')->nullable();
                $table->unsignedBigInteger('received_by')->nullable();
                $table->date('business_date')->nullable();

                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversal_reason', 300)->nullable();

                $table->timestamps();

                $table->unique(['company_id', 'receipt_no'], 'health_pay_no_unique');
                $table->index(['company_id', 'health_patient_id'], 'health_pay_patient');
                $table->index(['company_id', 'health_bill_id'], 'health_pay_bill');
                $table->index(['company_id', 'received_at'], 'health_pay_when');
                $table->index(['company_id', 'health_cashier_shift_id'], 'health_pay_shift');
                $table->index(['company_id', 'branch_id', 'business_date'], 'health_pay_day');
            });
        }

        /*
         * ── Cashier shifts ──────────────────────────────────────────────────
         * Opened when the counter starts, closed when the drawer is counted.
         * `expected_*` is computed from health_payments at close time and frozen
         * alongside what was actually counted, so a variance stays answerable
         * months later even after the underlying day is long closed.
         */
        if (!Schema::hasTable('health_cashier_shifts')) {
            Schema::create('health_cashier_shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('user_id');

                $table->timestamp('opened_at')->nullable();
                $table->unsignedBigInteger('opened_by')->nullable();
                $table->decimal('opening_float', 14, 2)->default(0);

                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                // NULL counted cash is NOT zero — it means nobody counted yet.
                $table->decimal('counted_cash', 14, 2)->nullable();
                $table->decimal('expected_cash', 14, 2)->nullable();
                $table->decimal('variance', 14, 2)->nullable();
                // Frozen per-method totals at close: {"cash": …, "card": …}
                $table->text('totals')->nullable();

                $table->string('status', 10)->default('open');  // open | closed
                $table->string('note', 300)->nullable();
                $table->date('business_date')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status'], 'health_shift_status');
                $table->index(['company_id', 'user_id'], 'health_shift_user');
                $table->index(['company_id', 'opened_at'], 'health_shift_opened');
                $table->index(['company_id', 'branch_id', 'business_date'], 'health_shift_day');
            });
        }

        /*
         * ── FBR submission evidence ─────────────────────────────────────────
         * One row per ATTEMPT, never overwritten. The exact payload that left
         * this server and the exact body that came back are both kept, because
         * "what did we actually send FBR" is the only question that matters when
         * a filing is disputed, and re-deriving the payload months later from
         * mutable data answers a different question.
         */
        if (!Schema::hasTable('health_fbr_submissions')) {
            Schema::create('health_fbr_submissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_bill_id');
                $table->unsignedBigInteger('fbr_pos_transaction_id')->nullable();

                $table->unsignedInteger('attempt_no')->default(1);
                // pending | submitted | failed | config_error | queued_agent | blocked
                $table->string('status', 20)->default('pending');
                // manual | auto | retry | day_close
                $table->string('trigger', 16)->default('manual');

                $table->longText('request_payload')->nullable();
                $table->longText('response_payload')->nullable();
                $table->string('response_code', 16)->nullable();
                $table->string('invoice_number', 64)->nullable();
                $table->string('error_message', 1000)->nullable();

                $table->timestamp('submitted_at')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_bill_id'], 'health_fbrsub_bill');
                $table->index(['company_id', 'status'], 'health_fbrsub_status');
            });
        }

        /*
         * ── Mirror link on the retail fiscal table ──────────────────────────
         * A healthcare bill files through a mirror fbr_pos_transactions row so
         * it reuses the proven IMS submission path (hash lock, FbrPosLog, the
         * 2-minute sync job, the retry job) instead of growing a second one.
         * This column is what lets the healthcare side recognise its own mirror
         * again — including a mirror that the SHARED sync job succeeded on while
         * nobody was looking.
         */
        if (Schema::hasTable('fbr_pos_transactions') && !Schema::hasColumn('fbr_pos_transactions', 'health_bill_id')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('health_bill_id')->nullable()->after('company_id');
                $table->index(['company_id', 'health_bill_id'], 'fbr_pos_health_bill_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fbr_pos_transactions') && Schema::hasColumn('fbr_pos_transactions', 'health_bill_id')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->dropIndex('fbr_pos_health_bill_idx');
                $table->dropColumn('health_bill_id');
            });
        }

        foreach ([
            'health_fbr_submissions',
            'health_cashier_shifts',
            'health_payments',
            'health_bill_lines',
            'health_bills',
            'health_charge_adjustments',
            'health_charges',
            'health_tax_categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
