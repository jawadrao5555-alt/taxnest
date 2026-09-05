<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare ERP — patient and OPD core (Task 1548).
 *
 * Everything the outpatient department needs before billing, pharmacy, IPD or
 * lab can be built on top of it:
 *
 *   health_number_sequences   monotonic per-company counters (MRN, token, …)
 *   health_patients           the person, registered once, found forever
 *   health_doctors            the practitioner, their fees and their room
 *   health_doctor_slots       weekly availability per doctor per branch
 *   health_appointments       the booking / walk-in token and its queue status
 *   health_visits             the encounter: vitals, notes, fee, follow-up
 *   health_visit_attachments  files filed against one encounter
 *   health_prescriptions      the doctor's structured prescription header
 *   health_prescription_items one medicine line, dose / route / frequency
 *
 * Two conventions carried over from the rest of the platform:
 *
 *  - Every add is individually hasTable/hasColumn guarded. The owner's live box
 *    has a history of migration rows marked "Ran" whose columns never landed,
 *    so a re-run has to be able to finish the job rather than blow up.
 *  - company_id carries no FK cascade here (the platform's own healthcare
 *    tables do not either), so every table below is registered in the admin
 *    hard-delete purge list instead. One of the two is mandatory; neither
 *    leaves orphan rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── Number sequences ────────────────────────────────────────────────
         * One row per (company, key, period). `period` is '' for a counter that
         * never resets (the medical record number) and a date for one that
         * resets daily (the OPD token).
         *
         * A medical record number must never be reused or rewound: it is how a
         * hospital finds the same human being again years later. So the counter
         * lives in its own row, is allocated under a row lock inside the same
         * transaction as the insert, and is never derived from COUNT(*) — a
         * deleted patient would otherwise hand their number to the next one.
         */
        if (!Schema::hasTable('health_number_sequences')) {
            Schema::create('health_number_sequences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('key', 40);            // mrn | token | visit | prescription
                // '' for a counter that never resets; 'YYYY-MM-DD:d{doctor}'
                // for the daily OPD token.
                $table->string('period', 40)->default('');
                $table->unsignedBigInteger('next_value')->default(1);
                $table->timestamps();

                $table->unique(['company_id', 'key', 'period'], 'health_seq_unique');
            });
        }

        /*
         * ── Patients ────────────────────────────────────────────────────────
         * branch_id is nullable and means "the whole organisation" — the same
         * convention departments use. A patient registered at one branch is
         * still the same patient at another; the branch only records where the
         * file was opened, and the scope service always includes NULL rows.
         */
        if (!Schema::hasTable('health_patients')) {
            Schema::create('health_patients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();

                $table->string('mrn', 32);            // medical record number
                $table->string('name');
                $table->string('guardian_name')->nullable();   // father / husband
                $table->string('gender', 10)->nullable();      // male | female | other
                $table->date('date_of_birth')->nullable();
                // Pakistani reception often knows the age, not the birthday.
                $table->unsignedSmallInteger('age_years')->nullable();
                $table->unsignedSmallInteger('age_months')->nullable();

                $table->string('phone', 32)->nullable();
                // Digits only, no country prefix — the key duplicate detection
                // actually matches on. Storing it saves normalising 40k rows on
                // every search.
                $table->string('phone_digits', 20)->nullable();
                $table->string('alt_phone', 32)->nullable();
                $table->string('email')->nullable();
                $table->string('cnic', 20)->nullable();
                $table->string('address', 500)->nullable();
                $table->string('city', 100)->nullable();

                $table->string('blood_group', 8)->nullable();
                $table->string('marital_status', 16)->nullable();
                $table->text('allergies')->nullable();
                $table->text('chronic_conditions')->nullable();

                $table->string('emergency_contact_name')->nullable();
                $table->string('emergency_contact_phone', 32)->nullable();
                $table->string('emergency_contact_relation', 60)->nullable();

                /*
                 * Consent is recorded, not assumed. Each flag is a separate
                 * decision the patient actually made, with the moment and the
                 * staff member who took it, because "we had consent" is only
                 * defensible if it says when and from whom.
                 */
                $table->boolean('consent_treatment')->default(false);
                $table->boolean('consent_share_reports')->default(false);
                $table->boolean('consent_contact')->default(false);
                $table->timestamp('consent_recorded_at')->nullable();
                $table->unsignedBigInteger('consent_recorded_by')->nullable();

                /*
                 * Confidential file. A patient may ask that their record not be
                 * browsable by the whole reception desk. When this is on, the
                 * clinical record opens only for the treating doctor and the
                 * organisation's own administrators — never for staff who just
                 * happen to hold clinical.view.
                 */
                $table->boolean('is_confidential')->default(false);

                $table->text('notes')->nullable();
                $table->unsignedBigInteger('registered_by')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'mrn']);
                $table->index(['company_id', 'phone_digits']);
                $table->index(['company_id', 'cnic']);
                $table->index(['company_id', 'name']);
                $table->index(['company_id', 'branch_id', 'is_active']);
            });
        }

        /*
         * ── Doctors ─────────────────────────────────────────────────────────
         * A practitioner profile is NOT a login. Plenty of visiting consultants
         * never sign in, and their patients still need to be booked to them, so
         * user_id is nullable and the profile stands on its own.
         */
        if (!Schema::hasTable('health_doctors')) {
            Schema::create('health_doctors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                $table->string('name');
                $table->string('specialty', 120)->nullable();
                $table->string('qualification', 200)->nullable();
                $table->string('registration_no', 60)->nullable();   // PMDC / PMC
                $table->string('phone', 32)->nullable();
                $table->string('email')->nullable();
                $table->string('gender', 10)->nullable();
                $table->string('room', 60)->nullable();

                // Fee schedule. follow_up_days = how long after a paid visit a
                // return counts as a follow-up rather than a new consultation.
                $table->decimal('consultation_fee', 12, 2)->default(0);
                $table->decimal('follow_up_fee', 12, 2)->default(0);
                $table->unsignedSmallInteger('follow_up_days')->default(0);
                $table->unsignedSmallInteger('slot_minutes')->default(15);

                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['company_id', 'is_active']);
                $table->index(['company_id', 'branch_id']);
                $table->index(['company_id', 'user_id']);
            });
        }

        /*
         * ── Weekly availability ─────────────────────────────────────────────
         * weekday follows Carbon: 0 = Sunday … 6 = Saturday. max_tokens caps the
         * walk-in queue for that sitting; 0 means no cap.
         */
        if (!Schema::hasTable('health_doctor_slots')) {
            Schema::create('health_doctor_slots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_doctor_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedTinyInteger('weekday');
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedSmallInteger('slot_minutes')->nullable();
                $table->unsignedSmallInteger('max_tokens')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['company_id', 'health_doctor_id', 'weekday'], 'health_slot_doctor_day');
            });
        }

        /*
         * ── Appointments / walk-in tokens ───────────────────────────────────
         * One row is the whole queue record: a booked slot, a walk-in token, or
         * both. A walk-in is not a different table — reception hands out a
         * token for today and the row is created already checked in, which is
         * why a single status machine covers both.
         *
         * status: booked → checked_in → in_consultation → completed
         *         and the two dead ends, cancelled and no_show.
         */
        if (!Schema::hasTable('health_appointments')) {
            Schema::create('health_appointments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_patient_id');
                $table->unsignedBigInteger('health_doctor_id');

                $table->string('kind', 12)->default('scheduled');  // scheduled | walkin
                $table->date('appointment_date');
                $table->time('appointment_time')->nullable();
                $table->unsignedInteger('token_no')->nullable();

                $table->string('status', 20)->default('booked');
                $table->string('reason', 500)->nullable();

                $table->timestamp('checked_in_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancel_reason', 300)->nullable();
                $table->timestamp('no_show_at')->nullable();

                $table->unsignedBigInteger('health_visit_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'appointment_date', 'status'], 'health_appt_day_status');
                $table->index(['company_id', 'health_doctor_id', 'appointment_date'], 'health_appt_doctor_day');
                $table->index(['company_id', 'health_patient_id'], 'health_appt_patient');
                $table->index(['company_id', 'branch_id', 'appointment_date'], 'health_appt_branch_day');
            });
        }

        /*
         * ── Visits (the encounter) ──────────────────────────────────────────
         * Created at check-in by reception, finished by the doctor. Vitals,
         * clinical text, the consultation fee and the follow-up all hang off
         * this one row so that later modules (billing, pharmacy, IPD) have a
         * single encounter to point at.
         *
         * The fee is captured HERE, against the visit and its doctor, rather
         * than in a billing table that does not exist yet. When the billing
         * module lands it reads this; it does not have to re-derive who was
         * seen by whom for how much.
         */
        if (!Schema::hasTable('health_visits')) {
            Schema::create('health_visits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_patient_id');
                $table->unsignedBigInteger('health_doctor_id');
                $table->unsignedBigInteger('health_appointment_id')->nullable();

                $table->string('visit_no', 32);
                $table->date('visit_date');
                $table->string('visit_type', 16)->default('new');   // new | follow_up | emergency
                $table->string('status', 20)->default('waiting');   // waiting | in_consultation | completed | cancelled

                // Vitals. Nullable throughout: a nurse records what was actually
                // measured, and a blank field must never read as a zero reading.
                $table->decimal('temperature_c', 5, 2)->nullable();
                $table->unsignedSmallInteger('pulse_bpm')->nullable();
                $table->unsignedSmallInteger('respiratory_rate')->nullable();
                $table->unsignedSmallInteger('bp_systolic')->nullable();
                $table->unsignedSmallInteger('bp_diastolic')->nullable();
                $table->unsignedSmallInteger('spo2')->nullable();
                $table->decimal('weight_kg', 6, 2)->nullable();
                $table->decimal('height_cm', 6, 2)->nullable();
                $table->decimal('blood_sugar', 6, 2)->nullable();
                $table->unsignedBigInteger('vitals_recorded_by')->nullable();
                $table->timestamp('vitals_recorded_at')->nullable();

                // Clinical record.
                $table->text('chief_complaint')->nullable();
                $table->text('history')->nullable();
                $table->text('examination')->nullable();
                $table->text('diagnosis')->nullable();
                $table->text('procedures')->nullable();
                $table->text('advice')->nullable();
                $table->text('clinical_notes')->nullable();

                $table->date('follow_up_date')->nullable();
                $table->string('follow_up_notes', 500)->nullable();

                // Consultation fee and concession.
                $table->decimal('fee_amount', 12, 2)->default(0);
                $table->decimal('concession_amount', 12, 2)->default(0);
                $table->string('concession_reason', 300)->nullable();
                $table->decimal('net_fee', 12, 2)->default(0);
                $table->string('fee_status', 16)->default('pending'); // pending | paid | waived
                $table->string('payment_method', 20)->nullable();     // cash | card | online | other
                $table->unsignedBigInteger('fee_collected_by')->nullable();
                $table->timestamp('fee_collected_at')->nullable();

                $table->unsignedBigInteger('opened_by')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->timestamp('consultation_started_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'visit_no']);
                $table->index(['company_id', 'visit_date', 'status'], 'health_visit_day_status');
                $table->index(['company_id', 'health_doctor_id', 'visit_date'], 'health_visit_doctor_day');
                $table->index(['company_id', 'health_patient_id'], 'health_visit_patient');
                $table->index(['company_id', 'branch_id', 'visit_date'], 'health_visit_branch_day');
            });
        }

        /*
         * ── Attachments ─────────────────────────────────────────────────────
         * Files live on the PRIVATE local disk under the healthcare directory
         * the platform service owns; only the pointer is stored here.
         */
        if (!Schema::hasTable('health_visit_attachments')) {
            Schema::create('health_visit_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_visit_id');
                $table->unsignedBigInteger('health_patient_id');
                $table->string('path', 500);
                $table->string('original_name', 255)->nullable();
                $table->string('mime', 100)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->string('kind', 20)->default('other');   // report | image | other
                $table->string('caption', 300)->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_visit_id'], 'health_attach_visit');
                $table->index(['company_id', 'health_patient_id'], 'health_attach_patient');
            });
        }

        /*
         * ── Prescriptions ───────────────────────────────────────────────────
         * Structured, not a free-text blob: the pharmacy module has to be able
         * to read a line and know the medicine, the dose and how many to hand
         * over. `status` stops at issued — dispensing is the pharmacy's own
         * state and is deliberately not modelled here.
         */
        if (!Schema::hasTable('health_prescriptions')) {
            Schema::create('health_prescriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_visit_id');
                $table->unsignedBigInteger('health_patient_id');
                $table->unsignedBigInteger('health_doctor_id');

                $table->string('prescription_no', 32);
                $table->string('status', 16)->default('draft');   // draft | issued
                $table->text('general_instructions')->nullable();
                $table->date('valid_until')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'prescription_no'], 'health_presc_no_unique');
                $table->index(['company_id', 'health_visit_id'], 'health_presc_visit');
                $table->index(['company_id', 'health_patient_id'], 'health_presc_patient');
            });
        }

        if (!Schema::hasTable('health_prescription_items')) {
            Schema::create('health_prescription_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_prescription_id');
                $table->unsignedSmallInteger('line_no')->default(1);

                $table->string('medicine_name');
                $table->string('generic_name')->nullable();
                $table->string('strength', 60)->nullable();
                $table->string('form', 30)->nullable();        // tablet | capsule | syrup | injection | …
                $table->string('dose', 60)->nullable();        // "1 tablet", "5 ml"
                $table->string('route', 20)->nullable();       // oral | iv | im | topical | …
                $table->string('frequency', 40)->nullable();   // "1+0+1", "TDS"
                $table->unsignedSmallInteger('duration_days')->nullable();
                $table->decimal('quantity', 10, 2)->nullable();
                $table->string('instructions', 300)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_prescription_id'], 'health_presc_item_parent');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_prescription_items');
        Schema::dropIfExists('health_prescriptions');
        Schema::dropIfExists('health_visit_attachments');
        Schema::dropIfExists('health_visits');
        Schema::dropIfExists('health_appointments');
        Schema::dropIfExists('health_doctor_slots');
        Schema::dropIfExists('health_doctors');
        Schema::dropIfExists('health_patients');
        Schema::dropIfExists('health_number_sequences');
    }
};
