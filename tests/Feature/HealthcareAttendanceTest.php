<?php

namespace Tests\Feature;

use App\Models\HealthAttendanceCorrection;
use App\Models\HealthAttendanceDay;
use App\Models\HealthAttendancePunch;
use App\Models\HealthHoliday;
use App\Models\HealthHrPolicy;
use App\Models\HealthRosterEntry;
use App\Models\HealthShift;
use App\Models\HealthStaffProfile;
use App\Models\User;
use App\Services\HealthAttendanceService;
use App\Services\HealthHrService;
use App\Services\HealthPayrollService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HEALTHCARE ATTENDANCE CALCULATION (Task 1553).
 *
 * Locks the promises the attendance half of the HR module makes, in the order
 * a hospital hits them:
 *
 *  1. OVERNIGHT DUTY IS ONE DAY. A night nurse's morning punch-out belongs to
 *     the night she started, never to the morning that follows — otherwise the
 *     shift is paid twice and the next day sprouts a mystery hour.
 *  2. INCOMPLETE EVIDENCE IS NAMED, NOT GUESSED. An odd punch count becomes
 *     whatever the organisation's policy says a missed punch costs.
 *  3. LATE / EARLY / OVERTIME COME OFF THE ROSTER, not off a clock reading.
 *  4. RAW PUNCHES ARE APPEND-ONLY. A correction adds evidence or sets it
 *     aside; it never edits or deletes the original.
 *  5. A LOCKED MONTH IS FROZEN. Recompute, corrections and the payroll export
 *     all respect the lock, and the export refuses an unlocked month.
 *
 * Pattern: APP_ENV=testing + sqlite :memory:, the same shape as
 * HealthcareFoundationTest, with the real HR migration run on top so the tests
 * exercise the shipped schema rather than a hand-written copy of it.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthcareAttendanceTest.php --testdox
 */
class HealthcareAttendanceTest extends TestCase
{
    private int $companyId = 1;
    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('health_org_type')->nullable();
            $table->text('health_modules')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('health_department_id')->nullable();
            $table->text('health_permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        // The shipped migration, not a copy of it.
        (require base_path('database/migrations/2026_10_09_100000_create_healthcare_hr_attendance.php'))->up();

        DB::table('companies')->insert([
            'id'         => $this->companyId,
            'name'       => 'Test Hospital',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->nurse = User::create([
            'name'       => 'Night Nurse',
            'email'      => 'nurse@example.test',
            'password'   => Hash::make('secret-not-a-real-password'),
            'role'       => 'health_nurse',
            'company_id' => $this->companyId,
        ]);

        HealthHrService::forget();
    }

    protected function tearDown(): void
    {
        HealthHrService::forget();
        parent::tearDown();
    }

    // ═══════════════════ helpers ═══════════════════

    private function policy(array $overrides = []): HealthHrPolicy
    {
        $policy = HealthHrService::policy($this->companyId);
        if ($overrides) {
            $policy->forceFill($overrides)->save();
            HealthHrService::forget();
            $policy = HealthHrService::policy($this->companyId);
        }

        return $policy;
    }

    private function shift(array $attributes = []): HealthShift
    {
        return HealthShift::create(array_merge([
            'company_id'       => $this->companyId,
            'name'             => 'Morning',
            'start_time'       => '09:00:00',
            'end_time'         => '17:00:00',
            'crosses_midnight' => false,
            'break_minutes'    => 0,
            'is_active'        => true,
        ], $attributes));
    }

    private function roster(HealthShift $shift, string $date, string $type = 'shift'): HealthRosterEntry
    {
        return HealthRosterEntry::create([
            'company_id'      => $this->companyId,
            'user_id'         => $this->nurse->id,
            'duty_date'       => $date,
            'entry_type'      => $type,
            'health_shift_id' => $shift->id,
        ]);
    }

    private function punch(string $at, string $direction): HealthAttendancePunch
    {
        return HealthAttendanceService::recordPunch([
            'company_id' => $this->companyId,
            'user_id'    => $this->nurse->id,
            'punched_at' => Carbon::parse($at),
            'direction'  => $direction,
            'source'     => 'biometric',
        ]);
    }

    private function day(string $date): ?HealthAttendanceDay
    {
        return HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('user_id', $this->nurse->id)
            ->whereDate('attendance_date', $date)
            ->first();
    }

    // ═══════════════════ 1. OVERNIGHT ═══════════════════

    public function test_a_night_shift_keeps_its_morning_punch_out_and_does_not_leak_into_the_next_day(): void
    {
        $this->policy();
        $night = $this->shift([
            'name'             => 'Night',
            'start_time'       => '20:00:00',
            'end_time'         => '08:00:00',
            'crosses_midnight' => true,
        ]);

        $this->roster($night, '2026-03-10');
        $this->roster($night, '2026-03-11');

        // One duty: in on the 10th at 20:00, out on the 11th at 08:00.
        $this->punch('2026-03-10 19:58:00', 'in');
        $this->punch('2026-03-11 08:02:00', 'out');

        HealthAttendanceService::recompute(
            $this->companyId,
            [$this->nurse->id],
            Carbon::parse('2026-03-10'),
            Carbon::parse('2026-03-11')
        );

        $first = $this->day('2026-03-10');
        $second = $this->day('2026-03-11');

        $this->assertNotNull($first);
        $this->assertSame('present', $first->status);
        $this->assertSame(2, (int) $first->punch_count);
        $this->assertSame(724, (int) $first->worked_minutes, 'the whole night belongs to the night it started');
        $this->assertContains(HealthAttendanceDay::FLAG_OVERNIGHT, $first->flags());

        // The morning that follows must not also claim the 08:02 punch.
        $this->assertNotNull($second);
        $this->assertSame(0, (int) $second->punch_count);
        $this->assertSame('absent', $second->status);
    }

    // ═══════════════════ 2. MISSED PUNCH ═══════════════════

    public function test_a_single_punch_becomes_whatever_the_policy_says_a_missed_punch_costs(): void
    {
        $this->policy(['missed_punch_status' => 'missed_punch']);
        $morning = $this->shift();
        $this->roster($morning, '2026-03-12');

        $this->punch('2026-03-12 09:00:00', 'in');

        HealthAttendanceService::recomputeDay($this->companyId, $this->nurse->id, Carbon::parse('2026-03-12'));

        $day = $this->day('2026-03-12');
        $this->assertSame('missed_punch', $day->status);
        $this->assertContains(HealthAttendanceDay::FLAG_MISSED_PUNCH, $day->flags());

        // The organisation may decide a missed punch is simply an absence.
        $this->policy(['missed_punch_status' => 'absent']);
        HealthAttendanceService::recomputeDay($this->companyId, $this->nurse->id, Carbon::parse('2026-03-12'));

        $this->assertSame('absent', $this->day('2026-03-12')->status);
    }

    // ═══════════════════ 3. LATE / EARLY / OVERTIME ═══════════════════

    public function test_late_and_early_are_measured_against_the_rostered_shift_and_its_grace(): void
    {
        $this->policy(['grace_in_minutes' => 15, 'grace_out_minutes' => 10]);
        $morning = $this->shift();
        $this->roster($morning, '2026-03-13');

        // 40 minutes late, 30 minutes early away.
        $this->punch('2026-03-13 09:40:00', 'in');
        $this->punch('2026-03-13 16:30:00', 'out');

        HealthAttendanceService::recomputeDay($this->companyId, $this->nurse->id, Carbon::parse('2026-03-13'));

        $day = $this->day('2026-03-13');
        $this->assertSame(25, (int) $day->late_minutes, '40 minutes late less the 15 minute grace');
        $this->assertSame(20, (int) $day->early_leave_minutes, '30 minutes early less the 10 minute grace');
        $this->assertContains(HealthAttendanceDay::FLAG_LATE, $day->flags());
        $this->assertContains(HealthAttendanceDay::FLAG_EARLY_LEAVE, $day->flags());
    }

    public function test_overtime_only_counts_past_the_minimum_and_only_for_eligible_staff(): void
    {
        $this->policy(['overtime_enabled' => true, 'min_overtime_minutes' => 30]);
        $morning = $this->shift();
        $this->roster($morning, '2026-03-14');

        // Eight scheduled hours, ten worked.
        $this->punch('2026-03-14 09:00:00', 'in');
        $this->punch('2026-03-14 19:00:00', 'out');

        HealthAttendanceService::recomputeDay($this->companyId, $this->nurse->id, Carbon::parse('2026-03-14'));

        $day = $this->day('2026-03-14');
        $this->assertSame(120, (int) $day->overtime_minutes);
        $this->assertContains(HealthAttendanceDay::FLAG_OVERTIME, $day->flags());

        // Somebody paid a flat retainer is marked ineligible; the extra hours
        // still show as worked, they simply do not become an overtime claim.
        $profile = HealthHrService::profile($this->companyId, $this->nurse->id);
        $profile->forceFill(['overtime_eligible' => false])->save();
        HealthHrService::forget();

        HealthAttendanceService::recomputeDay($this->companyId, $this->nurse->id, Carbon::parse('2026-03-14'));

        $day = $this->day('2026-03-14');
        $this->assertSame(0, (int) $day->overtime_minutes);
        $this->assertSame(600, (int) $day->worked_minutes);
    }

    public function test_a_holiday_worked_is_recorded_as_worked_and_an_exempt_person_never_counts_as_absent(): void
    {
        $this->policy();

        HealthHoliday::create([
            'company_id'   => $this->companyId,
            'name'         => 'Eid',
            'holiday_date' => '2026-03-15',
            'is_paid'      => true,
        ]);

        HealthAttendanceService::recomputeDay($this->companyId, $this->nurse->id, Carbon::parse('2026-03-15'));
        $this->assertSame('holiday', $this->day('2026-03-15')->status);

        // A visiting consultant who does not punch must never read as absent.
        $profile = HealthHrService::profile($this->companyId, $this->nurse->id);
        $profile->forceFill(['attendance_exempt' => true])->save();
        HealthHrService::forget();

        HealthAttendanceService::recomputeDay($this->companyId, $this->nurse->id, Carbon::parse('2026-03-16'));
        $this->assertSame('exempt', $this->day('2026-03-16')->status);
    }

    // ═══════════════════ 4. CORRECTIONS KEEP THE EVIDENCE ═══════════════════

    public function test_an_approved_correction_adds_evidence_instead_of_editing_it(): void
    {
        $this->policy();
        $morning = $this->shift();
        $this->roster($morning, '2026-03-17');

        $this->punch('2026-03-17 09:00:00', 'in');
        HealthAttendanceService::recomputeDay($this->companyId, $this->nurse->id, Carbon::parse('2026-03-17'));
        $this->assertSame('missed_punch', $this->day('2026-03-17')->status);

        $correction = HealthAttendanceCorrection::create([
            'company_id'      => $this->companyId,
            'user_id'         => $this->nurse->id,
            'attendance_date' => '2026-03-17',
            'type'            => 'add_punch',
            'punch_at'        => '2026-03-17 17:05:00',
            'direction'       => 'out',
            'reason'          => 'Device was down at the ward door.',
            'requested_by'    => $this->nurse->id,
            'status'          => 'approved',
            'reviewed_by'     => $this->nurse->id,
            'reviewed_at'     => now(),
        ]);

        $this->assertTrue(HealthAttendanceService::applyCorrection($correction));

        $day = $this->day('2026-03-17');
        $this->assertSame('present', $day->status);
        $this->assertSame(2, (int) $day->punch_count);

        // The original biometric punch is untouched and the new one is manual,
        // stamped with the correction that created it.
        $punches = HealthAttendancePunch::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->orderBy('punched_at')
            ->get();

        $this->assertCount(2, $punches);
        $this->assertSame('biometric', $punches[0]->source);
        $this->assertNull($punches[0]->disregarded_at);
        $this->assertSame('manual', $punches[1]->source);
        $this->assertSame((int) $correction->id, (int) $punches[1]->correction_id);
    }

    public function test_a_disregarded_punch_stays_on_the_timeline_and_stops_counting(): void
    {
        $this->policy();
        $morning = $this->shift();
        $this->roster($morning, '2026-03-18');

        $this->punch('2026-03-18 09:00:00', 'in');
        $stray = $this->punch('2026-03-18 09:02:00', 'out'); // double tap at the door
        $this->punch('2026-03-18 17:00:00', 'out');

        $correction = HealthAttendanceCorrection::create([
            'company_id'      => $this->companyId,
            'user_id'         => $this->nurse->id,
            'attendance_date' => '2026-03-18',
            'type'            => 'disregard_punch',
            'target_punch_id' => $stray->id,
            'reason'          => 'Double tap on the reader.',
            'requested_by'    => $this->nurse->id,
            'status'          => 'approved',
            'reviewed_by'     => $this->nurse->id,
            'reviewed_at'     => now(),
        ]);

        $this->assertTrue(HealthAttendanceService::applyCorrection($correction));

        $stray->refresh();
        $this->assertNotNull($stray->disregarded_at, 'the punch is set aside, never deleted');
        $this->assertSame('Double tap on the reader.', $stray->disregard_reason);
        $this->assertFalse($stray->isCounted());

        $this->assertSame(3, HealthAttendancePunch::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->count());

        $day = $this->day('2026-03-18');
        $this->assertSame('present', $day->status);
        $this->assertSame(480, (int) $day->worked_minutes);
    }

    // ═══════════════════ 5. THE LOCK ═══════════════════

    public function test_a_locked_month_refuses_recomputation_corrections_and_an_unlocked_export(): void
    {
        $this->policy();
        $morning = $this->shift();
        $this->roster($morning, '2026-03-19');

        $this->punch('2026-03-19 09:00:00', 'in');
        $this->punch('2026-03-19 17:00:00', 'out');
        HealthAttendanceService::recomputeDay($this->companyId, $this->nurse->id, Carbon::parse('2026-03-19'));

        $profile = HealthHrService::profile($this->companyId, $this->nurse->id);
        $profile->forceFill(['basic_salary' => 60000])->save();
        HealthHrService::forget();

        $staff = User::where('company_id', $this->companyId)->get();

        // Before the lock there is nothing to hand payroll.
        $this->assertNull(HealthPayrollService::lock($this->companyId, 2026, 3));

        $lock = HealthPayrollService::lockMonth($this->companyId, 2026, 3, $this->nurse->id, $staff, 'March approved');
        $this->assertNotNull($lock);
        $this->assertTrue(HealthAttendanceService::isMonthLocked($this->companyId, 2026, 3));

        $totals = HealthPayrollService::monthlyTotals($this->companyId, 2026, 3, $staff);
        $this->assertSame(1.0, (float) $totals[$this->nurse->id]['payable_days']);

        // A late punch cannot restate a month payroll already went out on.
        $this->punch('2026-03-19 21:00:00', 'in');
        $this->assertSame(0, HealthAttendanceService::recompute(
            $this->companyId,
            [$this->nurse->id],
            Carbon::parse('2026-03-19'),
            Carbon::parse('2026-03-19')
        ));
        $this->assertSame(2, (int) $this->day('2026-03-19')->punch_count);

        $late = HealthAttendanceCorrection::create([
            'company_id'      => $this->companyId,
            'user_id'         => $this->nurse->id,
            'attendance_date' => '2026-03-19',
            'type'            => 'set_status',
            'requested_status' => 'absent',
            'reason'          => 'Tried after the lock.',
            'requested_by'    => $this->nurse->id,
            'status'          => 'approved',
            'reviewed_by'     => $this->nurse->id,
            'reviewed_at'     => now(),
        ]);
        $this->assertFalse(HealthAttendanceService::applyCorrection($late));
        $this->assertSame('present', $this->day('2026-03-19')->status);

        // Unlocking reopens the month; the snapshot stays on the row.
        $this->assertTrue(HealthPayrollService::unlockMonth($this->companyId, 2026, 3, $this->nurse->id));
        $this->assertFalse(HealthAttendanceService::isMonthLocked($this->companyId, 2026, 3));
        $this->assertNotEmpty(HealthPayrollService::lock($this->companyId, 2026, 3)->totals);

        // And the export shape stays stable for whoever runs the salaries.
        $rows = HealthPayrollService::exportRows($totals);
        $this->assertSame('Employee Code', $rows[0][0]);
        $this->assertCount(count($rows[0]), $rows[1]);
    }

    public function test_a_one_day_recompute_leaves_the_previous_nights_punch_out_where_it_belongs(): void
    {
        $this->policy(['missed_punch_status' => 'missed_punch']);

        $night = $this->shift([
            'name'             => 'Night',
            'start_time'       => '20:00:00',
            'end_time'         => '08:00:00',
            'crosses_midnight' => true,
        ]);
        $morning = $this->shift();

        $this->roster($night, '2026-03-24');
        $this->roster($morning, '2026-03-25');

        // The night that ended this morning, then a full morning duty.
        $this->punch('2026-03-24 20:00:00', 'in');
        $this->punch('2026-03-25 08:00:00', 'out');
        $this->punch('2026-03-25 09:00:00', 'in');
        $this->punch('2026-03-25 17:00:00', 'out');

        // Recompute ONLY the 25th — what a roster edit or a manual recompute
        // of a single day actually does. The 08:00 checkout is not the 25th's
        // to claim, and counting it would turn a completed morning into a
        // missed punch.
        HealthAttendanceService::recomputeDay($this->companyId, $this->nurse->id, Carbon::parse('2026-03-25'));

        $day = $this->day('2026-03-25');
        $this->assertNotNull($day);
        $this->assertSame(2, (int) $day->punch_count, 'only the morning duty belongs to the 25th');
        $this->assertSame('present', $day->status);
        $this->assertSame(480, (int) $day->worked_minutes);
        $this->assertNotContains(HealthAttendanceDay::FLAG_MISSED_PUNCH, $day->flags());
    }

    public function test_a_locked_month_is_found_even_when_the_range_starts_on_a_day_it_has_not_got(): void
    {
        $this->policy();

        HealthPayrollService::lockMonth(
            $this->companyId,
            2026,
            2,
            $this->nurse->id,
            User::where('company_id', $this->companyId)->get(),
            'February approved'
        );

        // 31 Jan + 1 month = 28 Feb. Walking a range that way from its own
        // first day skips February entirely, and the locked month would be
        // editable straight through.
        $this->assertSame('2026-02', HealthAttendanceService::lockedMonthInRange(
            $this->companyId,
            Carbon::parse('2026-01-31'),
            Carbon::parse('2026-02-01')
        ));

        // A range that ends before the lock is still free.
        $this->assertNull(HealthAttendanceService::lockedMonthInRange(
            $this->companyId,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31')
        ));

        // And the order of the two ends must not decide the answer.
        $this->assertSame('2026-02', HealthAttendanceService::lockedMonthInRange(
            $this->companyId,
            Carbon::parse('2026-03-05'),
            Carbon::parse('2026-01-31')
        ));
    }

    public function test_a_staff_profile_never_creates_a_second_login(): void
    {
        $this->policy();

        $first = HealthHrService::profile($this->companyId, $this->nurse->id);
        $second = HealthHrService::profile($this->companyId, $this->nurse->id);

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, HealthStaffProfile::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->count());
        $this->assertSame(1, User::where('company_id', $this->companyId)->count());
    }
}
