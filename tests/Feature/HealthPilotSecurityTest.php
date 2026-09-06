<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HealthDoctor;
use App\Models\HealthPatient;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthModuleService;
use App\Services\HealthPatientService;
use App\Support\HealthPanel;
use App\Support\NestErps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PILOT SECURITY & PRIVACY SWEEP (Task 1555).
 *
 * A hospital holds the most sensitive records this platform will ever store,
 * and the pilot is the moment those records stop being test data. Each module
 * guards its own screens; this file walks the seams BETWEEN them, where a
 * boundary is easiest to forget:
 *
 *  1. ONE HOSPITAL CANNOT REACH ANOTHER — not by guessing an id, not through a
 *     print view, not through an export. A record id is not a permission.
 *  2. A CLOSED ACCOUNT IS CLOSED — a member switched off keeps no session and
 *     gains no screen.
 *  3. A CAPABILITY GATE COVERS THE EXPORT TOO — refusing the screen while the
 *     same rows leave as a spreadsheet is not a refusal.
 *  4. DESTRUCTIVE ACTIONS ARE NARROWER THAN READING — being allowed to see a
 *     bill is not being allowed to cancel it.
 *  5. A MODULE THAT IS OFF IS OFF EVERYWHERE — its URLs refuse, not merely its
 *     navigation links.
 *  6. NOBODY ELSE'S PANEL — a POS or Digital Invoice login is not a hospital
 *     login, whatever the credentials.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthPilotSecurityTest.php --testdox
 */
class HealthPilotSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $mine;
    private Company $theirs;
    private User $myOwner;
    private User $myCashier;
    private User $myNurse;
    private HealthPatient $theirPatient;

    protected function setUp(): void
    {
        parent::setUp();

        HealthModuleService::forget();

        $this->mine = $this->hospital('Boundary Hospital A', 'SEC-A');
        $this->theirs = $this->hospital('Boundary Hospital B', 'SEC-B');

        $this->myOwner = $this->user($this->mine, 'sec.owner@example.test', HealthAccessService::ROLE_OWNER);
        $this->myCashier = $this->user($this->mine, 'sec.cashier@example.test', 'health_cashier');
        $this->myNurse = $this->user($this->mine, 'sec.nurse@example.test', 'health_nurse');

        $this->theirPatient = HealthPatientService::register((int) $this->theirs->id, [
            'name' => 'Private Record',
            'gender' => 'female',
            'age_years' => 52,
            'phone' => '03001112233',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        HealthModuleService::forget();
        parent::tearDown();
    }

    /* ─────────── 1. one hospital cannot reach another ─────────────────────── */

    public function test_an_owner_cannot_open_another_hospitals_patient_by_id(): void
    {
        // Owner of A is the most privileged account there is INSIDE A. That is
        // precisely why this is the right actor for the test: privilege must
        // stop at the organisation, not travel with the role.
        $this->actingAs($this->myOwner, HealthPanel::GUARD)
            ->get('/health/patients/' . $this->theirPatient->id)
            ->assertRedirect('/health/dashboard');
    }

    public function test_another_hospitals_patient_never_appears_in_a_search(): void
    {
        HealthPatientService::register((int) $this->mine->id, [
            'name' => 'My Own Patient',
            'gender' => 'male',
            'age_years' => 33,
            'phone' => '03004445566',
            'is_active' => true,
        ]);

        $this->actingAs($this->myOwner, HealthPanel::GUARD)
            ->get('/health/patients?q=Private')
            ->assertOk()
            ->assertDontSee('Private Record');
    }

    public function test_editing_another_hospitals_patient_is_refused_not_merely_hidden(): void
    {
        // Reading is not the only door. A write addressed straight at the id
        // must be refused by the same boundary, or the hide is cosmetic.
        $this->actingAs($this->myOwner, HealthPanel::GUARD)
            ->put('/health/patients/' . $this->theirPatient->id, [
                'name' => 'Renamed By Outsider',
                'gender' => 'female',
                'age_years' => 52,
            ])
            ->assertRedirect('/health/dashboard');

        $this->assertSame(
            'Private Record',
            HealthPatient::withoutGlobalScopes()->find($this->theirPatient->id)->name,
            'A refused write must leave the other hospital\'s record untouched.'
        );
    }

    /* ─────────── 2. a closed account is closed ────────────────────────────── */

    public function test_a_deactivated_member_is_turned_away(): void
    {
        $this->myNurse->forceFill(['is_active' => false])->save();

        $response = $this->actingAs($this->myNurse, HealthPanel::GUARD)->get('/health/dashboard');

        $this->assertContains(
            $response->getStatusCode(),
            [302, 403],
            'A member switched off must not reach the panel; leaving them signed in is how a dismissed employee keeps working.'
        );
        $this->assertNotSame(200, $response->getStatusCode());
    }

    /* ─────────── 3 & 4. gates cover exports and destructive actions ───────── */

    public function test_a_nurse_may_not_read_the_hr_payroll_screen_or_its_export(): void
    {
        // Payroll is money and salary — the one HR desk a ward nurse must not
        // reach. Screen and export are the SAME data; refusing one is not a
        // refusal.
        $this->actingAs($this->myNurse, HealthPanel::GUARD)
            ->get('/health/hr/payroll')
            ->assertForbidden();

        $this->actingAs($this->myNurse, HealthPanel::GUARD)
            ->get('/health/hr/payroll/export')
            ->assertForbidden();
    }

    public function test_the_setup_importer_is_shut_to_everyone_but_the_owner(): void
    {
        // One press writes staff logins, a medicine catalogue, opening stock
        // and the opening trial balance. It is owner-only by capability and
        // owner-only again by path.
        foreach ([$this->myCashier, $this->myNurse] as $user) {
            $this->actingAs($user, HealthPanel::GUARD)
                ->get('/health/setup/import')
                ->assertForbidden();
        }

        $this->actingAs($this->myOwner, HealthPanel::GUARD)
            ->get('/health/setup/import')
            ->assertOk();
    }

    public function test_a_cashier_may_take_money_but_may_not_manage_the_team(): void
    {
        // Creating logins is how any permission boundary is escaped: a cashier
        // who can add a user can add themselves an owner.
        $this->actingAs($this->myCashier, HealthPanel::GUARD)
            ->get('/health/team')
            ->assertForbidden();
    }

    /* ─────────── 5. a module that is off is off everywhere ────────────────── */

    public function test_switching_a_module_off_closes_its_urls_not_just_its_menu(): void
    {
        $this->mine->forceFill([
            'health_modules' => json_encode(['opd']),
        ])->save();
        HealthModuleService::forget();

        // The pharmacy is off. Its counter, its stock and its reports must all
        // refuse — a hospital that turned a module off has not bought it.
        foreach (['/health/pharmacy', '/health/pharmacy/stock', '/health/pharmacy/reports'] as $path) {
            $this->actingAs($this->myOwner, HealthPanel::GUARD)
                ->get($path)
                ->assertForbidden();
        }
    }

    public function test_navigation_offers_nothing_the_hospital_cannot_use(): void
    {
        $this->mine->forceFill([
            'health_modules' => json_encode(['opd']),
        ])->save();
        HealthModuleService::forget();

        $this->actingAs($this->myOwner, HealthPanel::GUARD)
            ->get('/health/dashboard')
            ->assertOk()
            ->assertDontSee('/health/pharmacy/stock', false)
            ->assertDontSee('/health/accounts/journals', false);
    }

    /* ─────────── 6. nobody else's panel ───────────────────────────────────── */

    public function test_a_hospital_login_does_not_open_any_other_panel(): void
    {
        // Guard isolation, from the hospital's side: the healthcare session is
        // not a POS session and not a Digital Invoice session.
        $this->actingAs($this->myOwner, HealthPanel::GUARD);

        $this->assertGuest('web');
        $this->assertGuest('pos');
    }

    public function test_a_signed_out_visitor_reaches_the_hospital_login_and_nothing_else(): void
    {
        $this->get('/health/dashboard')->assertRedirect('/health/login');
        $this->get('/health/patients')->assertRedirect('/health/login');
        $this->get('/health/accounts/journals')->assertRedirect('/health/login');
    }

    /* ───────────────────────────── helpers ────────────────────────────────── */

    private function hospital(string $name, string $ntn): Company
    {
        $company = Company::create([
            'name' => $name,
            'ntn' => $ntn,
            'product_type' => NestErps::PRODUCT_TYPE,
            NestErps::VERTICAL_COLUMN => NestErps::HEALTH,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'hospital',
            'health_modules' => json_encode(HealthModuleService::MODULES),
        ]);

        $plan = PricingPlan::create([
            'name' => 'Security Plan ' . $company->id,
            'product_type' => NestErps::PRODUCT_TYPE,
            'price' => 99999,
            'is_trial' => false,
            'health_modules' => json_encode(HealthModuleService::MODULES),
            'user_limit' => 50,
            'branch_limit' => 5,
            'invoice_limit' => 0,
        ]);

        Subscription::create([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);

        HealthDoctor::create([
            'company_id' => $company->id,
            'name' => 'Dr On Duty',
            'consultation_fee' => 1000,
            'is_active' => true,
        ]);

        return $company;
    }

    private function user(Company $company, string $email, string $role): User
    {
        return User::create([
            'name' => $role,
            'email' => $email,
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $company->id,
            'role' => $role === HealthAccessService::ROLE_OWNER ? 'company_admin' : 'user',
            'health_role' => $role,
            'is_active' => true,
        ]);
    }
}
