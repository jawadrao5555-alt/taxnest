<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, PaymentProof, PosAddon, PricingPlan, SignupPackageRequest, Subscription, SubscriptionInvoice, SystemSetting};
use App\Services\{BranchAddonService, PosAddonPricingService, PosFederalAddonAccess, PosSignupBundle, ProspectiveSubscriptionPolicy};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Mail, Notification, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class PraSignupBundleTest extends TestCase
{
    use RefreshDatabase;

    private const FEDERAL_PRICE = 4400;
    private const BASE_PRICE = 12000;
    private const BRANCH_PRICE = 20000;
    private const TOTAL = '36400.00';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        BranchAddonService::forgetSchemaProbe();
        SystemSetting::set(PosAddonPricingService::settingKey('federal_di', 'annual'), self::FEDERAL_PRICE);
        SystemSetting::set(PosAddonPricingService::settingKey('federal_ims', 'annual'), self::FEDERAL_PRICE);
    }

    private function plan(): PricingPlan
    {
        return PricingPlan::updateOrCreate(['name' => 'Business', 'product_type' => 'pos', 'is_trial' => false], [
            'price' => self::BASE_PRICE, 'invoice_limit' => 50, 'user_limit' => 2, 'branch_limit' => 1,
        ]);
    }

    private function payload(string $federal = 'federal_di'): array
    {
        return [
            'name' => 'Synthetic buyer', 'email' => 'pra-signup-bundle@example.test',
            'company_name' => 'Synthetic PRA signup bundle', 'company_ntn' => '2480005',
            'pos_type' => 'restaurant', 'pricing_plan_id' => $this->plan()->id,
            'password' => 'Synthetic123!', 'password_confirmation' => 'Synthetic123!',
            'billing_cycle' => 'annual', 'federal_signup_addon' => $federal, 'signup_extra_branch_slots' => 2,
        ];
    }

    private function signup(string $federal = 'federal_di'): SignupPackageRequest
    {
        $this->post('/pos/register', $this->payload($federal))->assertSessionHasNoErrors()->assertRedirect(route('pos.package-checkout'));
        $sale = SignupPackageRequest::sole();
        $this->assertSame(self::TOTAL, $sale->quoted_amount);
        $this->assertSame([
            'federal' => $federal, 'federal_amount' => self::FEDERAL_PRICE,
            'branch_slots' => 2, 'branch_amount' => self::BRANCH_PRICE,
        ], $sale->quote_snapshot[PosSignupBundle::KEY]);
        $company = Company::findOrFail($sale->company_id);
        $this->assertSame(0, BranchAddonService::slots($company));
        $this->assertSame('pending', PosFederalAddonAccess::state($company, $federal));
        $this->assertSame('locked', PosFederalAddonAccess::state($company, $federal === 'federal_di' ? 'federal_ims' : 'federal_di'));
        $this->assertDatabaseCount('pos_addons', 0);
        $this->assertDatabaseCount('subscription_payments', 0);
        return $sale;
    }

    private function admin(): void
    {
        $this->actingAs(AdminUser::create([
            'role' => 'admin', 'name' => 'Synthetic reviewer', 'email' => 'pra-review@example.test', 'password' => bcrypt('Synthetic123!'),
        ]), 'admin');
    }

    private function assertSettled(SignupPackageRequest $sale, string $federal): void
    {
        $sale->refresh();
        $company = Company::findOrFail($sale->company_id);
        $grant = PosAddon::sole();
        $proof = PaymentProof::sole();
        $invoice = SubscriptionInvoice::where('payment_proof_id', $proof->id)->sole();
        $paid = Subscription::where('company_id', $company->id)->where('active', true)->sole();
        $this->assertSame('approved', $sale->status);
        $this->assertSame('verified', $proof->status);
        $this->assertSame($invoice->id, $sale->subscription_invoice_id);
        $this->assertSame($sale->quote_snapshot[PosSignupBundle::KEY], $invoice->payments()->sole()->receipt_snapshot[PosSignupBundle::KEY]);
        $this->assertSame(self::TOTAL, $invoice->document_snapshot['amount_due']);
        $this->assertSame($proof->id, $grant->payment_proof_id);
        $this->assertSame($paid->id, $grant->subscription_id);
        $this->assertSame($company->id, $grant->company_id);
        $this->assertSame($federal, $grant->addon_code);
        $this->assertEquals(self::BASE_PRICE + self::BRANCH_PRICE, $paid->final_price);
        $this->assertSame(2, BranchAddonService::slots($company));
        $this->assertFalse(PosFederalAddonAccess::allows($company, $federal));
        $this->assertDatabaseCount('subscription_payments', 1);

        $this->travel(4)->days();
        $this->assertSame('paid', PosFederalAddonAccess::state($company, $federal));
        $this->assertSame('locked', PosFederalAddonAccess::state($company, $federal === 'federal_di' ? 'federal_ims' : 'federal_di'));
    }

    public function test_signup_requires_one_configured_connector_and_valid_branch_quantity(): void
    {
        $payload = $this->payload();
        $this->get('/pos/register')->assertOk()->assertSee('FBR Digital Invoice')->assertSee('FBR POS · IMS');
        $this->post('/pos/register', array_replace($payload, ['federal_signup_addon' => ['federal_di', 'federal_ims']]))
            ->assertSessionHasErrors('federal_signup_addon');
        $this->post('/pos/register', array_replace($payload, ['signup_extra_branch_slots' => 21]))
            ->assertSessionHasErrors('signup_extra_branch_slots');
        SystemSetting::set(PosAddonPricingService::settingKey('federal_di', 'annual'), 0);
        $this->post('/pos/register', $payload)->assertSessionHasErrors('pra_signup_bundle');
        $this->assertDatabaseMissing('companies', ['name' => $payload['company_name']]);
        $this->assertDatabaseCount('signup_package_requests', 0);
    }

    public function test_branch_only_signup_does_not_implicitly_open_a_federal_connector(): void
    {
        $this->post('/pos/register', array_replace($this->payload(), ['federal_signup_addon' => null]))
            ->assertSessionHasNoErrors()->assertRedirect(route('pos.package-checkout'));
        $sale = SignupPackageRequest::sole();
        $company = Company::findOrFail($sale->company_id);
        $this->assertSame('32000.00', $sale->quoted_amount);
        $this->assertNull($sale->quote_snapshot[PosSignupBundle::KEY]['federal']);
        $this->assertSame('locked', PosFederalAddonAccess::state($company, 'federal_di'));
        $this->assertSame('locked', PosFederalAddonAccess::state($company, 'federal_ims'));
        $this->assertDatabaseCount('pos_addons', 0);
    }

    public function test_manual_confirmation_collects_the_frozen_bundle_once_with_no_early_federal_access(): void
    {
        $sale = $this->signup();
        $this->get(route('pos.package-checkout'))->assertOk()->assertSee('36,400.00')->assertSee('FBR Digital Invoice');
        $this->post(route('pos.package-checkout.select'), ['plan_id' => $sale->pricing_plan_id, 'billing_cycle' => 'monthly'])
            ->assertUnprocessable();
        $this->plan()->update(['price' => 70000]);
        SystemSetting::set(PosAddonPricingService::settingKey('federal_di', 'annual'), 0);
        $this->admin();
        $confirm = route('saas.admin.package-requests.confirm', $sale);
        $data = [
            'verified_received_amount' => self::TOTAL, 'payment_date' => today()->toDateString(),
            'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ];
        $this->post($confirm, array_replace($data, ['verified_received_amount' => '32000.00']))->assertSessionHasErrors();
        $this->assertDatabaseCount('pos_addons', 0);
        $this->post($confirm, $data)->assertSessionHasNoErrors();
        $this->post($confirm, $data)->assertSessionHasNoErrors();
        $this->assertSettled($sale, 'federal_di');
    }

    public function test_uploaded_bank_proof_freezes_the_sale_and_grants_ims_only_after_exact_review(): void
    {
        $sale = $this->signup('federal_ims');
        $company = Company::findOrFail($sale->company_id);
        $this->post(route('pos.payment-proof.store'), [
            'pricing_plan_id' => $sale->pricing_plan_id, 'billing_cycle' => 'annual', 'amount' => '1.00',
            'payment_method' => 'bank', 'proof' => UploadedFile::fake()->image('synthetic-receipt.png'),
        ])->assertSessionHasNoErrors()->assertSessionHas('payment_proof', 'submitted');
        $proof = PaymentProof::sole();
        $this->assertSame($sale->quote_snapshot, $proof->distributor_quote_snapshot);
        $this->assertFalse($company->subscriptions()->where('active', true)->sole()->hasActiveOverride());
        $this->assertDatabaseCount('pos_addons', 0);

        $this->admin();
        $this->get(route('saas.admin.payment-proofs', ['company_id' => $company->id]))
            ->assertOk()->assertSee('FBR POS · IMS')->assertSee('Original signup package and add-ons')
            ->assertSee('36,400.00')->assertDontSee('name="extra_branch_slots"', false);
        $approve = route('saas.admin.payment-proofs.approve', $proof);
        $review = ['pricing_plan_id' => $sale->pricing_plan_id, 'billing_cycle' => 'annual', 'verified_received_amount' => self::TOTAL];
        $this->post($approve, $review + ['extra_branch_slots' => 1])->assertSessionHas('error');
        $this->post($approve, array_replace($review, ['verified_received_amount' => '36399.99']))->assertRedirect();
        $this->assertSame('pending', $proof->fresh()->status);
        $this->post($approve, $review)->assertSessionHasNoErrors();
        $this->post($approve, $review)->assertSessionHasNoErrors();
        $this->assertSettled($sale, 'federal_ims');
    }
}
