<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HealthCharge;
use App\Models\HealthPatient;
use App\Models\HealthPayment;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthChartOfAccountsService as Chart;
use App\Services\HealthFiscalPeriodService as Periods;
use App\Services\HealthHrService;
use App\Services\HealthModuleService;
use App\Services\HealthPatientService;
use App\Support\HealthPanel;
use App\Support\NestErps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Nest ERPS / Healthcare — subscription lock on money writes.
 *
 * The healthcare routes carry no plan.limit middleware, so an APPROVED hospital
 * whose plan had expired (or whose trial had ended) never met
 * SubscriptionAccessService::hasAccess() and could keep posting charges,
 * taking deposits and ringing pharmacy sales. HealthSubscriptionLock now sits
 * on the money-writing groups only:
 *
 *   - reads keep working (staff still open the patient file and the bill),
 *   - money writes are refused with the same localized lock reason the DI /
 *     POS panels show,
 *   - an active plan and a lifetime override both pass.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthSubscriptionLockTest.php --testdox
 */
class HealthSubscriptionLockTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private PricingPlan $plan;
    private HealthPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        HealthModuleService::forget();
        HealthHrService::forget();
        Chart::flush();

        $this->company = Company::create([
            'name' => 'Lock Test Hospital',
            'ntn' => 'LOCK-TEST-1',
            'product_type' => NestErps::PRODUCT_TYPE,
            NestErps::VERTICAL_COLUMN => NestErps::HEALTH,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'hospital',
            'health_modules' => json_encode(HealthModuleService::MODULES),
        ]);

        $this->plan = PricingPlan::create([
            'name' => 'Lock Test Plan ' . $this->company->id,
            'product_type' => NestErps::PRODUCT_TYPE,
            'price' => 99999,
            'is_trial' => false,
            'health_modules' => json_encode(HealthModuleService::MODULES),
            'user_limit' => 50,
            'branch_limit' => 5,
            'invoice_limit' => 0,
        ]);

        $this->owner = User::create([
            'name' => 'owner',
            'email' => 'lock.owner@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'company_admin',
            'health_role' => HealthAccessService::ROLE_OWNER,
            'is_active' => true,
        ]);

        Chart::seed($this->company->id, $this->owner);
        Periods::settings($this->company->id);

        $this->patient = HealthPatientService::register((int) $this->company->id, [
            'name' => 'Locked Patient',
            'gender' => 'female',
            'age_years' => 30,
            'phone' => '03001234567',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        HealthModuleService::forget();
        HealthHrService::forget();
        Chart::flush();
        parent::tearDown();
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function subscription(array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'company_id' => $this->company->id,
            'pricing_plan_id' => $this->plan->id,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'override_type' => 'none',
        ], $overrides));
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner, HealthPanel::GUARD);
    }

    private function chargePayload(): array
    {
        return [
            'category' => HealthCharge::CAT_SERVICE,
            'description' => 'Dressing',
            'unit_price' => 500,
            'quantity' => 1,
        ];
    }

    private function billingUrl(string $suffix = ''): string
    {
        return '/health/billing/patient/' . $this->patient->id . $suffix;
    }

    /* ─────────────────────────── locked ─────────────────────────── */

    public function test_locked_company_can_still_read_patients_and_billing(): void
    {
        $this->subscription(['end_date' => now()->subDay()->toDateString()]);

        $this->asOwner()->get('/health/patients')->assertOk();
        $this->asOwner()->get('/health/patients/' . $this->patient->id)->assertOk();
        $this->asOwner()->get($this->billingUrl())->assertOk();
    }

    public function test_locked_company_cannot_post_a_charge(): void
    {
        $this->subscription(['end_date' => now()->subDay()->toDateString()]);

        $response = $this->asOwner()
            ->from($this->billingUrl())
            ->post($this->billingUrl('/charges'), $this->chargePayload());

        $response->assertRedirect($this->billingUrl())
            ->assertSessionHas('error', __('pos.tl_reason_expired'));

        $this->assertSame(0, HealthCharge::where('company_id', $this->company->id)->count(),
            'A locked hospital must not gain a charge row.');
    }

    public function test_locked_company_cannot_take_a_deposit_or_pay(): void
    {
        $this->subscription(['end_date' => now()->subDay()->toDateString()]);

        $this->asOwner()
            ->from($this->billingUrl())
            ->post($this->billingUrl('/deposit'), ['amount' => 1000, 'method' => 'cash'])
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('error', __('pos.tl_reason_expired'));

        $this->assertSame(0, HealthPayment::where('company_id', $this->company->id)->count());

        // The lock runs BEFORE the controller — even a bill that does not exist
        // is refused for the plan, not for the id.
        $this->asOwner()
            ->from($this->billingUrl())
            ->post('/health/billing/bills/999999/pay', ['amount' => 100, 'method' => 'cash'])
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('error', __('pos.tl_reason_expired'));
    }

    public function test_locked_company_cannot_ring_a_pharmacy_sale_or_post_ipd_charges(): void
    {
        $this->subscription(['end_date' => now()->subDay()->toDateString()]);

        // XHR caller gets the JSON shape CheckPlanLimit uses.
        $this->asOwner()
            ->postJson('/health/pharmacy/counter', ['items' => []])
            ->assertStatus(403)
            ->assertJsonPath('error', __('pos.tl_reason_expired'))
            ->assertJsonPath('message', __('pos.tl_reason_expired'));

        $this->asOwner()
            ->postJson('/health/ipd/admissions/999999/charges', ['description' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error', __('pos.tl_reason_expired'));

        $this->asOwner()
            ->postJson('/health/ipd/admissions/999999/payments', ['amount' => 1, 'method' => 'cash'])
            ->assertStatus(403)
            ->assertJsonPath('error', __('pos.tl_reason_expired'));
    }

    public function test_no_referer_falls_back_to_the_health_dashboard(): void
    {
        $this->subscription(['end_date' => now()->subDay()->toDateString()]);

        $this->asOwner()
            ->post($this->billingUrl('/charges'), $this->chargePayload())
            ->assertRedirect('/health/dashboard')
            ->assertSessionHas('error', __('pos.tl_reason_expired'));
    }

    public function test_expired_trial_is_locked_too(): void
    {
        $trial = PricingPlan::create([
            'name' => 'Health Trial ' . $this->company->id,
            'product_type' => NestErps::PRODUCT_TYPE,
            'price' => 0,
            'is_trial' => true,
            'invoice_limit' => 0,
        ]);
        $this->subscription([
            'pricing_plan_id' => $trial->id,
            'end_date' => null,
            'trial_ends_at' => now()->subHour(),
        ]);

        $this->asOwner()
            ->from($this->billingUrl())
            ->post($this->billingUrl('/charges'), $this->chargePayload())
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('error', __('pos.tl_reason_trial_expired'));

        $this->assertSame(0, HealthCharge::where('company_id', $this->company->id)->count());
    }

    /* ─────────────────────────── allowed ────────────────────────── */

    public function test_active_company_can_post_a_charge(): void
    {
        $this->subscription();

        $this->asOwner()
            ->from($this->billingUrl())
            ->post($this->billingUrl('/charges'), $this->chargePayload())
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $this->assertSame(1, HealthCharge::where('company_id', $this->company->id)->count());
    }

    public function test_lifetime_override_unlocks_an_expired_plan(): void
    {
        $this->subscription([
            'end_date' => now()->subDay()->toDateString(),
            'override_type' => 'lifetime',
        ]);

        $this->asOwner()
            ->from($this->billingUrl())
            ->post($this->billingUrl('/charges'), $this->chargePayload())
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $this->assertSame(1, HealthCharge::where('company_id', $this->company->id)->count());
    }
}
