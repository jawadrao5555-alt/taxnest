<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare ERP — inpatient (IPD) and operation theatre (Task 1550).
 *
 * The OPD core answers "who was seen today". This answers "who is still in the
 * building, in which bed, since when, and what has that stay cost so far".
 *
 *   health_wards                 a ward: its kind, its default day rate
 *   health_rooms                 a room inside a ward
 *   health_beds                  the countable unit; carries the live status
 *   health_admissions            one stay, request → discharge → clearance
 *   health_admission_events      the auditable timeline of that stay
 *   health_admission_charges     every charge-producing event on that stay
 *   health_admission_payments    advances taken and refunds given
 *   health_procedures            the priced catalogue of what a theatre does
 *   health_operation_theatres    the bookable theatre
 *   health_operations            one scheduled/performed procedure
 *   health_operation_team        who stood at the table, and in which role
 *   health_operation_consumables what was used up doing it
 *
 * Conventions carried from the rest of the platform:
 *
 *  - Every add is individually hasTable/hasColumn guarded. The owner's live box
 *    has a history of migration rows marked "Ran" whose columns never landed,
 *    so a re-run has to be able to finish the job rather than blow up.
 *  - company_id carries no FK cascade (neither do the OPD tables), so every
 *    table here is registered in the admin hard-delete purge list instead.
 *  - Money is stored as three numbers — gross, concession, net — never as "the
 *    amount we took". A concession is a decision somebody made and has to
 *    survive in the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── Wards ───────────────────────────────────────────────────────────
         * branch_id nullable = organisation-wide, the same convention
         * departments and patients use, so a single-site hospital never has to
         * invent a branch before it can open a ward.
         *
         * Two rates, not one: the bed-day and the nursing-day are separate
         * lines on every hospital bill in the country, and a hospital that
         * charges nursing at zero simply leaves it at zero.
         */
        if (!Schema::hasTable('health_wards')) {
            Schema::create('health_wards', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();

                $table->string('name');
                $table->string('code', 32)->nullable();
                // general | private | semi_private | icu | nicu | hdu |
                // isolation | maternity | emergency | daycare | other
                $table->string('type', 20)->default('general');
                // any | male | female — a male patient must not be offered the
                // last free bed in the women's ward.
                $table->string('gender_policy', 10)->default('any');
                $table->string('floor', 40)->nullable();

                $table->decimal('daily_rate', 12, 2)->default(0);
                $table->decimal('nursing_daily_rate', 12, 2)->default(0);

                $table->boolean('is_active')->default(true);
                $table->string('notes', 500)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'is_active'], 'health_ward_active');
                $table->index(['company_id', 'branch_id'], 'health_ward_branch');
                $table->unique(['company_id', 'code'], 'health_ward_code_unique');
            });
        }

        /*
         * ── Rooms ───────────────────────────────────────────────────────────
         * A rate here OVERRIDES the ward's. NULL means "whatever the ward
         * charges" rather than zero — a room created without a rate must not
         * silently make the stay free.
         */
        if (!Schema::hasTable('health_rooms')) {
            Schema::create('health_rooms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_ward_id');

                $table->string('name', 60);           // "204", "Private-B"
                // general | semi_private | private | deluxe | suite | icu | other
                $table->string('room_type', 20)->default('general');
                $table->decimal('daily_rate', 12, 2)->nullable();
                $table->decimal('nursing_daily_rate', 12, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('notes', 300)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_ward_id'], 'health_room_ward');
                $table->unique(['company_id', 'health_ward_id', 'name'], 'health_room_name_unique');
            });
        }

        /*
         * ── Beds ────────────────────────────────────────────────────────────
         * The bed is the unit the whole module counts. Its `status` is the live
         * truth the bed board renders, and `health_admission_id` is who is in
         * it right now — set and cleared inside the same transaction as the
         * admission move, never inferred by scanning admissions, so a bed can
         * never read "available" while somebody is lying in it.
         *
         * `status_changed_at` is what makes a cleaning turnaround measurable:
         * "bed free" and "bed ready" are not the same moment.
         */
        if (!Schema::hasTable('health_beds')) {
            Schema::create('health_beds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_ward_id');
                $table->unsignedBigInteger('health_room_id')->nullable();

                $table->string('code', 40);           // "ICU-01", "204-A"
                $table->string('label', 60)->nullable();
                $table->decimal('daily_rate', 12, 2)->nullable();
                $table->decimal('nursing_daily_rate', 12, 2)->nullable();

                // available | occupied | reserved | cleaning | blocked
                $table->string('status', 16)->default('available');
                $table->unsignedBigInteger('health_admission_id')->nullable();
                // Who the bed is being held for while status = reserved.
                $table->unsignedBigInteger('reserved_for_admission_id')->nullable();
                $table->string('status_note', 300)->nullable();
                $table->timestamp('status_changed_at')->nullable();

                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'code'], 'health_bed_code_unique');
                $table->index(['company_id', 'status'], 'health_bed_status');
                $table->index(['company_id', 'health_ward_id'], 'health_bed_ward');
                $table->index(['company_id', 'health_admission_id'], 'health_bed_admission');
            });
        }

        /*
         * ── Admissions ──────────────────────────────────────────────────────
         * One row per stay. The status ladder is deliberately explicit rather
         * than derived from timestamps:
         *
         *   requested → admitted → discharge_requested → discharged
         *                        ↘ cancelled
         *
         * A stay is only RELEASED once it is both clinically discharged and
         * financially cleared, and those are two different people's decisions,
         * so they are two different stamps.
         */
        if (!Schema::hasTable('health_admissions')) {
            Schema::create('health_admissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_patient_id');
                // The attending consultant. Nullable only so an emergency
                // admission can be recorded before one is assigned.
                $table->unsignedBigInteger('health_doctor_id')->nullable();
                // The OPD encounter this admission came out of, when it did.
                $table->unsignedBigInteger('health_visit_id')->nullable();

                $table->string('admission_no', 32);
                // requested | admitted | discharge_requested | discharged | cancelled
                $table->string('status', 24)->default('requested');
                // planned | emergency | daycare | maternity | transfer_in
                $table->string('admission_type', 20)->default('planned');

                $table->unsignedBigInteger('health_bed_id')->nullable();
                $table->unsignedBigInteger('health_ward_id')->nullable();

                $table->string('reason', 500)->nullable();
                $table->string('provisional_diagnosis', 500)->nullable();
                $table->unsignedSmallInteger('estimated_days')->nullable();
                $table->decimal('estimated_cost', 14, 2)->nullable();
                $table->decimal('deposit_required', 14, 2)->default(0);

                // Attendant / next of kin — who the ward calls at 3am.
                $table->string('attendant_name', 150)->nullable();
                $table->string('attendant_phone', 32)->nullable();
                $table->string('attendant_relation', 60)->nullable();

                // Who is paying: self | panel | insurance | charity | government
                $table->string('payer_type', 20)->default('self');
                $table->string('payer_name', 150)->nullable();
                $table->string('payer_reference', 80)->nullable();

                $table->timestamp('requested_at')->nullable();
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->timestamp('admitted_at')->nullable();
                $table->unsignedBigInteger('admitted_by')->nullable();

                // Daily care status the ward round updates.
                $table->string('care_status', 20)->default('stable'); // stable | critical | improving | serious
                $table->text('care_note')->nullable();
                $table->timestamp('care_updated_at')->nullable();

                $table->timestamp('discharge_requested_at')->nullable();
                $table->unsignedBigInteger('discharge_requested_by')->nullable();
                // routine | lama | referred | absconded | expired | transfer_out
                $table->string('discharge_type', 20)->nullable();
                $table->string('final_diagnosis', 500)->nullable();
                $table->text('discharge_summary')->nullable();
                $table->text('discharge_advice')->nullable();
                $table->date('follow_up_date')->nullable();
                $table->timestamp('discharged_at')->nullable();
                $table->unsignedBigInteger('discharged_by')->nullable();

                // Financial clearance — the second signature before release.
                $table->decimal('concession_amount', 14, 2)->default(0);
                $table->string('concession_reason', 300)->nullable();
                $table->unsignedBigInteger('concession_approved_by')->nullable();
                $table->timestamp('cleared_at')->nullable();
                $table->unsignedBigInteger('cleared_by')->nullable();

                $table->string('cancel_reason', 300)->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();

                // Last date the recurring room/nursing charge was posted for.
                // Kept here so the daily run knows where it got to without
                // scanning the charge table for every admission.
                $table->date('charges_posted_through')->nullable();

                $table->timestamps();

                $table->unique(['company_id', 'admission_no'], 'health_adm_no_unique');
                $table->index(['company_id', 'status'], 'health_adm_status');
                $table->index(['company_id', 'health_patient_id'], 'health_adm_patient');
                $table->index(['company_id', 'health_doctor_id'], 'health_adm_doctor');
                $table->index(['company_id', 'branch_id'], 'health_adm_branch');
                $table->index(['company_id', 'admitted_at'], 'health_adm_admitted');
            });
        }

        /*
         * ── Admission timeline ──────────────────────────────────────────────
         * Append-only. Nothing in this table is ever edited or deleted: it is
         * the answer to "who moved this patient, and when", which is the first
         * question asked when a stay is disputed.
         */
        if (!Schema::hasTable('health_admission_events')) {
            Schema::create('health_admission_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_admission_id');

                // requested | admitted | bed_assigned | transferred | care_note |
                // charge_posted | charge_reversed | payment | concession |
                // operation_scheduled | operation_completed | operation_cancelled |
                // discharge_requested | cleared | discharged | cancelled
                $table->string('event', 32);
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24)->nullable();
                $table->unsignedBigInteger('from_bed_id')->nullable();
                $table->unsignedBigInteger('to_bed_id')->nullable();
                $table->string('note', 500)->nullable();
                $table->text('meta')->nullable();      // JSON, event-specific

                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_name', 150)->nullable();  // frozen: staff leave
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_admission_id'], 'health_adm_event_parent');
                $table->index(['company_id', 'event'], 'health_adm_event_kind');
            });
        }

        /*
         * ── Admission charges ───────────────────────────────────────────────
         * Every charge-producing event on the stay, whatever produced it.
         *
         * A charge is never deleted — it is REVERSED, which leaves both rows in
         * the record. `dedupe_key` is what makes the recurring daily charge
         * safe: the room-day for one admission on one date has exactly one key,
         * so the daily run can be re-run (or run twice by two servers) without
         * double-charging the patient.
         */
        if (!Schema::hasTable('health_admission_charges')) {
            Schema::create('health_admission_charges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_admission_id');
                $table->unsignedBigInteger('health_patient_id')->nullable();

                $table->date('charge_date');
                // room | nursing | service | medicine | consumable | procedure |
                // doctor | investigation | misc
                $table->string('category', 20)->default('service');
                $table->string('description', 300);
                $table->string('reference', 120)->nullable();  // bed code, service name…

                // What produced this line, when something did:
                // operation | procedure | service | prescription | manual
                $table->string('source_type', 24)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();

                $table->decimal('unit_price', 14, 2)->default(0);
                $table->decimal('quantity', 12, 2)->default(1);
                $table->decimal('gross_amount', 14, 2)->default(0);
                $table->decimal('concession_amount', 14, 2)->default(0);
                $table->string('concession_reason', 300)->nullable();
                $table->unsignedBigInteger('concession_approved_by')->nullable();
                $table->decimal('net_amount', 14, 2)->default(0);

                $table->boolean('is_recurring')->default(false);
                // NULL for a one-off line; set for anything that must exist at
                // most once (the room-day, the nursing-day, an operation's fee).
                $table->string('dedupe_key', 150)->nullable();

                $table->string('status', 12)->default('posted');  // posted | reversed
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversal_reason', 300)->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'dedupe_key'], 'health_charge_dedupe_unique');
                $table->index(['company_id', 'health_admission_id'], 'health_charge_admission');
                $table->index(['company_id', 'charge_date'], 'health_charge_date');
                $table->index(['company_id', 'category'], 'health_charge_category');
            });
        }

        /*
         * ── Advances and refunds ────────────────────────────────────────────
         * Deliberately NOT a charge with a negative sign: money taken from the
         * patient and money owed by the patient are different facts, and a
         * report that adds them together answers neither question.
         */
        if (!Schema::hasTable('health_admission_payments')) {
            Schema::create('health_admission_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_admission_id');

                $table->string('kind', 12)->default('advance');   // advance | refund
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('method', 16)->default('cash');    // cash | card | online | cheque | other
                $table->string('reference', 120)->nullable();
                $table->string('note', 300)->nullable();
                $table->timestamp('received_at')->nullable();
                $table->unsignedBigInteger('received_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_admission_id'], 'health_adm_pay_parent');
                $table->index(['company_id', 'received_at'], 'health_adm_pay_when');
            });
        }

        /*
         * ── Procedure catalogue ─────────────────────────────────────────────
         * The priced list of what the theatre does. A PACKAGE price is the
         * all-in figure a hospital quotes a patient; when it is set, the
         * operation posts that one number and its consumables stop being
         * separately billable, because that is what "package" means to the
         * person who agreed to it.
         */
        if (!Schema::hasTable('health_procedures')) {
            Schema::create('health_procedures', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_department_id')->nullable();

                $table->string('name');
                $table->string('code', 40)->nullable();
                $table->string('category', 60)->nullable();       // general surgery, ortho…
                $table->text('description')->nullable();

                $table->decimal('base_price', 14, 2)->default(0);
                $table->boolean('is_package')->default(false);
                $table->decimal('package_price', 14, 2)->nullable();
                $table->text('package_includes')->nullable();

                // general | spinal | epidural | local | sedation | regional | none
                $table->string('default_anaesthesia', 20)->nullable();
                $table->unsignedSmallInteger('estimated_minutes')->nullable();
                $table->text('pre_op_checklist')->nullable();     // JSON: default items

                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'code'], 'health_proc_code_unique');
                $table->index(['company_id', 'is_active'], 'health_proc_active');
            });
        }

        /*
         * ── Theatres ────────────────────────────────────────────────────────
         * Its own table rather than a room with type='ot': a theatre is booked
         * against a clock, and double-booking one is the failure this module
         * exists to prevent.
         */
        if (!Schema::hasTable('health_operation_theatres')) {
            Schema::create('health_operation_theatres', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();

                $table->string('name', 80);
                $table->string('code', 32)->nullable();
                $table->string('notes', 300)->nullable();
                // Turnaround the schedule must leave between two operations.
                $table->unsignedSmallInteger('turnaround_minutes')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'code'], 'health_ot_code_unique');
                $table->index(['company_id', 'is_active'], 'health_ot_active');
            });
        }

        /*
         * ── Operations ──────────────────────────────────────────────────────
         * `health_admission_id` is nullable on purpose: a day-care procedure is
         * a real operation with a real bill and no bed.
         *
         * `charge_posted_at` is the idempotency stamp — completing an operation
         * twice (double-clicked button, retried request) must not bill the
         * patient twice.
         */
        if (!Schema::hasTable('health_operations')) {
            Schema::create('health_operations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_patient_id');
                $table->unsignedBigInteger('health_admission_id')->nullable();
                $table->unsignedBigInteger('health_procedure_id')->nullable();
                $table->unsignedBigInteger('health_operation_theatre_id')->nullable();

                $table->string('operation_no', 32);
                $table->string('title', 200);
                // scheduled | in_progress | completed | cancelled | postponed
                $table->string('status', 16)->default('scheduled');
                $table->string('urgency', 12)->default('elective');   // elective | emergency

                $table->timestamp('scheduled_start')->nullable();
                $table->timestamp('scheduled_end')->nullable();
                $table->timestamp('actual_start')->nullable();
                $table->timestamp('actual_end')->nullable();

                $table->unsignedBigInteger('primary_surgeon_id')->nullable();   // health_doctors
                $table->unsignedBigInteger('anaesthetist_id')->nullable();      // health_doctors
                $table->string('anaesthesia_type', 20)->nullable();

                $table->text('pre_op_checklist')->nullable();   // JSON [{item, done, note}]
                $table->text('pre_op_notes')->nullable();
                $table->timestamp('pre_op_completed_at')->nullable();
                $table->unsignedBigInteger('pre_op_completed_by')->nullable();
                $table->string('consent_reference', 80)->nullable();

                $table->boolean('is_package')->default(false);
                $table->decimal('price', 14, 2)->default(0);
                $table->decimal('concession_amount', 14, 2)->default(0);
                $table->string('concession_reason', 300)->nullable();

                $table->text('operative_notes')->nullable();
                $table->text('findings')->nullable();
                // successful | complications | aborted | expired
                $table->string('outcome', 20)->nullable();
                $table->text('complications')->nullable();
                $table->unsignedInteger('blood_loss_ml')->nullable();
                $table->boolean('specimen_sent')->default(false);
                $table->text('post_op_instructions')->nullable();

                $table->string('cancel_reason', 300)->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();

                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->timestamp('charge_posted_at')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'operation_no'], 'health_op_no_unique');
                $table->index(['company_id', 'status'], 'health_op_status');
                $table->index(['company_id', 'scheduled_start'], 'health_op_when');
                $table->index(['company_id', 'health_admission_id'], 'health_op_admission');
                $table->index(['company_id', 'health_patient_id'], 'health_op_patient');
                $table->index(['company_id', 'primary_surgeon_id'], 'health_op_surgeon');
            });
        }

        /*
         * ── Operating team ──────────────────────────────────────────────────
         * `name` is frozen at the time the row is written so a register printed
         * two years later still says who was actually in the room, even if the
         * practitioner profile has since been renamed or retired.
         */
        if (!Schema::hasTable('health_operation_team')) {
            Schema::create('health_operation_team', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_operation_id');
                $table->unsignedBigInteger('health_doctor_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                $table->string('name', 150);
                // surgeon | assistant | anaesthetist | scrub_nurse |
                // circulating_nurse | technician | other
                $table->string('role', 24)->default('assistant');
                $table->decimal('fee_amount', 14, 2)->default(0);
                $table->string('note', 300)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_operation_id'], 'health_op_team_parent');
                $table->index(['company_id', 'health_doctor_id'], 'health_op_team_doctor');
            });
        }

        /*
         * ── Consumables ─────────────────────────────────────────────────────
         * What was used up. `is_billable` is false for anything a package
         * already covers, so the usage record stays complete (theatre stock
         * still needs to know) without charging the patient twice.
         */
        if (!Schema::hasTable('health_operation_consumables')) {
            Schema::create('health_operation_consumables', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_operation_id');

                $table->string('item_name', 200);
                $table->string('unit', 20)->nullable();
                $table->decimal('quantity', 12, 2)->default(1);
                $table->decimal('unit_price', 14, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->boolean('is_billable')->default(true);
                $table->string('note', 300)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_operation_id'], 'health_op_cons_parent');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_operation_consumables');
        Schema::dropIfExists('health_operation_team');
        Schema::dropIfExists('health_operations');
        Schema::dropIfExists('health_operation_theatres');
        Schema::dropIfExists('health_procedures');
        Schema::dropIfExists('health_admission_payments');
        Schema::dropIfExists('health_admission_charges');
        Schema::dropIfExists('health_admission_events');
        Schema::dropIfExists('health_admissions');
        Schema::dropIfExists('health_beds');
        Schema::dropIfExists('health_rooms');
        Schema::dropIfExists('health_wards');
    }
};
