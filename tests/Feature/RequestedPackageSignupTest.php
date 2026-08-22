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
 * (Digital Invoice also carries &cycle=<billing cycle>). That request is stored
 * against the new company and approval activates EXACTLY it, charged for the
 * period the product sells: DI on the picked cycle, FBR POS / PRA POS yearly.
 *
 * The dangerous half is the other one: a tampered, unknown, trial or
 * wrong-product name in the link must be ignored rather than recorded, so no
 * shop is ever activated on something it did not pick and pay for.
 */
class RequestedPackageSignupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->integer('branch_limit')->nullable();
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
        PricingPlan::create(['name' => 'Business', 'product_type' => 'di', 'price' => 3000, 'is_trial' => false]);
        PricingPlan::create(['name' => 'DI Trial', 'product_type' => 'di', 'price' => 0, 'is_trial' => true]);

        // FBR POS: price is MONTHLY, but it is licensed by the YEAR (12 × −6%).
        PricingPlan::create(['name' => 'Pro', 'product_type' => 'fbrpos', 'price' => 3000, 'is_trial' => false]);
        PricingPlan::create(['name' => 'FBR Trial', 'product_type' => 'fbrpos', 'price' => 0, 'is_trial' => true]);

        // PRA POS: price is ALREADY the annual total.
        PricingPlan::create(['name' => 'POS Basic', 'product_type' => 'pos', 'price' => 30000, 'is_trial' => false]);
        PricingPlan::create(['name' => 'POS Trial', 'product_type' => 'pos', 'price' => 0, 'is_trial' => true]);
    }

    private function plan(string $name, string $productType): PricingPlan
    {
        return PricingPlan::where('name', $name)->where('product_type', $productType)->firstOrFail();
    }

    /** Post an FBR POS signup, optionally carrying a package name. */
    private function fbrSignup(string $company, string $email, ?string $requestedPlan = null): Company
    {
        $payload = [
            'company_name' => $company,
            'company_ntn' => (string) random_int(1000000, 9999999),
            'name' => 'Owner Sahib',
            'email' => $email,
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'pos_type' => 'retail',
        ];
        if ($requestedPlan !== null) {
            $payload['requested_plan'] = $requestedPlan;
        }

        $this->post('/fbr-pos/register', $payload)->assertSessionHasNoErrors();

        return Company::where('name', $company)->firstOrFail();
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
            'pos_type' => 'retail',
            'pricing_plan_id' => $this->plan('POS Basic', 'pos')->id,
            'requested_addons' => $addons,
            'requested_addon_cycle' => $cycle,
        ];

        $this->post('/pos/register', $payload)->assertSessionHasNoErrors();

        return Company::where('name', $company)->firstOrFail();
    }

    // ── Recording the request at signup ───────────────────────────────

    public function test_fbr_signup_records_the_clicked_package(): void
    {
        $company = $this->fbrSignup('City Mart', 'city@example.com', 'Pro');

        $this->assertSame($this->plan('Pro', 'fbrpos')->id, (int) $company->requested_plan_id);
        // FBR POS is yearly — the visitor never picks a cycle.
        $this->assertSame('annual', $company->requested_billing_cycle);
    }

    public function test_fbr_signup_matches_the_package_name_case_insensitively(): void
    {
        $company = $this->fbrSignup('Metro Shoes', 'metro@example.com', 'pRo');

        $this->assertSame($this->plan('Pro', 'fbrpos')->id, (int) $company->requested_plan_id);
    }

    public function test_di_signup_records_the_package_and_the_picked_cycle(): void
    {
        $company = $this->diSignup('Ali Traders', 'ali@example.com', 'Business', 'quarterly');

        $this->assertSame($this->plan('Business', 'di')->id, (int) $company->requested_plan_id);
        $this->assertSame('quarterly', $company->requested_billing_cycle);
    }

    public function test_di_signup_defaults_to_monthly_when_the_cycle_is_missing_or_unknown(): void
    {
        $missing = $this->diSignup('No Cycle Co', 'nocycle@example.com', 'Business');
        $this->assertSame('monthly', $missing->requested_billing_cycle);

        $garbage = $this->diSignup('Bad Cycle Co', 'badcycle@example.com', 'Business', 'weekly-ish');
        $this->assertSame($this->plan('Business', 'di')->id, (int) $garbage->requested_plan_id);
        $this->assertSame('monthly', $garbage->requested_billing_cycle, 'An unrecognised cycle must fall back to monthly, not be stored raw');
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
        // 'POS Basic' is a real paid package — just not an FBR POS one.
        $fbr = $this->fbrSignup('Cross Product Mart', 'cross@example.com', 'POS Basic');
        $this->assertNull($fbr->requested_plan_id, 'An FBR shop must never be recorded against a PRA POS package');

        $di = $this->diSignup('Cross Product Traders', 'cross2@example.com', 'Pro', 'annual');
        $this->assertNull($di->requested_plan_id, 'A DI shop must never be recorded against an FBR POS package');
        $this->assertNull($di->requested_billing_cycle, 'No package means no cycle either');
    }

    public function test_a_trial_package_is_ignored(): void
    {
        $fbr = $this->fbrSignup('Trial Mart', 'trialmart@example.com', 'FBR Trial');
        $this->assertNull($fbr->requested_plan_id);

        $di = $this->diSignup('Trial Traders', 'trialtraders@example.com', 'DI Trial', 'annual');
        $this->assertNull($di->requested_plan_id);
    }

    public function test_a_tampered_or_unknown_package_is_ignored(): void
    {
        $fbr = $this->fbrSignup('Hacky Mart', 'hacky@example.com', "Pro' OR 1=1 --");
        $this->assertNull($fbr->requested_plan_id);

        $di = $this->diSignup('Ghost Traders', 'ghost@example.com', 'Package That Does Not Exist', 'annual');
        $this->assertNull($di->requested_plan_id);
    }

    // ── The signup pages carry the pick into the form ─────────────────

    public function test_signup_pages_carry_the_pick_into_hidden_fields(): void
    {
        $this->get('/register?plan=Business&cycle=quarterly')
            ->assertOk()
            ->assertSee('name="requested_plan" value="Business"', false)
            ->assertSee('name="requested_billing_cycle" value="quarterly"', false);

        $this->get('/fbr-pos/register?plan=Pro')
            ->assertOk()
            ->assertSee('name="requested_plan" value="Pro"', false);

        // Nothing picked → no hidden fields at all.
        $this->get('/register')->assertOk()->assertDontSee('name="requested_plan"', false);
        $this->get('/fbr-pos/register')->assertOk()->assertDontSee('name="requested_plan"', false);

        // A trial / wrong-product name in the link is not echoed back either.
        $this->get('/register?plan=DI+Trial')->assertOk()->assertDontSee('name="requested_plan"', false);
        $this->get('/fbr-pos/register?plan=POS+Basic')->assertOk()->assertDontSee('name="requested_plan"', false);
    }

    public function test_pos_signup_page_carries_only_allow_listed_addons_into_the_form(): void
    {
        $this->get('/pos/register?addons[]=delivery_riders&addons[]=not-a-real-feature&addon_cycle=quarterly')
            ->assertOk()
            ->assertSee('name="requested_addons[]" value="delivery_riders"', false)
            ->assertDontSee('name="requested_addons[]" value="not-a-real-feature"', false)
            ->assertSee('name="requested_addon_cycle" value="quarterly"', false);
    }

    public function test_pos_signup_remembers_the_valid_addon_quote_for_authenticated_billing(): void
    {
        $this->posSignup(
            'Addon Selection Mart',
            'addon-selection@example.com',
            ['caller_id', 'qr_menu'],
            'quarterly'
        );

        $selection = session(\App\Services\PosAddonService::SIGNUP_SESSION_KEY);
        $this->assertSame(['caller_id', 'qr_menu'], $selection['codes']);
        $this->assertSame('quarterly', $selection['cycle']);
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

    public function test_approval_activates_a_di_package_on_the_cycle_the_visitor_picked(): void
    {
        $plan = $this->plan('Business', 'di');
        $company = $this->pendingCompany('di', $plan, 'quarterly');

        $sub = SubscriptionAssignmentService::assignRequestedPlanOnApproval($company);

        $this->assertNotNull($sub);
        $this->assertSame($plan->id, (int) $sub->pricing_plan_id);
        $this->assertSame('quarterly', $sub->billing_cycle);
        // Rs 3,000/month × 3 months − 1% cycle discount.
        $this->assertSame(8910.0, (float) $sub->final_price);
        $this->assertSame(3, (int) $sub->start_date->diffInMonths($sub->end_date), 'Expiry must match the cycle charged');
    }

    public function test_approval_activates_a_di_package_monthly_when_no_cycle_was_stored(): void
    {
        $plan = $this->plan('Business', 'di');
        $company = $this->pendingCompany('di', $plan, null);

        $sub = SubscriptionAssignmentService::assignRequestedPlanOnApproval($company);

        $this->assertSame('monthly', $sub->billing_cycle);
        $this->assertSame(3000.0, (float) $sub->final_price);
        $this->assertSame(1, (int) $sub->start_date->diffInMonths($sub->end_date));
    }

    public function test_approval_activates_an_fbr_package_for_a_full_year(): void
    {
        $plan = $this->plan('Pro', 'fbrpos');
        $company = $this->pendingCompany('fbrpos', $plan, 'annual');

        $sub = SubscriptionAssignmentService::assignRequestedPlanOnApproval($company);

        $this->assertSame('annual', $sub->billing_cycle);
        // Rs 3,000/month × 12 − 6%.
        $this->assertSame(33840.0, (float) $sub->final_price);
        $this->assertSame(12, (int) $sub->start_date->diffInMonths($sub->end_date));
    }

    public function test_an_fbr_package_can_never_be_charged_on_a_monthly_cycle(): void
    {
        // Even if a stray cycle were somehow stored, FBR POS stays yearly.
        $plan = $this->plan('Pro', 'fbrpos');
        $company = $this->pendingCompany('fbrpos', $plan, 'monthly');

        $sub = SubscriptionAssignmentService::assignRequestedPlanOnApproval($company);

        $this->assertSame('annual', $sub->billing_cycle);
        $this->assertSame(33840.0, (float) $sub->final_price);
    }

    public function test_pra_pos_approval_is_unchanged(): void
    {
        $plan = $this->plan('POS Basic', 'pos');
        $company = $this->pendingCompany('pos', $plan, 'monthly');

        $sub = SubscriptionAssignmentService::assignRequestedPlanOnApproval($company);

        $this->assertSame('annual', $sub->billing_cycle, 'PRA POS approval must still be a full year');
        $this->assertSame(30000.0, (float) $sub->final_price);
        $this->assertSame(12, (int) $sub->start_date->diffInMonths($sub->end_date));
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
        $this->assertSame(8910.0, $summary['price']);
        $this->assertStringContainsString('Rs 8,910 every 3 months', $summary['badge']);
        $this->assertStringContainsString('3 months', $summary['note']);

        $fbr = $this->pendingCompany('fbrpos', $this->plan('Pro', 'fbrpos'), null);
        $fbrSummary = RequestedPackageService::pendingSummary($fbr);
        $this->assertStringContainsString('Rs 33,840 / year', $fbrSummary['badge']);
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
