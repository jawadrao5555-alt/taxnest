<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HealthAttendancePunch;
use App\Models\HealthDepartment;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthHrService;
use App\Services\HealthModuleService;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HEALTHCARE ERP FOUNDATION (Task 1547).
 *
 * Locks the five promises the foundation makes, in the order they are made:
 *
 *  1. MODULE GATING — a module is live only when the PACKAGE sells it AND the
 *     owner switched it on. A downgrade masks; it never destroys the owner's
 *     stored choice. Capabilities of an off module are unreachable for
 *     EVERYONE, the owner included.
 *  2. ROLE SEPARATION — least-privilege defaults per role, an auditor that
 *     cannot write however the permission was granted, owner-only capabilities
 *     that no delegation can hand out, and an owner whose access is COMPUTED
 *     from the enabled modules (so a new module needs no role-table edit).
 *  3. PANEL ISOLATION — a PRA POS / DI account is refused at /health/login, a
 *     healthcare account is refused at the POS panels, and the panel
 *     middleware refuses a signed-in session whose company is not healthcare.
 *  4. BRANCH + DEPARTMENT BOUNDARIES — a nurse posted to one department must
 *     not see the other, in the same branch.
 *  5. PATH-LEVEL ENFORCEMENT — the capability a screen needs is derived from
 *     its PATH, so a future route that forgets its middleware argument still
 *     cannot open a medical or financial screen.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create, the same
 * shape as Phase3LoginIsolationTest / FbrPosPlanGatingTest.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthcareFoundationTest.php --testdox
 */
class HealthcareFoundationTest extends TestCase
{
    private int $healthCompanyId;
    private int $posCompanyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('user_limit_override')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('health_org_type')->nullable();
            $table->text('health_modules')->nullable();
            $table->boolean('health_setup_completed')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('health_role')->nullable();
            $table->text('health_permissions')->nullable();
            $table->unsignedBigInteger('health_department_id')->nullable();
            $table->string('language')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('health');
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->default(-1);
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('user_limit')->nullable();
            $table->text('features')->nullable();
            $table->text('health_modules')->nullable();
            $table->integer('health_department_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('billing_cycle')->default('annual');
            $table->decimal('final_price', 12, 2)->default(0);
            // `active` only — the real table has no is_active column, and a
            // service that queries one throws and reads as "no plan at all".
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('none');
            $table->dateTime('override_until')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
        });

        Schema::create('health_departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type')->default('clinical');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('health_department_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('health_department_id');
            $table->unsignedBigInteger('user_id');
        });

        // Side-effect tables written during a login attempt.
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->seedFixtures();
        $this->forgetHealthCaches();
    }

    private function seedFixtures(): void
    {
        // ── Packages: a small Clinic (OPD + accounts) and a full Hospital ──
        DB::table('pricing_plans')->insert([
            [
                'id' => 1, 'name' => 'Clinic', 'product_type' => 'health', 'is_trial' => false,
                'price' => 24999, 'health_modules' => json_encode(['opd', 'accounts']),
                'health_department_limit' => 8, 'user_limit' => 2,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 2, 'name' => 'Hospital', 'product_type' => 'health', 'is_trial' => false,
                'price' => 59999, 'health_modules' => json_encode(HealthModuleService::MODULES),
                'health_department_limit' => -1, 'user_limit' => -1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        // ── Healthcare company on the Clinic package ──
        $this->healthCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Shifa Clinic', 'product_type' => 'health',
            'status' => 'approved', 'company_status' => 'active',
            'health_org_type' => 'clinic',
            // The owner ticked FIVE modules; the Clinic package only sells two.
            'health_modules' => json_encode(['opd', 'pharmacy', 'ipd', 'lab', 'accounts']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $this->healthCompanyId, 'pricing_plan_id' => 1,
            'billing_cycle' => 'annual', 'final_price' => 24999,
            'active' => true,
            'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'name' => 'Clinic Owner', 'email' => 'owner@shifa.test',
            'password' => Hash::make('Health@12345'),
            'company_id' => $this->healthCompanyId, 'role' => 'company_admin',
            'health_role' => HealthAccessService::ROLE_OWNER, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── A POS company + user, to prove the two panels never mix ──
        $this->posCompanyId = DB::table('companies')->insertGetId([
            'name' => 'POS Co', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'POS User', 'email' => 'pos@shop.test',
            'password' => Hash::make('POS@12345'),
            'company_id' => $this->posCompanyId, 'role' => 'company_admin',
            'pos_role' => 'pos_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Both services memoise per request; a test changes state mid-request. */
    private function forgetHealthCaches(): void
    {
        HealthModuleService::forget();
        HealthScopeService::forget();
    }

    private function healthCompany(): Company
    {
        $this->forgetHealthCaches();

        return Company::findOrFail($this->healthCompanyId);
    }

    private function makeStaff(string $role, array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role),
            'email' => $role . '.' . uniqid() . '@shifa.test',
            'password' => Hash::make('Staff@12345'),
            'company_id' => $this->healthCompanyId,
            'role' => 'user',
            'health_role' => $role,
            'is_active' => true,
        ], $attributes));

        $this->forgetHealthCaches();

        return $user;
    }

    private function upgradeToHospital(): void
    {
        DB::table('subscriptions')->where('company_id', $this->healthCompanyId)
            ->update(['pricing_plan_id' => 2]);
        $this->forgetHealthCaches();
    }

    // ═══════════════════ 1. MODULE GATING ═══════════════════

    public function test_a_module_is_live_only_when_the_package_sells_it_and_the_owner_enabled_it(): void
    {
        $company = $this->healthCompany();

        // Owner ticked five; the Clinic package sells two.
        $this->assertSame(['opd', 'accounts'], HealthModuleService::enabled($company));
        $this->assertTrue(HealthModuleService::isEnabled($company, 'opd'));
        $this->assertFalse(HealthModuleService::isEnabled($company, 'ipd'));

        // HR is sold by neither side.
        $this->assertFalse(HealthModuleService::isEnabled($company, 'hr'));
    }

    public function test_an_upgrade_restores_the_owners_own_choice_without_re_ticking(): void
    {
        $this->upgradeToHospital();
        $company = $this->healthCompany();

        // The stored set was never destroyed by the smaller package — the plan
        // only masked it, so the upgrade brings all five back at once.
        $this->assertSame(
            ['opd', 'pharmacy', 'ipd', 'lab', 'accounts'],
            HealthModuleService::enabled($company)
        );
        // HR still off: the owner never asked for it, even though it is sold.
        $this->assertFalse(HealthModuleService::isEnabled($company, 'hr'));
    }

    public function test_the_switch_refuses_to_turn_on_a_module_the_package_does_not_sell(): void
    {
        $company = $this->healthCompany();

        $stored = HealthModuleService::setForCompany($company, ['opd', 'accounts', 'ipd', 'hr']);

        // Only what the Clinic package sells is accepted...
        $this->assertSame(['opd', 'accounts'], $stored);
        // ...and the switch never claims otherwise on the next read.
        $this->assertSame(['opd', 'accounts'], HealthModuleService::enabled($this->healthCompany()));
    }

    public function test_an_off_module_removes_its_capabilities_from_everyone_including_the_owner(): void
    {
        $company = $this->healthCompany();
        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();

        // IPD is off (package), so nobody holds ipd.view — not even the owner.
        $this->assertFalse(HealthAccessService::can($owner, 'ipd.view', $company));
        // A capability of an enabled module is reachable.
        $this->assertTrue(HealthAccessService::can($owner, 'appointments.manage', $company));

        $this->upgradeToHospital();
        $company = $this->healthCompany();

        // Owner access is COMPUTED from enabled modules — no role-table edit.
        $this->assertTrue(HealthAccessService::can($owner, 'ipd.view', $company));
    }

    public function test_a_trial_evaluates_every_module(): void
    {
        DB::table('pricing_plans')->insert([
            'id' => 3, 'name' => 'Trial', 'product_type' => 'health', 'is_trial' => true,
            'price' => 0, 'health_modules' => json_encode(['opd', 'accounts']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->where('company_id', $this->healthCompanyId)
            ->update(['pricing_plan_id' => 3]);

        $this->assertSame(
            HealthModuleService::MODULES,
            HealthModuleService::planModules($this->healthCompany())
        );
    }

    // ═══════════════════ 2. ROLE SEPARATION ═══════════════════

    public function test_each_role_holds_only_what_its_job_needs(): void
    {
        $this->upgradeToHospital();
        // Everything the hospital package sells is switched on for this test.
        HealthModuleService::setForCompany($this->healthCompany(), HealthModuleService::MODULES);
        $company = $this->healthCompany();

        $expectations = [
            // role => [must hold, must NOT hold]
            'health_receptionist' => [['patients.manage', 'appointments.manage'], ['clinical.view', 'accounts.manage']],
            'health_doctor'       => [['clinical.write', 'lab.view'], ['pharmacy.dispense', 'billing.charge']],
            'health_nurse'        => [['nursing.record', 'ipd.manage'], ['clinical.write', 'billing.view']],
            'health_pharmacist'   => [['pharmacy.dispense'], ['clinical.write', 'lab.result']],
            'health_lab_tech'     => [['lab.collect', 'lab.result'], ['clinical.view', 'pharmacy.dispense']],
            'health_accountant'   => [
                ['accounts.manage', 'billing.charge', 'hr.payroll.view'],
                ['patients.view', 'clinical.view', 'hr.manage', 'hr.attendance.correct', 'hr.attendance.approve'],
            ],
            'health_cashier'      => [['billing.charge'], ['accounts.manage', 'clinical.view']],
            'health_hr'           => [
                ['hr.manage', 'hr.attendance.view', 'hr.attendance.correct', 'hr.attendance.approve', 'hr.leave.approve', 'hr.payroll.view'],
                ['patients.view', 'billing.view'],
            ],
            'health_admin'        => [['staff.manage', 'settings.manage'], ['clinical.write', 'lab.result']],
        ];

        foreach ($expectations as $role => [$held, $denied]) {
            $user = $this->makeStaff($role);
            foreach ($held as $capability) {
                $this->assertTrue(
                    HealthAccessService::can($user, $capability, $company),
                    "{$role} should hold {$capability}"
                );
            }
            foreach ($denied as $capability) {
                $this->assertFalse(
                    HealthAccessService::can($user, $capability, $company),
                    "{$role} must NOT hold {$capability}"
                );
            }
        }
    }

    public function test_the_auditor_can_never_write_however_the_permission_was_granted(): void
    {
        $this->upgradeToHospital();
        HealthModuleService::setForCompany($this->healthCompany(), HealthModuleService::MODULES);
        $company = $this->healthCompany();

        $auditor = $this->makeStaff('health_auditor');

        $this->assertTrue(HealthAccessService::can($auditor, 'accounts.view', $company));
        $this->assertTrue(HealthAccessService::can($auditor, 'audit.view', $company));

        // A tampered stored set must not turn a read-only role into a writer.
        $auditor->health_permissions = json_encode(['accounts.manage', 'billing.charge', 'clinical.write']);
        $auditor->save();
        $this->forgetHealthCaches();

        $this->assertFalse(HealthAccessService::can($auditor, 'accounts.manage', $company));
        $this->assertFalse(HealthAccessService::can($auditor, 'billing.charge', $company));
        $this->assertFalse(HealthAccessService::can($auditor, 'clinical.write', $company));

        // Reading attendance is fine for an audit; deciding on it is not.
        $this->assertTrue(HealthAccessService::can($auditor, 'hr.attendance.view', $company));
        $auditor->health_permissions = json_encode([
            'hr.attendance.correct', 'hr.attendance.approve', 'hr.leave.approve',
        ]);
        $auditor->save();
        $this->forgetHealthCaches();

        $this->assertFalse(HealthAccessService::can($auditor, 'hr.attendance.correct', $company));
        $this->assertFalse(HealthAccessService::can($auditor, 'hr.attendance.approve', $company));
        $this->assertFalse(HealthAccessService::can($auditor, 'hr.leave.approve', $company));

        // Delegation refuses the auditor outright.
        $this->assertNull(HealthAccessService::setCustomSet($auditor, ['accounts.manage'], $company));
    }

    public function test_owner_delegation_can_widen_a_role_but_never_past_the_enabled_modules(): void
    {
        $company = $this->healthCompany(); // Clinic: opd + accounts only.
        $cashier = $this->makeStaff('health_cashier');

        $this->assertFalse(HealthAccessService::can($cashier, 'accounts.manage', $company));

        $stored = HealthAccessService::setCustomSet(
            $cashier,
            ['billing.view', 'billing.charge', 'accounts.manage', 'ipd.manage'],
            $company
        );
        $this->forgetHealthCaches();
        $cashier->refresh();

        // accounts.manage granted (accounts is on); ipd.manage dropped (off).
        $this->assertContains('accounts.manage', $stored);
        $this->assertNotContains('ipd.manage', $stored);
        $this->assertTrue(HealthAccessService::can($cashier, 'accounts.manage', $company));
        $this->assertFalse(HealthAccessService::can($cashier, 'ipd.manage', $company));
    }

    public function test_owner_only_capabilities_can_never_be_delegated(): void
    {
        $company = $this->healthCompany();
        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();
        $admin = $this->makeStaff('health_admin');

        $this->assertTrue(HealthAccessService::can($owner, 'settings.manage.modules', $company));
        $this->assertFalse(HealthAccessService::can($admin, 'settings.manage.modules', $company));

        // The delegation list itself never offers them.
        $delegatable = HealthAccessService::delegatableCapabilities($company);
        foreach (HealthAccessService::OWNER_ONLY as $capability) {
            $this->assertNotContains($capability, $delegatable);
        }

        // And a tampered set cannot smuggle one in.
        HealthAccessService::setCustomSet($admin, ['staff.delegate', 'settings.manage.modules'], $company);
        $this->forgetHealthCaches();
        $admin->refresh();
        $this->assertFalse(HealthAccessService::can($admin, 'settings.manage.modules', $company));
        $this->assertFalse(HealthAccessService::can($admin, 'staff.delegate', $company));
    }

    public function test_a_company_admin_is_always_the_owner_even_without_the_column(): void
    {
        $user = User::where('email', 'owner@shifa.test')->firstOrFail();
        $user->health_role = null;
        $user->save();
        $this->forgetHealthCaches();

        // Schema drift must never lock the person who bought the product out.
        $this->assertSame(HealthAccessService::ROLE_OWNER, HealthAccessService::roleFor($user->refresh()));
        $this->assertTrue(HealthAccessService::isOwner($user));
    }

    public function test_a_healthcare_user_without_any_role_is_not_staff(): void
    {
        $stranger = User::create([
            'name' => 'No Role', 'email' => 'norole@shifa.test',
            'password' => Hash::make('Staff@12345'),
            'company_id' => $this->healthCompanyId, 'role' => 'user',
            'is_active' => true,
        ]);

        $this->assertNull(HealthAccessService::roleFor($stranger));
        $this->assertSame([], HealthAccessService::capabilitiesFor($stranger, $this->healthCompany()));
    }

    // ═══════════════════ 3. PANEL ISOLATION ═══════════════════

    public function test_a_pos_account_cannot_sign_into_the_healthcare_panel(): void
    {
        $response = $this->from(HealthPanel::LOGIN_PATH)->post('/health/login', [
            'login' => 'pos@shop.test',
            'password' => 'POS@12345',
        ]);

        $response->assertRedirect(HealthPanel::LOGIN_PATH);
        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth(HealthPanel::GUARD)->check());
        $this->assertFalse(auth('pos')->check());
    }

    public function test_a_healthcare_account_cannot_sign_into_the_pos_panel(): void
    {
        $response = $this->from('/pos/login')->post('/pos/login', [
            'login' => 'owner@shifa.test',
            'password' => 'Health@12345',
        ]);

        $response->assertSessionHasErrors();
        $this->assertFalse(auth('pos')->check());
        $this->assertFalse(auth(HealthPanel::GUARD)->check());
    }

    public function test_a_healthcare_owner_signs_into_the_healthcare_panel(): void
    {
        $response = $this->post('/health/login', [
            'login' => 'owner@shifa.test',
            'password' => 'Health@12345',
        ]);

        $response->assertRedirect();
        $this->assertTrue(auth(HealthPanel::GUARD)->check());
        $this->assertSame($this->healthCompanyId, (int) auth(HealthPanel::GUARD)->user()->company_id);
    }

    public function test_the_panel_refuses_a_session_whose_company_is_not_healthcare(): void
    {
        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();
        $this->actingAs($owner, HealthPanel::GUARD);

        // The company changes product line mid-session — the panel must close.
        Company::where('id', $this->healthCompanyId)->update(['product_type' => 'pos']);
        $this->forgetHealthCaches();

        $this->get('/health/dashboard')->assertRedirect(HealthPanel::LOGIN_PATH);
        $this->assertFalse(auth(HealthPanel::GUARD)->check());
    }

    public function test_an_inactive_member_is_logged_out_of_the_panel(): void
    {
        // A REAL sign-in, not actingAs: the session guard re-reads the account
        // from the database on every request, which is exactly the behaviour
        // being tested (actingAs would pin a stale in-memory user).
        $nurse = $this->makeStaff('health_nurse', ['email' => 'nurse.deactivated@shifa.test']);
        $this->post('/health/login', [
            'login' => 'nurse.deactivated@shifa.test',
            'password' => 'Staff@12345',
        ]);
        $this->assertTrue(auth(HealthPanel::GUARD)->check());

        User::where('id', $nurse->id)->update(['is_active' => false]);
        // Drop the resolved guard so the next request reloads the account from
        // the database, the way a separate HTTP request would.
        $this->app['auth']->forgetGuards();

        $this->get('/health/dashboard')->assertRedirect(HealthPanel::LOGIN_PATH);
        $this->assertFalse(auth(HealthPanel::GUARD)->check());
    }

    // ═══════════════════ 4. BRANCH + DEPARTMENT BOUNDARIES ═══════════════════

    public function test_a_posted_member_sees_only_their_own_department(): void
    {
        $branchId = DB::table('branches')->insertGetId([
            'company_id' => $this->healthCompanyId, 'name' => 'Main',
            'is_head_office' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $wardA = DB::table('health_departments')->insertGetId([
            'company_id' => $this->healthCompanyId, 'branch_id' => $branchId,
            'name' => 'Ward A', 'type' => 'ward', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $wardB = DB::table('health_departments')->insertGetId([
            'company_id' => $this->healthCompanyId, 'branch_id' => $branchId,
            'name' => 'Ward B', 'type' => 'ward', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $nurse = $this->makeStaff('health_nurse', ['health_department_id' => $wardA]);

        $ids = HealthScopeService::departmentIdsFor($nurse);
        $this->assertSame([$wardA], $ids);
        $this->assertTrue(HealthScopeService::canAccessDepartment($nurse, $wardA));
        $this->assertFalse(HealthScopeService::canAccessDepartment($nurse, $wardB));

        // An extra posting via the pivot widens it — and only by that one.
        DB::table('health_department_user')->insert([
            'health_department_id' => $wardB, 'user_id' => $nurse->id,
        ]);
        $this->forgetHealthCaches();

        $this->assertEqualsCanonicalizing([$wardA, $wardB], HealthScopeService::departmentIdsFor($nurse));
    }

    public function test_owner_and_administrator_are_not_department_scoped(): void
    {
        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();
        $admin = $this->makeStaff('health_admin');

        // NULL means "no restriction" — deliberately different from [].
        $this->assertNull(HealthScopeService::departmentIdsFor($owner));
        $this->assertNull(HealthScopeService::branchIdsFor($owner));
        $this->assertNull(HealthScopeService::departmentIdsFor($admin));
    }

    public function test_a_member_is_limited_to_the_branches_they_are_attached_to(): void
    {
        $main = DB::table('branches')->insertGetId([
            'company_id' => $this->healthCompanyId, 'name' => 'Main',
            'is_head_office' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $second = DB::table('branches')->insertGetId([
            'company_id' => $this->healthCompanyId, 'name' => 'Second',
            'is_head_office' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $doctor = $this->makeStaff('health_doctor');
        DB::table('branch_user')->insert(['branch_id' => $second, 'user_id' => $doctor->id]);
        $this->forgetHealthCaches();

        $this->assertSame([$second], HealthScopeService::branchIdsFor($doctor));
        $this->assertTrue(HealthScopeService::canAccessBranch($doctor, $second));
        $this->assertFalse(HealthScopeService::canAccessBranch($doctor, $main));
    }

    public function test_departments_stay_inside_their_company(): void
    {
        DB::table('health_departments')->insert([
            'company_id' => $this->healthCompanyId, 'name' => 'OPD',
            'type' => 'clinical', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('health_departments')->insert([
            'company_id' => $this->posCompanyId + 500, 'name' => 'Someone Else',
            'type' => 'clinical', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->instance('currentCompanyId', $this->healthCompanyId);

        $names = HealthDepartment::pluck('name')->all();
        $this->assertSame(['OPD'], $names);
    }

    // ═══════════════════ 5. PATH-LEVEL ENFORCEMENT ═══════════════════

    public function test_the_capability_a_screen_needs_is_derived_from_its_path(): void
    {
        $cases = [
            'health/settings/modules' => 'settings.manage.modules',
            'health/settings' => 'settings.manage',
            'health/departments' => 'departments.manage',
            'health/team/9/permissions' => 'staff.manage',
            'health/pharmacy/dispense' => 'pharmacy.view',
            'health/accounts/ledger' => 'accounts.view',
            'health/dashboard' => null,
        ];

        foreach ($cases as $path => $expected) {
            $this->assertSame($expected, HealthAccessService::capabilityForPath($path), $path);
        }
    }

    public function test_a_role_without_the_capability_is_refused_at_the_screen(): void
    {
        $cashier = $this->makeStaff('health_cashier');
        $this->actingAs($cashier, HealthPanel::GUARD);

        // The path map alone refuses this, not the route middleware.
        $this->get('/health/team')->assertStatus(403);
        $this->get('/health/settings')->assertStatus(403);
        // The dashboard needs no capability.
        $this->get('/health/dashboard')->assertStatus(200);
    }

    public function test_the_attendance_export_hands_no_salary_to_an_attendance_only_manager(): void
    {
        // Hospital package, so the HR module is actually sold, plus the real
        // HR schema — this export reads it.
        $this->upgradeToHospital();
        DB::table('companies')->where('id', $this->healthCompanyId)
            ->update(['health_modules' => json_encode(HealthModuleService::MODULES)]);
        (require base_path('database/migrations/2026_10_09_100000_create_healthcare_hr_attendance.php'))->up();
        $this->forgetHealthCaches();

        $company = $this->healthCompany();

        // Somebody who runs the attendance desk and nothing else: the roster
        // and the punches are theirs, the salaries are not.
        $manager = $this->makeStaff('health_hr');
        HealthAccessService::setCustomSet($manager, ['hr.view', 'hr.attendance.view'], $company);
        $this->forgetHealthCaches();
        $manager->refresh();

        $this->assertTrue(HealthAccessService::can($manager, 'hr.attendance.view', $company));
        $this->assertFalse(HealthAccessService::can($manager, 'hr.payroll.view', $company));

        $this->actingAs($manager, HealthPanel::GUARD);

        // The payroll screen and its export are closed outright.
        $this->get('/health/hr/payroll')->assertStatus(403);
        $this->get('/health/hr/payroll/export')->assertStatus(403);

        // The attendance export opens — WITHOUT the money columns. Absent, not
        // blanked: nothing about anybody's pay travels on this file.
        $response = $this->get('/health/hr/attendance/reports/export?year=2026&month=3');
        $response->assertStatus(200);
        $csv = $response->streamedContent();

        foreach (['Basic Salary', 'Basic Earned', 'Overtime Pay', 'Gross'] as $column) {
            $this->assertStringNotContainsString($column, $csv, $column . ' must not reach an attendance-only manager');
        }
        $this->assertStringContainsString('Payable Days', $csv);
        $this->assertStringContainsString('Overtime Hours', $csv);

        // And for whoever does hold the payroll permission, the same export
        // still carries them.
        HealthAccessService::setCustomSet($manager, null, $company);
        $this->forgetHealthCaches();
        $manager->refresh();

        $this->actingAs($manager, HealthPanel::GUARD);
        $paid = $this->get('/health/hr/attendance/reports/export?year=2026&month=3');
        $paid->assertStatus(200);
        $this->assertStringContainsString('Basic Salary', $paid->streamedContent());
    }

    /**
     * The geofence has to MEASURE something.
     *
     * "Location required" used to mean only that the phone sent a coordinate,
     * and a phone will happily send any coordinate at all. A staff member at
     * home could clock in for a shift they never came to. Distance from the
     * configured site is now checked on the server, and a site nobody has
     * configured REFUSES the punch instead of waving it through.
     */
    public function test_a_location_check_in_refuses_forged_coordinates(): void
    {
        $this->prepareHrModule();
        $nurse = $this->makeStaff('health_nurse');

        $policy = HealthHrService::policy($this->healthCompanyId);
        $policy->forceFill([
            'geo_required'  => true,
            'geo_radius_m'  => 200,
            'geo_latitude'  => 33.6844,   // the hospital
            'geo_longitude' => 73.0479,
        ])->save();
        HealthHrService::forget();

        $this->actingAs($nurse, HealthPanel::GUARD);

        // Somebody 1,100 km away, sending perfectly valid numbers.
        $far = $this->postJson('/health/my/punch', [
            'channel'   => 'web',
            'latitude'  => 24.8607,
            'longitude' => 67.0011,
        ]);
        $far->assertStatus(422);
        $this->assertSame(0, HealthAttendancePunch::withoutGlobalScopes()
            ->where('user_id', $nurse->id)->count(), 'a forged punch must not be recorded');

        // The same person standing at the door.
        $near = $this->postJson('/health/my/punch', [
            'channel'   => 'web',
            'latitude'  => 33.6846,
            'longitude' => 73.0481,
        ]);
        $near->assertStatus(200);
        $this->assertSame(1, HealthAttendancePunch::withoutGlobalScopes()
            ->where('user_id', $nurse->id)->count());
    }

    public function test_a_location_check_in_refuses_when_no_site_has_been_configured(): void
    {
        $this->prepareHrModule();
        $nurse = $this->makeStaff('health_nurse');

        // The switch is on, but nobody ever set a centre for it.
        $policy = HealthHrService::policy($this->healthCompanyId);
        $policy->forceFill(['geo_required' => true, 'geo_radius_m' => 200])->save();
        HealthHrService::forget();

        $this->actingAs($nurse, HealthPanel::GUARD);

        $response = $this->postJson('/health/my/punch', [
            'channel'   => 'web',
            'latitude'  => 33.6844,
            'longitude' => 73.0479,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, HealthAttendancePunch::withoutGlobalScopes()
            ->where('user_id', $nurse->id)->count());
    }

    /**
     * Cross-branch OFF has to actually stop something.
     *
     * The calculation can only ever FLAG a cross-branch day after it happened.
     * The switch is honoured where a decision is still open: a person tapping
     * check-in at a branch that is not their posting.
     */
    public function test_cross_branch_off_refuses_a_punch_at_another_branch(): void
    {
        $this->prepareHrModule();

        $mainId = DB::table('branches')->insertGetId([
            'company_id' => $this->healthCompanyId, 'name' => 'Main',
            'is_head_office' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $annexId = DB::table('branches')->insertGetId([
            'company_id' => $this->healthCompanyId, 'name' => 'Annex',
            'is_head_office' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $nurse = $this->makeStaff('health_nurse');

        // Posted to the Annex, standing in Main (which is where a plain
        // employee's branch context lands).
        $profile = HealthHrService::profile($this->healthCompanyId, (int) $nurse->id);
        $profile->forceFill(['branch_id' => $annexId])->save();

        $policy = HealthHrService::policy($this->healthCompanyId);
        $policy->forceFill(['cross_branch_allowed' => false])->save();
        HealthHrService::forget();

        $this->actingAs($nurse, HealthPanel::GUARD);
        $this->postJson('/health/my/punch', ['channel' => 'web'])->assertStatus(422);
        $this->assertSame(0, HealthAttendancePunch::withoutGlobalScopes()
            ->where('user_id', $nurse->id)->count());

        // With the switch back on, the same punch is welcome.
        $policy = HealthHrService::policy($this->healthCompanyId);
        $policy->forceFill(['cross_branch_allowed' => true])->save();
        HealthHrService::forget();

        $this->postJson('/health/my/punch', ['channel' => 'web'])->assertStatus(200);
        $this->assertSame(1, HealthAttendancePunch::withoutGlobalScopes()
            ->where('user_id', $nurse->id)->count());
        $this->assertSame($mainId, (int) HealthAttendancePunch::withoutGlobalScopes()
            ->where('user_id', $nurse->id)->value('branch_id'));
    }

    /**
     * A nurse can withdraw her own leave request.
     *
     * The cancel button used to point at the HR desk's URL, which needs
     * hr.view — a permission ordinary staff do not hold, so the button 403'd
     * for exactly the people it was drawn for.
     */
    public function test_staff_can_withdraw_their_own_leave_without_hr_access(): void
    {
        $this->prepareHrModule();
        HealthHrService::ensureLeaveTypes($this->healthCompanyId);
        $typeId = (int) DB::table('health_leave_types')
            ->where('company_id', $this->healthCompanyId)->value('id');

        $nurse = $this->makeStaff('health_nurse');
        $this->actingAs($nurse, HealthPanel::GUARD);

        // The HR leave desk is not hers.
        $this->get('/health/hr/leave')->assertStatus(403);

        $this->post('/health/my/leave', [
            'health_leave_type_id' => $typeId,
            'start_date'           => now()->addDays(3)->toDateString(),
            'end_date'             => now()->addDays(4)->toDateString(),
            'reason'               => 'Family matter',
        ]);

        $leaveId = (int) DB::table('health_leave_requests')
            ->where('user_id', $nurse->id)->value('id');
        $this->assertNotSame(0, $leaveId, 'the request must have been created');

        $this->post('/health/my/leave/' . $leaveId . '/cancel');

        $this->assertSame('cancelled', DB::table('health_leave_requests')
            ->where('id', $leaveId)->value('status'));
    }

    /** The switch and the centre it measures from live on the same screen. */
    public function test_the_policy_screen_offers_a_site_location(): void
    {
        $this->prepareHrModule();

        DB::table('branches')->insert([
            'company_id' => $this->healthCompanyId, 'name' => 'Annex',
            'is_head_office' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();
        $this->actingAs($owner, HealthPanel::GUARD);

        $response = $this->get('/health/hr/policy');
        $response->assertStatus(200);
        $response->assertSee('name="geo_latitude"', false);
        $response->assertSee('name="geo_longitude"', false);
    }

    /**
     * Bulk publish is how a roster is really built, so the pattern it writes
     * has to pass the SAME checks as a single cell.
     *
     * Roster rows carry no foreign keys, so an id nobody checked is stored
     * happily and only surfaces later as a duty that resolves to nothing, or
     * as attendance attributed to a branch the person was never posted to.
     */
    public function test_bulk_roster_refuses_a_foreign_branch_a_foreign_shift_and_a_blocked_cross_branch_run(): void
    {
        $this->prepareHrModule();

        $annexId = DB::table('branches')->insertGetId([
            'company_id' => $this->healthCompanyId, 'name' => 'Annex',
            'is_head_office' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherCompanyBranchId = DB::table('branches')->insertGetId([
            'company_id' => $this->posCompanyId, 'name' => 'Somebody Else',
            'is_head_office' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $nurse = $this->makeStaff('health_nurse');
        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();

        // A shift belonging to another company entirely.
        $foreignShiftId = DB::table('health_shifts')->insertGetId([
            'company_id' => $this->posCompanyId, 'name' => 'Not ours',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'crosses_midnight' => false, 'break_minutes' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ourShiftId = DB::table('health_shifts')->insertGetId([
            'company_id' => $this->healthCompanyId, 'name' => 'Morning',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'crosses_midnight' => false, 'break_minutes' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($owner, HealthPanel::GUARD);

        $base = [
            'user_ids'   => [$nurse->id],
            'from'       => '2026-04-01',
            'to'         => '2026-04-03',
            'entry_type' => 'shift',
        ];

        // 1. A branch that is not this company's.
        $this->post('/health/hr/roster/bulk', $base + [
            'health_shift_id' => $ourShiftId,
            'branch_id'       => $otherCompanyBranchId,
        ])->assertSessionHas('error');

        // 2. A shift that is not this company's.
        $this->post('/health/hr/roster/bulk', $base + [
            'health_shift_id' => $foreignShiftId,
        ])->assertSessionHas('error');

        $this->assertSame(0, DB::table('health_roster_entries')->count(),
            'nothing may be written by a refused bulk run');

        // 3. Cross-branch off, and the pattern points somewhere that is not
        //    this nurse's own posting.
        $profile = HealthHrService::profile($this->healthCompanyId, (int) $nurse->id);
        $profile->forceFill(['branch_id' => $annexId])->save();

        $policy = HealthHrService::policy($this->healthCompanyId);
        $policy->forceFill(['cross_branch_allowed' => false])->save();
        HealthHrService::forget();

        $mainId = DB::table('branches')->insertGetId([
            'company_id' => $this->healthCompanyId, 'name' => 'Main',
            'is_head_office' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post('/health/hr/roster/bulk', $base + [
            'health_shift_id' => $ourShiftId,
            'branch_id'       => $mainId,
        ])->assertSessionHas('error');

        $this->assertSame(0, DB::table('health_roster_entries')->count(),
            'a cross-branch bulk run must write nothing at all');

        // The same run at her OWN branch goes through.
        $this->post('/health/hr/roster/bulk', $base + [
            'health_shift_id' => $ourShiftId,
            'branch_id'       => $annexId,
        ]);

        $this->assertSame(3, DB::table('health_roster_entries')->count());
    }

    /** Hospital package + every module on + the real HR schema. */
    private function prepareHrModule(): void
    {
        $this->upgradeToHospital();
        DB::table('companies')->where('id', $this->healthCompanyId)
            ->update(['health_modules' => json_encode(HealthModuleService::MODULES)]);
        (require base_path('database/migrations/2026_10_09_100000_create_healthcare_hr_attendance.php'))->up();
        (require base_path('database/migrations/2026_10_16_100000_add_health_site_geofence.php'))->up();
        $this->forgetHealthCaches();
        HealthHrService::forget();
    }

    public function test_only_the_owner_reaches_the_module_switchboard(): void
    {
        $admin = $this->makeStaff('health_admin');
        $this->actingAs($admin, HealthPanel::GUARD);
        $this->get('/health/settings/modules')->assertStatus(403);

        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();
        $this->actingAs($owner, HealthPanel::GUARD);
        $this->get('/health/settings/modules')->assertStatus(200);
    }

    public function test_the_public_healthcare_page_is_reachable_signed_out(): void
    {
        $this->get('/healthcare')->assertStatus(200);
    }

    /**
     * /health is the panel's own root. It used to be answered by an
     * infrastructure diagnostic that printed the database host, drivers and a
     * slice of the Replit DB file to anyone who asked — so this pins BOTH that
     * the prefix belongs to the panel and that the diagnostic is not public.
     */
    public function test_the_panel_owns_its_own_root_and_no_diagnostic_answers_it(): void
    {
        $response = $this->get('/health');

        $response->assertRedirect(HealthPanel::LOGIN_PATH);
        $this->assertStringNotContainsStringIgnoringCase('db_host', $response->getContent());

        // Signed in, the root lands inside the panel.
        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();
        $this->actingAs($owner, HealthPanel::GUARD);
        $this->get('/health')->assertRedirect('/health/dashboard');
    }

    public function test_the_infrastructure_diagnostic_is_not_readable_signed_out(): void
    {
        $response = $this->get('/__diag/infrastructure');

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsStringIgnoringCase('db_host', $response->getContent());
    }

    // ═══════════════════ 6. PACKAGE STAFF QUOTA ═══════════════════

    /**
     * The healthcare packages advertise a staff count, so the panel has to
     * hold that line on the server. Hiding the form is not enough — the POST
     * arrives anyway.
     */
    public function test_the_package_staff_quota_is_enforced_when_adding_a_member(): void
    {
        // Clinic package allows 2 active accounts; the owner is already one.
        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();
        $this->actingAs($owner, HealthPanel::GUARD);

        $this->post('/health/team', [
            'name' => 'Reception One',
            'email' => 'rec1@shifa.test',
            'password' => 'Staff@12345',
            'password_confirmation' => 'Staff@12345',
            'health_role' => 'health_receptionist',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'rec1@shifa.test']);

        // Second one is over the package.
        $this->post('/health/team', [
            'name' => 'Reception Two',
            'email' => 'rec2@shifa.test',
            'password' => 'Staff@12345',
            'password_confirmation' => 'Staff@12345',
            'health_role' => 'health_receptionist',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'rec2@shifa.test']);
        $this->assertSame(2, User::where('company_id', $this->healthCompanyId)->where('is_active', true)->count());
    }

    public function test_reactivating_a_member_cannot_slip_past_the_package_quota(): void
    {
        $parked = $this->makeStaff('health_receptionist', [
            'email' => 'parked@shifa.test',
            'is_active' => false,
        ]);
        $active = $this->makeStaff('health_nurse', ['email' => 'active@shifa.test']);

        // Owner + one active nurse = the Clinic package's 2 accounts.
        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();
        $this->actingAs($owner, HealthPanel::GUARD);

        $this->post('/health/team/' . $parked->id . '/toggle-active');
        $this->assertFalse((bool) $parked->refresh()->is_active);

        // Free a seat and the same press works.
        $this->post('/health/team/' . $active->id . '/toggle-active');
        $this->assertFalse((bool) $active->refresh()->is_active);

        $this->post('/health/team/' . $parked->id . '/toggle-active');
        $this->assertTrue((bool) $parked->refresh()->is_active);
    }

    public function test_the_hospital_package_does_not_cap_staff(): void
    {
        $this->upgradeToHospital();

        $owner = User::where('email', 'owner@shifa.test')->firstOrFail();
        $this->actingAs($owner, HealthPanel::GUARD);

        foreach (['a', 'b', 'c', 'd'] as $suffix) {
            $this->post('/health/team', [
                'name' => 'Staff ' . $suffix,
                'email' => 'staff' . $suffix . '@shifa.test',
                'password' => 'Staff@12345',
                'password_confirmation' => 'Staff@12345',
                'health_role' => 'health_nurse',
            ]);
        }

        $this->assertSame(5, User::where('company_id', $this->healthCompanyId)->where('is_active', true)->count());
    }
}
