<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\HrOrganization;
use App\Models\PricingPlan;
use App\Models\ScopedProductEntitlement;
use App\Models\Subscription;
use App\Services\ScopedProductEntitlementService;
use App\Services\SubscriptionAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ScopedProductEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_grants_use_exact_scopes_and_leave_the_legacy_subscription_intact(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-29 09:00:00', 'Asia/Karachi'));
        $this->signInBillingAdmin();
        $company = $this->company('QA-ENT-1');
        $otherCompany = $this->company('QA-ENT-2');
        $workspace = HrOrganization::forceCreate(['id' => $company->id, 'name' => 'Matching ID workspace', 'status' => 'active']);
        $receiptTerm = $this->paidLegacyTerm($company);
        $legacyBefore = $receiptTerm->fresh()->getRawOriginal();
        $this->assertTrue(SubscriptionAccessService::hasAccess($company)['allowed']);
        $entitlements = app(ScopedProductEntitlementService::class);

        $start = CarbonImmutable::parse('2026-11-29 04:00:00 UTC');
        $end = $start->addDay();
        $this->assertFalse($entitlements->allows($workspace, 'hr', 'attendance'));
        $grant = $entitlements->grant($company, 'hr', 'attendance', 'agreement:qa-1', $start, $end);
        $this->assertTrue($entitlements->allows($company, 'hr', 'attendance'));
        $this->assertFalse($entitlements->allows($company, 'hr', 'payroll'));
        $this->assertFalse($entitlements->allows($company, 'distribution', 'attendance'));
        $this->assertFalse($entitlements->allows($otherCompany, 'hr', 'attendance'));
        $this->assertFalse($entitlements->allows($workspace, 'hr', 'attendance'));

        $entitlements->grant($workspace, 'hr', 'attendance', 'agreement:qa-1', $start, $end);
        $this->assertTrue($entitlements->allows($workspace, 'hr', 'attendance'));
        $entitlements->revoke($company, $grant->id);
        $this->assertFalse($entitlements->allows($company, 'hr', 'attendance'));
        $this->assertTrue($entitlements->allows($workspace, 'hr', 'attendance'));
        $this->assertTrue(SubscriptionAccessService::hasAccess($company)['allowed']);
        $this->assertSame($legacyBefore, $receiptTerm->fresh()->getRawOriginal());
        $this->assertSame($receiptTerm->id, $company->activeSubscription()->sole()->id);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscription_invoices', 0);
        $this->assertDatabaseCount('payment_proofs', 0);
    }

    public function test_period_boundaries_and_current_scope_state_fail_closed(): void
    {
        $this->signInBillingAdmin();
        $company = $this->company('QA-ENT-3');
        $entitlements = app(ScopedProductEntitlementService::class);
        $start = CarbonImmutable::parse('2026-11-29 09:00:00', 'Asia/Karachi');
        $end = $start->addHour();

        $entitlements->grant($company, 'lab', 'analyzer', 'agreement:qa-2', $start, $end);
        $this->assertFalse($entitlements->allows($company, 'lab', 'analyzer', $start->subSecond()));
        $this->assertTrue($entitlements->allows($company, 'lab', 'analyzer', $start->utc()));
        $this->assertTrue($entitlements->allows($company, 'lab', 'analyzer', $end->subSecond()));
        $this->assertFalse($entitlements->allows($company, 'lab', 'analyzer', $end));
        $this->assertFalse($entitlements->allows($company, 'LAB', 'analyzer', $start));
        $this->assertFalse($entitlements->allows(new Company(['id' => $company->id]), 'lab', 'analyzer', $start));

        $company->fresh()->update(['company_status' => 'suspended']);
        $this->assertFalse($entitlements->allows($company, 'lab', 'analyzer', $start));
    }

    public function test_replay_never_reactivates_a_revoked_grant_or_changes_another_scope(): void
    {
        $this->signInBillingAdmin();
        $company = $this->company('QA-ENT-4');
        $otherCompany = $this->company('QA-ENT-5');
        $entitlements = app(ScopedProductEntitlementService::class);
        $start = CarbonImmutable::parse('2026-11-29 09:00:00', 'Asia/Karachi');
        $this->travelTo($start->addMinute());
        $end = $start->addHour();
        $grant = $entitlements->grant($company, 'hr', 'attendance', 'agreement:qa-3', $start, $end);
        $replay = $entitlements->grant($company, 'hr', 'attendance', 'agreement:qa-3', $start->utc(), $end->utc());
        $this->assertSame($grant->id, $replay->id);
        $this->assertDatabaseCount('scoped_product_entitlements', 1);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'scoped_entitlement_granted', 'target_id' => $grant->id]);

        try {
            $entitlements->grant($company, 'hr', 'attendance', 'agreement:qa-3', $start, $end->addDay());
            $this->fail('Expected rejection of changed terms under the same source.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_reference', $exception->errors());
        }

        try {
            $entitlements->revoke($otherCompany, $grant->id);
            $this->fail('An unrelated scope must not revoke the grant.');
        } catch (ModelNotFoundException) {
            $this->assertTrue($entitlements->allows($company, 'hr', 'attendance'));
        }

        $entitlements->revoke($company, $grant->id);
        $entitlements->revoke($company, $grant->id);
        $this->assertNotNull($entitlements->grant($company, 'hr', 'attendance', 'agreement:qa-3', $start, $end)->revoked_at_epoch);
        $this->assertFalse($entitlements->allows($company, 'hr', 'attendance'));
        $this->assertTrue($entitlements->allows($company, 'hr', 'attendance', $start));
        $this->assertDatabaseCount('admin_audit_logs', 2);
    }

    public function test_non_billing_admin_cannot_grant(): void
    {
        $this->signInBillingAdmin('viewer');
        $company = $this->company('QA-ENT-6');
        $entitlements = app(ScopedProductEntitlementService::class);

        try {
            $entitlements->grant($company, 'hr', 'attendance', 'agreement:qa-4', now(), now()->addDay());
            $this->fail('Expected rejection of a grant from a viewer.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('scoped_product_entitlements', 0);
    }

    public function test_rollback_and_reapply_touch_only_the_new_table(): void
    {
        $company = $this->company('QA-ENT-7');
        $term = $this->paidLegacyTerm($company);
        $before = $term->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2027_01_01_000047_create_scoped_product_entitlements.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('scoped_product_entitlements'));
        $this->assertSame($before, $term->fresh()->getRawOriginal());

        $migration->up();
        $this->assertTrue(Schema::hasTable('scoped_product_entitlements'));
        $this->assertSame($before, $term->fresh()->getRawOriginal());
        $this->assertDatabaseCount('subscriptions', 1);
    }

    private function signInBillingAdmin(string $role = 'admin'): void
    {
        $this->actingAs(AdminUser::create([
            'name' => 'Example billing reviewer', 'email' => 'entitlement-reviewer@example.invalid',
            'role' => $role, 'password' => 'FictionalTestOnly987!',
        ]), 'admin');
    }

    private function company(string $ntn): Company
    {
        return Company::create([
            'name' => 'Example installation '.$ntn, 'ntn' => $ntn, 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'active',
        ]);
    }

    private function paidLegacyTerm(Company $company): Subscription
    {
        $plan = PricingPlan::create([
            'name' => 'Example paid plan', 'product_type' => 'pos', 'price' => 1000,
            'is_trial' => false, 'invoice_limit' => 100,
        ]);

        return Subscription::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
            'active' => true, 'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'final_price' => '1000.00',
        ]);
    }
}
