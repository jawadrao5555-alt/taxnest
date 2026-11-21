<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\RequestedPackageService;
use App\Services\SubscriptionAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE PACKAGE A SHOP CLICKED MUST SURVIVE SIGNUP — AND ONLY A REAL ONE.
 *
 * The public pricing tables send a visitor to signup with ?plan=<package>
 * (Digital Invoice may also carry a legacy &cycle value). That request is stored
 * against the new company and approval activates it on the only sellable cycle:
 * configured periods for DI and annual for FBR POS and PRA POS.
 *
 * The dangerous half is the other one: a tampered, unknown, trial or
 * wrong-product name must never be recorded (FBR POST rejects it; old DI behavior ignores it), so no
 * shop is ever activated on something it did not pick and pay for.
 */
class RequestedPackageSignupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('owner_name')->nullable();
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('business_activity')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->default('pending');
            $table->string('company_status')->default('active');
            $table->string('product_type')->nullable();
            $table->string('pos_type')->nullable();
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_environment')->nullable();
            $table->string('pos_integration_mode')->nullable();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->string('requested_billing_cycle', 20)->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_pos_environment')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->decimal('standard_tax_rate', 8, 2)->nullable();
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->boolean('is_internal_account')->default(false);
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
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_quarterly', 12, 2)->nullable();
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->integer('branch_limit')->nullable();
            $table->boolean('whatsapp_enabled')->default(false);
            $table->boolean('caller_id_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->decimal('discount_percent', 8, 2)->default(0);
            $table->decimal('final_price', 12, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->string('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->timestamps();
        });

        Schema::create('registered_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('credential_type');
            $table->string('credential_value', 191);
            $table->string('product_type')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['credential_type', 'credential_value']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
        });

        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Mail::fake();
        Notification::fake();

        $this->seedPlans();
    }

    /** One paid + one trial package per product, priced the way each is sold. */
    private function seedPlans(): void
    {
        // DI: price is the MONTHLY rate (cycle discount applied on top).
        PricingPlan::create(['name' => 'Business', 'product_type' => 'di', 'price' => 3000, 'price_quarterly' => 8700, 'is_trial' => false]);
        PricingPlan::create(['name' => 'DI Trial', 'product_type' => 'di', 'price' => 0, 'is_trial' => true]);

        // FBR POS: price is the ANNUAL rate since 23 Aug 2026 (like PRA POS).
        PricingPlan::create(['name' => 'Business', 'product_type' => 'fbrpos', 'price' => 3000, 'is_trial' => false]);
        // Retired 23 Aug 2026 — kept only to prove it can never be picked again.
        PricingPlan::create(['name' => 'Pro', 'product_type' => 'fbrpos', 'price' => 2999, 'is_trial' => false]);
        PricingPlan::create(['name' => 'FBR Trial', 'product_type' => 'fbrpos', 'price' => 0, 'is_trial' => true]);

        // PRA POS: price is ALREADY the annual total.
        PricingPlan::create(['name' => 'Starter', 'product_type' => 'pos', 'price' => 30000, 'is_trial' => false]);
        $business = PricingPlan::create(['name' => 'Business', 'product_type' => 'pos', 'price' => 40000, 'is_trial' => false]);
        PricingPlan::whereKey($business->id)->update(['whatsapp_enabled' => true]);
        PricingPlan::create(['name' => 'Pro Max', 'product_type' => 'pos', 'price' => 50000, 'is_trial' => false]);
        PricingPlan::create(['name' => 'POS Trial', 'product_type' => 'pos', 'price' => 0, 'is_trial' => true]);
    }

    private function plan(string $name, string $productType): PricingPlan
    {
        return PricingPlan::where('name', $name)->where('product_type', $productType)->firstOrFail();
    }

    /** Post an FBR POS signup, optionally carrying a package name. */
    private function fbrSignup(string $company, string $email, ?string $requestedPlan = null, string $posType = 'restaurant'): Company
    {
        $payload = [
            'company_name' => $company,
            'company_ntn' => (string) random_int(1000000, 9999999),
            'name' => 'Owner Sahib',
            'email' => $email,
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'pos_type' => $posType,
        ];
        if ($requestedPlan !== null) {
            $payload['requested_plan'] = $requestedPlan;
        }

        $this->post('/fbr-pos/register', $payload)->assertSessionHasNoErrors()
            ->assertRedirect($posType === 'education' ? '/fbr-pos/school-fees' : ($posType === 'clinic' ? '/fbr-pos/hospital' : '/fbr-pos/create'));

        return Company::where('name', $company)->firstOrFail();
    }

    private function rejectFbrPackage(string $plan): void
    {
        $before = Company::count();
        $this->postJson('/fbr-pos/register', [
            'company_name' => 'Synthetic Invalid Package', 'company_ntn' => '9876501',
            'name' => 'Synthetic Owner', 'email' => 'invalid-package@example.test',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
            'pos_type' => 'retail', 'requested_plan' => $plan,
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_plan');
        $this->assertSame($before, Company::count());
    }

    /** Post a Digital Invoice signup, optionally carrying a package + cycle. */
    private function diSignup(string $company, string $email, ?string $requestedPlan = null, ?string $cycle = null): Company
    {
        $payload = [
            'name' => 'Owner Sahib',
            'email' => $email,
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'company_name' => $company,
            'company_ntn' => (string) random_int(1000000, 9999999),
        ];
        if ($requestedPlan !== null) {
            $payload['requested_plan'] = $requestedPlan;
        }
        if ($cycle !== null) {
            $payload['requested_billing_cycle'] = $cycle;
        }

        $this->post('/register', $payload)->assertSessionHasNoErrors();

        return Company::where('name', $company)->firstOrFail();
    }

    /** Post a PRA POS signup with an optional public add-on selection. */
    private function posSignup(string $company, string $email, array $addons = [], string $cycle = 'annual'): Company
    {
        $payload = [
            'company_name' => $company,
            'company_ntn' => (string) random_int(1000000, 9999999),
            'name' => 'Owner Sahib',
            'email' => $email,
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'pos_type' => 'restaurant',
            'pricing_plan_id' => $this->plan('Business', 'pos')->id,
            'requested_addons' => $addons,
            'requested_addon_cycle' => $cycle,
        ];

        $this->post('/pos/register', $payload)->assertSessionHasNoErrors();

        return Company::where('name', $company)->firstOrFail();
    }

    // ── Recording the request at signup ───────────────────────────────

    public function test_hospital_signup_is_standalone_local_only_and_selects_its_own_product_package(): void
    {
        $this->get('/fbr-pos/register')->assertOk()->assertSee('Hospital / Clinic');
        $hospital = $this->fbrSignup('Synthetic Clinic', 'clinic@example.test', 'Business', 'clinic');
        $this->assertTrue($hospital->isHospitalCollectionPilot());
        $this->assertFalse($hospital->isSchoolFeePilot());
        $this->assertFalse($hospital->fbr_reporting_enabled);
        $this->assertSame($this->plan('Business', 'fbrpos')->id, (int) $hospital->requested_plan_id);
        $this->assertSame('clinic', $hospital->pos_type);
    }

    public function test_public_registration_rejects_cross_product_category_and_plan_payload_shapes(): void
    {
        foreach ([
            ['/fbr-pos/register', ['product_type' => 'pos'], 'product_type'],
            ['/pos/register', ['product_type' => 'fbrpos'], 'product_type'],
            ['/fbr-pos/register', ['pos_type' => 'warehouse'], 'pos_type'],
            ['/pos/register', ['pos_type' => 'pharmacy'], 'pos_type'],
            ['/fbr-pos/register', ['pos_type' => 'clinic', 'business_category' => 'retail'], 'business_category'],
            ['/fbr-pos/register', ['requested_plan' => ['Business']], 'requested_plan'],
            ['/fbr-pos/register', ['pricing_plan_id' => $this->plan('Starter', 'pos')->id], 'pricing_plan_id'],
            ['/pos/register', ['pricing_plan_id' => $this->plan('Business', 'fbrpos')->id], 'pricing_plan_id'],
        ] as [$url, $payload, $error]) {
            $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
        }
        $this->assertSame(0, Company::count());
    }

    public function test_fbr_legacy_plan_id_cannot_override_named_plan_or_select_retired_package(): void
    {
        $starter = PricingPlan::create(['name' => 'Starter', 'product_type' => 'fbrpos', 'price' => 2000, 'is_trial' => false]);
        $base = ['company_name' => 'Synthetic Legacy Package', 'company_ntn' => '9876502',
            'name' => 'Synthetic Owner', 'email' => 'legacy-package@example.test',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123', 'pos_type' => 'general'];
        foreach ([
            ['pricing_plan_id' => $starter->id, 'requested_plan' => 'Business'],
            ['pricing_plan_id' => $this->plan('Pro', 'fbrpos')->id],
            ['pricing_plan_id' => $this->plan('FBR Trial', 'fbrpos')->id],
        ] as $selection) {
            $this->postJson('/fbr-pos/register', $base + $selection)
                ->assertUnprocessable()->assertJsonValidationErrors('pricing_plan_id');
        }
        $this->assertSame(0, Company::count());
    }

    public function test_school_signup_uses_existing_fbr_product_and_keeps_reporting_disabled(): void
    {
        $this->get('/fbr-pos/register')->assertOk()->assertSee('School / Education');
        $school = $this->fbrSignup('Synthetic School', 'school@example.test', 'Business', 'education');

        $this->assertSame('fbrpos', $school->product_type);
        $this->assertSame('education', $school->pos_type);
        $this->assertTrue($school->isSchoolFeePilot());
        $this->assertTrue((bool) $school->fbr_pos_enabled);
        $this->assertFalse((bool) $school->fbr_reporting_enabled);
        $this->assertSame('sandbox', $school->fbr_pos_environment);
        $this->assertSame('pending', $school->status);
        $this->assertSame($this->plan('Business', 'fbrpos')->id, (int) $school->requested_plan_id);
        $this->assertNotNull(Subscription::where('company_id', $school->id)->first());
    }

    public function test_existing_retail_signup_defaults_remain_unchanged(): void
    {
        $retail = $this->fbrSignup('Synthetic Retail', 'retail@example.test', 'Business', 'retail');

        $this->assertSame('fbrpos', $retail->product_type);
        $this->assertSame('retail', $retail->pos_type);
        $this->assertFalse($retail->isSchoolFeePilot());
        $this->assertTrue((bool) $retail->fbr_pos_enabled);
        $this->assertFalse((bool) $retail->fbr_reporting_enabled);
        $this->assertSame('sandbox', $retail->fbr_pos_environment);
        $this->assertSame('pending', $retail->status);
        $this->assertSame($this->plan('Business', 'fbrpos')->id, (int) $retail->requested_plan_id);
    }

    public function test_fbr_signup_records_the_clicked_package(): void
    {
        $company = $this->fbrSignup('City Mart', 'city@example.com', 'Business');

        $this->assertSame($this->plan('Business', 'fbrpos')->id, (int) $company->requested_plan_id);
        // FBR POS is yearly — the visitor never picks a cycle.
        $this->assertSame('annual', $company->requested_billing_cycle);
    }

    public function test_fbr_signup_matches_the_package_name_case_insensitively(): void
    {
        $company = $this->fbrSignup('Metro Shoes', 'metro@example.com', 'bUsInEsS');

        $this->assertSame($this->plan('Business', 'fbrpos')->id, (int) $company->requested_plan_id);
    }

    /** A stale link to the retired FBR package must record nothing at all. */
    public function test_fbr_signup_rejects_the_retired_package(): void
    {
        $this->rejectFbrPackage('Pro');
    }

    public function test_di_signup_records_the_package_and_configured_cycle(): void
    {
        $company = $this->diSignup('Ali Traders', 'ali@example.com', 'Business', 'quarterly');

        $this->assertSame($this->plan('Business', 'di')->id, (int) $company->requested_plan_id);
        $this->assertSame('quarterly', $company->requested_billing_cycle);
    }

    public function test_di_signup_defaults_to_annual_when_missing_and_refuses_unknown_period(): void
    {
        $missing = $this->diSignup('No Cycle Co', 'nocycle@example.com', 'Business');
        $this->assertSame('annual', $missing->requested_billing_cycle);

        $this->post('/register', ['name' => 'Synthetic owner', 'email' => 'badcycle@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
            'company_name' => 'Bad Cycle Co', 'company_ntn' => '7654321', 'requested_plan' => 'Business',
            'requested_billing_cycle' => 'weekly-ish'])->assertSessionHasErrors('billing_cycle');
        $this->assertFalse(Company::where('name', 'Bad Cycle Co')->exists());
    }

    // ── Edge cases: nothing wrong may ever be recorded ────────────────

    public function test_a_signup_with_no_package_behind_it_records_nothing(): void
    {
        $fbr = $this->fbrSignup('Walk In Mart', 'walkin@example.com');
        $this->assertNull($fbr->requested_plan_id);
        $this->assertNull($fbr->requested_billing_cycle);

        $di = $this->diSignup('Direct Traders', 'direct@example.com');
        $this->assertNull($di->requested_plan_id);
        $this->assertNull($di->requested_billing_cycle);
    }

    public function test_a_package_belonging_to_another_product_is_ignored(): void
    {
        // 'Starter' is a real paid package — just not an FBR POS one.
        $this->rejectFbrPackage('Starter');

        // 'Pro Max' is a real PRA POS package name — never a DI one.
        $di = $this->diSignup('Cross Product Traders', 'cross2@example.com', 'Pro Max', 'annual');
        $this->assertNull($di->requested_plan_id, 'A DI shop must never be recorded against a POS package');
        $this->assertNull($di->requested_billing_cycle, 'No package means no cycle either');
    }

    public function test_a_trial_package_is_ignored(): void
    {
        $this->rejectFbrPackage('FBR Trial');

        $di = $this->diSignup('Trial Traders', 'trialtraders@example.com', 'DI Trial', 'annual');
        $this->assertNull($di->requested_plan_id);
    }

    public function test_a_tampered_or_unknown_package_is_ignored(): void
    {
        $this->rejectFbrPackage("Business' OR 1=1 --");

        $di = $this->diSignup('Ghost Traders', 'ghost@example.com', 'Package That Does Not Exist', 'annual');
        $this->assertNull($di->requested_plan_id);
    }

    // ── The signup pages carry the pick into the form ─────────────────

    public function test_signup_pages_carry_the_pick_and_fbr_exposes_an_explicit_package_choice(): void
    {
        $response = $this->get('/register?plan=Business&cycle=quarterly')
            ->assertOk()
            ->assertSee('name="requested_plan" required', false)
            ->assertSee('value="Business" selected', false);

        // DI now lets the visitor edit the cycle. Verify the selected option on
        // the actual submitted control instead of expecting a hidden input.
        $document = new \DOMDocument();
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $selected = $xpath->query('//form//select[@name="requested_billing_cycle" and @required]/option[@selected]');
        $this->assertCount(1, $selected);
        $this->assertSame('quarterly', $selected->item(0)->getAttribute('value'));

        $this->get('/fbr-pos/register?plan=Business')
            ->assertOk()
            ->assertSee('name="requested_plan"', false)
            ->assertSee('value="Business" selected', false)
            ->assertSee('signup-package-choice', false);

        // A retired FBR package must not survive the trip into the form.
        $this->get('/fbr-pos/register?plan=Pro')
            ->assertOk()
            ->assertDontSee('value="Pro"', false)
            ->assertSee('name="requested_plan"', false);

        // Nothing picked still requires an explicit compatible package; no selection is invented.
        $this->get('/register')->assertOk()->assertSee('name="requested_plan" required', false)->assertSee(__('billing_policy.choose_plan'));
        $this->get('/fbr-pos/register')->assertOk()->assertSee('name="requested_plan"', false)->assertSee(__('billing_policy.choose_plan'));

        // A trial / wrong-product name in the link is not echoed back either.
        $this->get('/register?plan=DI+Trial')->assertOk()->assertSee('name="requested_plan" required', false)->assertDontSee('value="DI Trial"', false);
        $this->get('/fbr-pos/register?plan=Starter')->assertOk()->assertDontSee('value="Starter"', false)->assertSee('name="requested_plan"', false);
    }

    public function test_pos_signup_page_carries_only_allow_listed_addons_into_the_form(): void
    {
        $this->get('/pos/register?plan=Business&addons[]=caller_id&addons[]=whatsapp_bill&addons[]=delivery_riders&addons[]=not-a-real-feature&addon_cycle=quarterly')
            ->assertOk()
            ->assertSee('name="requested_addons[]" value="caller_id"', false)
            ->assertDontSee('name="requested_addons[]" value="whatsapp_bill"', false)
            ->assertDontSee('name="requested_addons[]" value="delivery_riders"', false)
            ->assertDontSee('name="requested_addons[]" value="not-a-real-feature"', false)
            ->assertSee('name="requested_addon_cycle" value="annual"', false);
    }

    public function test_pos_signup_remembers_the_addon_quote_as_annual(): void
    {
        $this->posSignup(
            'Addon Selection Mart',
            'addon-selection@example.com',
            ['caller_id', 'whatsapp_bill'],
            'quarterly'
        );

        $selection = session(\App\Services\PosAddonService::SIGNUP_SESSION_KEY);
        $this->assertSame(['caller_id'], $selection['codes']);
        $this->assertSame('annual', $selection['cycle']);
        $this->assertSame(['codes', 'cycle'], array_keys($selection));
    }

    public function test_tampered_pos_addon_values_do_not_block_signup_or_enter_the_session(): void
    {
        $this->posSignup(
            'Tampered Addon Mart',
            'tampered-addon@example.com',
            ['caller_id', 'free_ferrari'],
            'fortnightly'
        );

        $selection = session(\App\Services\PosAddonService::SIGNUP_SESSION_KEY);
        $this->assertSame(['caller_id'], $selection['codes']);
        $this->assertSame('annual', $selection['cycle']);
        $this->assertSame(['codes', 'cycle'], array_keys($selection));
    }

    // ── Approval charges the period the product actually sells ────────

    private function pendingCompany(string $productType, PricingPlan $plan, ?string $cycle = null): Company
    {
        return Company::create([
            'name' => 'Pending ' . $productType . ' ' . $plan->id . ' ' . ($cycle ?? 'none'),
            'product_type' => $productType,
            'status' => 'pending',
            'company_status' => 'pending',
            'requested_plan_id' => $plan->id,
            'requested_billing_cycle' => $cycle,
        ]);
    }

    public function test_approval_activates_the_explicitly_configured_di_period(): void
    {
        $plan = $this->plan('Business', 'di');
        $company = $this->pendingCompany('di', $plan, 'quarterly');

        $sub = SubscriptionAssignmentService::assignRequestedPlanOnApproval($company);

        $this->assertNotNull($sub);
        $this->assertSame($plan->id, (int) $sub->pricing_plan_id);
        $this->assertSame('quarterly', $sub->billing_cycle);
        // Explicit quarterly total; no invented short-cycle discount.
        $this->assertSame(8700.0, (float) $sub->final_price);
        $this->assertSame(3, (int) $sub->start_date->diffInMonths($sub->end_date), 'Expiry must match the configured cycle charged');
    }

    public function test_approval_activates_a_di_package_annually_when_no_cycle_was_stored(): void
    {
        $plan = $this->plan('Business', 'di');
        $company = $this->pendingCompany('di', $plan, null);

        $sub = SubscriptionAssignmentService::assignRequestedPlanOnApproval($company);

        $this->assertSame('annual', $sub->billing_cycle);
        $this->assertSame(33840.0, (float) $sub->final_price);
        $this->assertSame(12, (int) $sub->start_date->diffInMonths($sub->end_date));
    }

    public function test_approval_activates_an_fbr_package_for_a_full_year(): void
    {
        $plan = $this->plan('Business', 'fbrpos');
        $company = $this->pendingCompany('fbrpos', $plan, 'annual');

        $sub = SubscriptionAssignmentService::assignRequestedPlanOnApproval($company);

        $this->assertSame('annual', $sub->billing_cycle);
        // 23 Aug 2026: fbrpos price IS the yearly rate — never ×12 again.
        $this->assertSame(3000.0, (float) $sub->final_price);
        $this->assertSame(12, (int) $sub->start_date->diffInMonths($sub->end_date));
    }

    public function test_missing_or_unactivated_short_request_requires_review_without_annual_fallback(): void
    {
        foreach (['fbrpos','pos'] as $type) {
            $plan=$this->plan($type === 'pos' ? 'Starter' : 'Business',$type);
            $plan->update(['price_monthly'=>2599]);
            $company=$this->pendingCompany($type,$plan->fresh(),'monthly');
            $this->assertNull(SubscriptionAssignmentService::assignRequestedPlanOnApproval($company));
        }
    }

    public function test_retired_pos_plan_cannot_be_requested_or_assigned_through_a_service_bypass(): void
    {
        $retired = $this->plan('Pro Max', 'pos');

        $this->assertNull(RequestedPackageService::resolvePlan('Pro Max', 'pos'));

        $company = $this->pendingCompany('pos', $retired, 'annual');
        $this->assertNull(SubscriptionAssignmentService::assignRequestedPlanOnApproval($company));
        $this->assertSame(0, Subscription::where('company_id', $company->id)->count());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        SubscriptionAssignmentService::assign($company->id, $retired->id, 'annual');
    }

    public function test_approval_activates_nothing_without_a_requested_package(): void
    {
        $company = Company::create([
            'name' => 'No Request Co',
            'product_type' => 'di',
            'status' => 'pending',
            'company_status' => 'pending',
        ]);

        $this->assertNull(SubscriptionAssignmentService::assignRequestedPlanOnApproval($company));
        $this->assertSame(0, Subscription::where('company_id', $company->id)->count());
    }

    // ── What the admin is shown before clicking Approve ───────────────

    public function test_admin_summary_names_the_package_cycle_and_real_charge(): void
    {
        $di = $this->pendingCompany('di', $this->plan('Business', 'di'), 'quarterly');
        $summary = RequestedPackageService::pendingSummary($di);

        $this->assertNotNull($summary);
        $this->assertSame('Business', $summary['name']);
        $this->assertSame('Quarterly', $summary['cycle_label']);
        $this->assertSame(8700.0, $summary['price']);
        $this->assertStringContainsString('Rs 8,700 every 3 months', $summary['badge']);
        $this->assertStringContainsString('3 months', $summary['note']);

        $fbr = $this->pendingCompany('fbrpos', $this->plan('Business', 'fbrpos'), null);
        $fbrSummary = RequestedPackageService::pendingSummary($fbr);
        $this->assertStringContainsString('Rs 3,000 / year', $fbrSummary['badge']);
    }

    public function test_unconfigured_legacy_requested_period_remains_visible_for_review_without_assignment(): void
    {
        $company = $this->pendingCompany('di', $this->plan('Business', 'di'), 'monthly');
        $summary = RequestedPackageService::pendingSummary($company);
        $this->assertFalse($summary['available']);
        $this->assertNull($summary['price']);
        $this->assertStringContainsString('unavailable', $summary['badge']);
        $this->assertNull(SubscriptionAssignmentService::assignRequestedPlanOnApproval($company));
        $this->assertSame('monthly', $company->fresh()->requested_billing_cycle);
        $this->assertSame(0, Subscription::where('company_id', $company->id)->count());
    }

    public function test_admin_summary_shows_nothing_for_a_di_signup_that_picked_no_package(): void
    {
        // DI signups land status=pending / company_status=active.
        $company = $this->diSignup('Quiet Traders', 'quiet@example.com');

        $this->assertNull(RequestedPackageService::pendingSummary($company));
    }

    public function test_admin_summary_disappears_once_the_company_is_approved(): void
    {
        $company = $this->pendingCompany('di', $this->plan('Business', 'di'), 'annual');
        $this->assertNotNull(RequestedPackageService::pendingSummary($company));

        $company->update(['status' => 'approved', 'company_status' => 'active']);
        $this->assertNull(RequestedPackageService::pendingSummary($company->fresh()));
    }
}
