<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare HR & attendance schema (Task 1553).
 *
 * Eleven healthcare-owned tables that turn the foundation's staff identities
 * into a real HR module: employment records, work patterns, duty rosters,
 * leave, holidays, one normalised punch timeline, its computed daily summary,
 * the correction/approval trail and the monthly lock the payroll handoff reads.
 *
 * TWO structural promises are encoded here and must never be softened:
 *
 *  1. RAW EVIDENCE IS APPEND-ONLY. `health_attendance_punches` rows are never
 *     edited or deleted. A correction adds a row (source = manual) or marks an
 *     existing row disregarded — the original punch, its device and its
 *     timestamp stay readable forever.
 *  2. THE DAILY SUMMARY IS DERIVED. `health_attendance_days` is recomputed from
 *     the punches + the roster on demand, so a policy fix repairs history
 *     instead of needing a data patch. Once a month is locked the summary
 *     freezes (is_locked) and the recompute refuses to touch it.
 *
 * Every add is individually hasTable/hasColumn guarded, the same as the
 * foundation migration: the owner's PROD box has a history of migrations marked
 * "Ran" whose columns never landed, so this file must be safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Organisation-level attendance policy. One row per company; the
        //    service seeds it lazily with the defaults below.
        if (!Schema::hasTable('health_hr_policies')) {
            Schema::create('health_hr_policies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');

                // The attendance day boundary. A night nurse who punches out at
                // 04:00 belongs to the PREVIOUS duty date, exactly like the POS
                // business day — never whereDate() on the raw punch time.
                $table->time('business_day_start')->default('06:00:00');

                $table->unsignedSmallInteger('grace_in_minutes')->default(15);
                $table->unsignedSmallInteger('grace_out_minutes')->default(10);
                $table->unsignedSmallInteger('half_day_minutes')->default(240);
                $table->unsignedSmallInteger('full_day_minutes')->default(480);

                $table->boolean('overtime_enabled')->default(true);
                $table->unsignedSmallInteger('min_overtime_minutes')->default(30);

                // What a day with an odd number of punches becomes:
                // missed_punch | absent | half_day
                $table->string('missed_punch_status', 20)->default('missed_punch');

                // JSON array of ISO weekday numbers (1 = Monday … 7 = Sunday).
                $table->text('weekly_off_days')->nullable();

                $table->boolean('biometric_enabled')->default(true);
                $table->boolean('web_checkin_enabled')->default(true);
                $table->boolean('mobile_checkin_enabled')->default(true);
                $table->boolean('session_punch_enabled')->default(false);
                $table->boolean('geo_required')->default(false);
                $table->unsignedSmallInteger('geo_radius_m')->default(300);
                $table->boolean('cross_branch_allowed')->default(true);

                $table->timestamps();
                $table->unique('company_id', 'health_hr_policy_company_unique');
            });
        }

        // ── Shift templates. A split shift is ONE template with two spans, not
        //    two rosters: the roster must stay one row per person per day or
        //    coverage counting silently double-counts.
        if (!Schema::hasTable('health_shifts')) {
            Schema::create('health_shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('name');
                $table->string('code', 32)->nullable();

                $table->time('start_time');
                $table->time('end_time');
                // Second span of a split duty (e.g. 09:00-13:00 + 17:00-21:00).
                $table->time('second_start_time')->nullable();
                $table->time('second_end_time')->nullable();

                $table->unsignedSmallInteger('break_minutes')->default(0);
                // NULL = fall back to the organisation policy.
                $table->unsignedSmallInteger('grace_in_minutes')->nullable();
                $table->unsignedSmallInteger('grace_out_minutes')->nullable();

                // Stored, not derived at read time: an end time before the start
                // time means the duty runs past midnight, and every query that
                // builds the punch window needs to know without re-deriving.
                $table->boolean('crosses_midnight')->default(false);
                $table->boolean('is_on_call')->default(false);
                $table->string('colour', 16)->default('teal');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['company_id', 'is_active']);
                $table->unique(['company_id', 'code'], 'health_shift_code_unique');
            });
        }

        // ── Employment record. Deliberately a SEPARATE table keyed to the
        //    existing user: a doctor already has one login, and a second
        //    identity for HR would drift from it the first time somebody is
        //    renamed or deactivated.
        if (!Schema::hasTable('health_staff_profiles')) {
            Schema::create('health_staff_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');

                $table->string('employee_code', 32)->nullable();
                $table->string('designation', 120)->nullable();
                // permanent | contract | visiting | locum | intern | daily_wage
                $table->string('employment_type', 20)->default('permanent');
                // active | probation | notice | suspended | left
                $table->string('employment_status', 20)->default('active');
                $table->date('joined_on')->nullable();
                $table->date('left_on')->nullable();

                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('supervisor_user_id')->nullable();
                $table->unsignedBigInteger('default_shift_id')->nullable();

                // NULL = use the organisation policy's weekly off days.
                $table->text('weekly_off_days')->nullable();
                // Visiting consultants who are paid per session do not punch.
                $table->boolean('attendance_exempt')->default(false);
                $table->boolean('overtime_eligible')->default(true);

                // Payroll INPUTS only. Nothing here files anything anywhere;
                // the payroll handoff multiplies them by approved attendance.
                $table->decimal('basic_salary', 12, 2)->nullable();
                $table->decimal('overtime_hourly_rate', 10, 2)->nullable();

                $table->string('qualification', 190)->nullable();
                // PMDC / PMC / Pharmacy Council registration number.
                $table->string('license_no', 64)->nullable();
                $table->string('cnic', 20)->nullable();
                $table->string('emergency_contact', 120)->nullable();
                $table->string('notes', 500)->nullable();

                $table->timestamps();

                $table->unique(['company_id', 'user_id'], 'health_staff_user_unique');
                $table->unique(['company_id', 'employee_code'], 'health_staff_code_unique');
                $table->index(['company_id', 'employment_status']);
            });
        }

        // ── Holidays. branch_id NULL = the whole organisation is closed.
        if (!Schema::hasTable('health_holidays')) {
            Schema::create('health_holidays', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('name');
                $table->date('holiday_date');
                $table->boolean('is_paid')->default(true);
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'holiday_date']);
            });
        }

        // ── Leave types. Seeded per company on first use by the service.
        if (!Schema::hasTable('health_leave_types')) {
            Schema::create('health_leave_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('name');
                $table->string('code', 20)->nullable();
                $table->decimal('annual_quota_days', 5, 1)->default(0);
                $table->boolean('is_paid')->default(true);
                $table->boolean('requires_approval')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'code'], 'health_leave_type_code_unique');
            });
        }

        if (!Schema::hasTable('health_leave_requests')) {
            Schema::create('health_leave_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('health_leave_type_id')->nullable();

                $table->date('start_date');
                $table->date('end_date');
                $table->decimal('days', 5, 1)->default(1);
                $table->boolean('is_half_day')->default(false);
                $table->string('reason', 500)->nullable();

                // pending | approved | rejected | cancelled
                $table->string('status', 16)->default('pending');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->string('review_note', 500)->nullable();

                $table->timestamps();

                $table->index(['company_id', 'status']);
                $table->index(['company_id', 'user_id', 'start_date'], 'health_leave_user_date_index');
            });
        }

        // ── Duty roster: exactly ONE row per person per date.
        if (!Schema::hasTable('health_roster_entries')) {
            Schema::create('health_roster_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                $table->date('duty_date');

                $table->unsignedBigInteger('health_shift_id')->nullable();
                // A nurse covering another branch for one night: the roster row
                // carries the branch, so the attendance day is cross-branch
                // without touching the person's permanent posting.
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();

                // shift | off | on_call | leave | holiday
                $table->string('entry_type', 16)->default('shift');
                $table->string('notes', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'user_id', 'duty_date'], 'health_roster_unique');
                $table->index(['company_id', 'duty_date']);
            });
        }

        // ── THE evidence timeline. Append-only: see the file header.
        if (!Schema::hasTable('health_attendance_punches')) {
            Schema::create('health_attendance_punches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                // NULL while a biometric PIN is still unmapped — the evidence is
                // kept and attaches itself the moment the PIN is mapped.
                $table->unsignedBigInteger('user_id')->nullable();

                $table->dateTime('punched_at');
                // in | out | unknown
                $table->string('direction', 8)->default('unknown');
                // biometric | web | mobile | session | manual | import
                $table->string('source', 16)->default('web');
                // Stable identity of the row this was mirrored from, e.g.
                // "pos_punch:912" or "session:44". Makes the mirror idempotent.
                $table->string('source_ref', 64)->nullable();

                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('device_pin', 32)->nullable();

                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('note', 255)->nullable();

                // Who typed a manual punch, and which approved correction it came from.
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->unsignedBigInteger('correction_id')->nullable();

                // Disregarded, NOT deleted: the row stays visible on the
                // timeline with its reason, the calculation skips it.
                $table->dateTime('disregarded_at')->nullable();
                $table->unsignedBigInteger('disregarded_by')->nullable();
                $table->unsignedBigInteger('disregarded_correction_id')->nullable();
                $table->string('disregard_reason', 255)->nullable();

                $table->timestamps();

                $table->index(['company_id', 'user_id', 'punched_at'], 'health_punch_user_time_index');
                $table->index(['company_id', 'punched_at']);
                $table->unique(['company_id', 'source', 'source_ref'], 'health_punch_source_unique');
            });
        }

        // ── Correction / override requests. A reason is mandatory and the
        //    review trail is never overwritten.
        if (!Schema::hasTable('health_attendance_corrections')) {
            Schema::create('health_attendance_corrections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                $table->date('attendance_date');

                // add_punch | disregard_punch | set_status | set_hours
                $table->string('type', 20)->default('add_punch');
                $table->dateTime('punch_at')->nullable();
                $table->string('direction', 8)->nullable();
                $table->unsignedBigInteger('target_punch_id')->nullable();
                $table->string('requested_status', 20)->nullable();
                $table->unsignedSmallInteger('requested_minutes')->nullable();

                $table->string('reason', 500);
                // pending | approved | rejected
                $table->string('status', 16)->default('pending');

                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->string('review_note', 500)->nullable();
                $table->dateTime('applied_at')->nullable();

                $table->timestamps();

                $table->index(['company_id', 'status']);
                $table->index(['company_id', 'user_id', 'attendance_date'], 'health_corr_user_date_index');
            });
        }

        // ── Derived daily summary. Recomputed from punches + roster.
        if (!Schema::hasTable('health_attendance_days')) {
            Schema::create('health_attendance_days', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                $table->date('attendance_date');

                $table->unsignedBigInteger('health_shift_id')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();

                $table->dateTime('shift_start')->nullable();
                $table->dateTime('shift_end')->nullable();
                $table->dateTime('first_in')->nullable();
                $table->dateTime('last_out')->nullable();

                $table->integer('scheduled_minutes')->default(0);
                $table->integer('worked_minutes')->default(0);
                $table->integer('break_minutes')->default(0);
                $table->integer('late_minutes')->default(0);
                $table->integer('early_leave_minutes')->default(0);
                $table->integer('overtime_minutes')->default(0);

                // present | half_day | absent | leave | holiday | weekly_off
                // | on_call | missed_punch | exempt
                $table->string('status', 20)->default('absent');
                // JSON list of flags: overnight, split, cross_branch, open_span…
                $table->text('exceptions')->nullable();

                $table->unsignedSmallInteger('punch_count')->default(0);
                $table->boolean('is_open')->default(false);
                $table->boolean('cross_branch')->default(false);
                $table->unsignedBigInteger('leave_request_id')->nullable();

                // An approved override froze this row; recompute leaves it alone.
                $table->boolean('is_manual')->default(false);
                $table->unsignedBigInteger('correction_id')->nullable();

                $table->dateTime('computed_at')->nullable();
                $table->boolean('is_locked')->default(false);

                $table->timestamps();

                $table->unique(['company_id', 'user_id', 'attendance_date'], 'health_att_day_unique');
                $table->index(['company_id', 'attendance_date']);
                $table->index(['company_id', 'status']);
            });
        }

        // ── Monthly lock: the gate the payroll handoff sits behind.
        if (!Schema::hasTable('health_attendance_locks')) {
            Schema::create('health_attendance_locks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedSmallInteger('period_year');
                $table->unsignedTinyInteger('period_month');

                $table->unsignedBigInteger('locked_by')->nullable();
                $table->dateTime('locked_at')->nullable();
                $table->string('note', 255)->nullable();
                // Snapshot of the per-staff totals AT LOCK TIME, so a later
                // policy change can never rewrite a month payroll already paid.
                $table->longText('totals')->nullable();

                $table->unsignedBigInteger('unlocked_by')->nullable();
                $table->dateTime('unlocked_at')->nullable();

                $table->timestamps();

                $table->unique(['company_id', 'period_year', 'period_month'], 'health_att_lock_unique');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'health_attendance_locks',
            'health_attendance_days',
            'health_attendance_corrections',
            'health_attendance_punches',
            'health_roster_entries',
            'health_leave_requests',
            'health_leave_types',
            'health_holidays',
            'health_staff_profiles',
            'health_shifts',
            'health_hr_policies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
