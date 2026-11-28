<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, PaymentProof, PosAddon, PosImsProfile, PricingPlan, Subscription, SubscriptionInvoice, SubscriptionPayment};
use App\Services\{PosAddonService, PosFederalAddonAccess};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\{Carbon, Str};
use Tests\TestCase;

final class HistoricalPosFederalAddonTest extends TestCase
{
    use RefreshDatabase;

    private int $companyNumber = 4298300;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-28 10:00:00', config('app.timezone')));
        config(['pra_di_sandbox.paid_addon_policy_starts_at' => '2026-10-01']);
        PosAddonService::flushCache();
        $this->admin();
    }

    public function test_included_feature_uses_exact_package_receipt_without_recording_more_money_or_enabling_fbr(): void
    {
        [$company, $term] = $this->package();
        [$other] = $this->package();
        $profile = PosImsProfile::create(['company_id' => $company->id, 'enabled' => false, 'environment' => 'sandbox']);
        $initialProfile = $profile->fresh()->getRawOriginal();
        $initialTerm = $term->fresh()->getRawOriginal();
        $action = route('saas.admin.companies.historical-federal-addon.record', $company->id);
        $payload = $this->included($term);

        $this->postJson($action, $payload)->assertUnprocessable()->assertJsonValidationErrors('historical_addon');
        $this->assertDatabaseCount('pos_addons', 0);
        $this->recordPackage($company, $term);
        $baseProof = PaymentProof::sole();
        $page = route('saas.admin.companies.historical-federal-addon', $company->id);
        $this->get($page)->assertOk()->assertSee('Complimentary with the paid package');
        $this->post($action, $payload)->assertSessionHasNoErrors()->assertRedirect($page);
        $this->post($action, $payload)->assertSessionHasNoErrors()->assertRedirect($page);
        $this->postJson($action, array_replace($payload, ['evidence_reference' => 'Different terms']))
            ->assertUnprocessable()->assertJsonValidationErrors('historical_addon');

        $grant = PosAddon::sole();
        $this->assertSame($baseProof->id, $grant->payment_proof_id);
        $this->assertSame($term->id, $grant->subscription_id);
        $this->assertSame('0.00', $grant->amount);
        $this->assertSame('included', $grant->historical_purchase['mode']);
        $this->assertSame('2026-11-21', $grant->starts_at->toDateString());
        $this->assertSame('2027-11-20', $grant->ends_at->toDateString());
        $this->assertDatabaseCount('payment_proofs', 1);
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertSame('paid', PosFederalAddonAccess::state($company, 'federal_ims'));
        $this->assertSame('Setup required', PosFederalAddonAccess::display($company, 'federal_ims', $profile->fresh()->enabled));
        $this->assertSame('locked', PosFederalAddonAccess::state($company, 'federal_di'));
        $this->assertSame('locked', PosFederalAddonAccess::state($other, 'federal_ims'));
        $this->assertSame($initialProfile, $profile->fresh()->getRawOriginal());
        $this->assertSame($initialTerm, $term->fresh()->getRawOriginal());

        $this->travelTo($term->end_date->copy()->addDay());
        $this->assertSame('expired', PosFederalAddonAccess::state($company, 'federal_ims'));
    }

    public function test_separate_historical_payment_preserves_exact_paid_amount_method_and_original_date(): void
    {
        [$company, $term] = $this->package();
        $this->recordPackage($company, $term);
        $action = route('saas.admin.companies.historical-federal-addon.record', $company->id);
        $payload = array_replace($this->included($term), [
            'addon_code' => 'federal_di', 'purchase_mode' => 'separate', 'received_amount' => '2460.50',
            'payment_date' => '2026-11-23', 'payment_method' => 'bank', 'payment_reference' => 'Synthetic transfer',
        ]);

        $this->postJson($action, array_replace($payload, ['payment_date' => '2026-12-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_date');
        $this->post($action, $payload)->assertSessionHasNoErrors();
        $this->post($action, $payload)->assertSessionHasNoErrors();
        $this->postJson($action, array_replace($payload, ['idempotency_key' => (string) Str::uuid()]))
            ->assertUnprocessable()->assertJsonValidationErrors('historical_addon');

        $proof = PaymentProof::posAddonKind()->sole();
        $grant = PosAddon::sole();
        $invoice = SubscriptionInvoice::where('payment_proof_id', $proof->id)->sole();
        $receipt = SubscriptionPayment::where('subscription_invoice_id', $invoice->id)->sole();
        $this->assertSame($proof->id, $grant->payment_proof_id);
        $this->assertSame('2460.50', $grant->amount);
        $this->assertSame('separate', $grant->historical_purchase['mode']);
        $this->assertSame('2026-11-23', $invoice->paid_at->toDateString());
        $this->assertSame('2026-11-23', $receipt->paid_at->toDateString());
        $this->assertSame('bank', $receipt->payment_method);
        $this->assertSame('2460.50', $receipt->receipt_snapshot['verified_received']);
        $this->assertSame('pos_addon', $receipt->receipt_snapshot['kind']);
        $this->assertSame('paid', PosFederalAddonAccess::state($company, 'federal_di'));
        $this->assertDatabaseCount('subscription_payments', 2);
        $this->assertDatabaseCount('pos_di_sandbox_profiles', 0);
    }

    public function test_wrong_term_support_actor_and_other_configured_legacy_channel_cannot_record(): void
    {
        [$company, $term] = $this->package();
        [, $otherTerm] = $this->package();
        $this->recordPackage($company, $term);
        $action = route('saas.admin.companies.historical-federal-addon.record', $company->id);
        $this->postJson($action, $this->included($otherTerm))->assertNotFound();

        $ims = PosImsProfile::create(['company_id' => $company->id, 'enabled' => true, 'environment' => 'sandbox']);
        $ims->forceFill(['created_at' => '2026-09-10'])->save();
        $differentChannel = array_replace($this->included($term), ['addon_code' => 'federal_di']);
        $this->postJson($action, $differentChannel)->assertUnprocessable()->assertJsonValidationErrors('historical_addon');

        $this->admin('support');
        $this->get(route('saas.admin.companies.historical-federal-addon', $company->id))->assertForbidden();
        $this->postJson($action, $this->included($term))->assertForbidden();
        $this->assertDatabaseCount('pos_addons', 0);
        $this->assertDatabaseCount('subscription_payments', 1);
    }

    public function test_existing_pending_purchase_and_ambiguous_active_terms_require_review(): void
    {
        [$company, $term] = $this->package();
        $this->recordPackage($company, $term);
        $action = route('saas.admin.companies.historical-federal-addon.record', $company->id);
        $pending = PaymentProof::create([
            'company_id' => $company->id, 'subscription_id' => $term->id, 'request_type' => 'pos_addon',
            'addon_codes' => '["federal_ims"]', 'proof_path' => 'synthetic-payment-proof', 'status' => 'pending',
        ]);

        $this->postJson($action, $this->included($term))->assertUnprocessable()->assertJsonValidationErrors('historical_addon');
        $pending->update(['status' => 'rejected']);
        $term->replicate()->save();
        $this->postJson($action, $this->included($term))->assertUnprocessable()->assertJsonValidationErrors('historical_addon');
        $this->assertDatabaseCount('pos_addons', 0);
        $this->assertDatabaseCount('subscription_payments', 1);
    }

    private function package(): array
    {
        $company = Company::create([
            'name' => 'Synthetic PRA customer', 'product_type' => 'pos', 'pos_integration_mode' => 'pra',
            'status' => 'approved', 'company_status' => 'active', 'ntn' => (string) ++$this->companyNumber,
        ]);
        $plan = PricingPlan::where('name', 'Starter')->where('product_type', 'pos')->firstOrFail();
        $term = Subscription::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
            'discount_percent' => 0, 'final_price' => '37999.00', 'start_date' => '2026-11-20',
            'end_date' => '2027-11-20', 'active' => true,
        ]);

        return [$company, $term];
    }

    private function admin(string $role = 'admin'): void
    {
        $this->actingAs(AdminUser::create([
            'name' => 'Synthetic billing reviewer', 'email' => $role.'-historical-addon@example.test',
            'password' => 'Synthetic password', 'role' => $role,
        ]), 'admin');
    }

    private function included(Subscription $term): array
    {
        return [
            'subscription_id' => $term->id, 'addon_code' => 'federal_ims', 'purchase_mode' => 'included',
            'idempotency_key' => (string) Str::uuid(), 'evidence_reference' => 'Synthetic historical sale',
        ];
    }

    private function recordPackage(Company $company, Subscription $term): void
    {
        $this->post(route('saas.admin.companies.package-receipts.record', [$company->id, $term->id]), [
            'verified_received_amount' => '37999.00', 'payment_date' => '2026-11-21',
            'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors()->assertRedirect();
    }
}
