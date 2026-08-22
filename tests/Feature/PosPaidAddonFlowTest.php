<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PosAddon;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PosAddonPricingService;
use App\Services\PosAddonService;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PAID FEATURE ADD-ONS — request → approval → gate (owner approved, Aug 2026)
 *
 * Three optional PRA POS features (WhatsApp Bill, Rider Live Tracking and
 * Caller ID) can be bought on top of a package instead of upgrading. Delivery
 * Riders and QR Menu are included from Business upward; Staff Attendance is
 * included from Pro upward; Custom Access is included from Business upward.
 *
 * The regressions that would be expensive and silent:
 *
 *   1. An add-on approval slipping into the PACKAGE approval path — that path
 *      deactivates the live subscription and creates a fresh one, which would
 *      reset a paying shop's expiry for a payment about a single feature.
 *   2. An add-on proof leaking into a query that assumes every payment_proofs
 *      row is a package payment (the one-pending guard hiding the renewal form,
 *      the instant-access grant unlocking a locked company for free).
 *   3. A SECOND entitlement path. Every feature must stay behind the one gate
 *      the whole app already calls (PosFeatureService::planAllows) — an add-on
 *      may only ever ADD access, never bypass or remove the package answer.
 *   4. Selling a shop something it already owns, or an approval activating a
 *      feature the shop never asked (and paid) for.
 *   5. An expired add-on still opening a feature after the package rolls over.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosPaidAddonFlowTest.php --testdox
 */
class PosPaidAddonFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // Every hasTable/hasColumn memo in this lane is process-wide — an
        // earlier suite's schema must never decide this one's answers.
        foreach ([[PaymentProof::class, 'kindColumn'], [PaymentProof::class, 'addonCodesColumn']] as [$class, $prop]) {
            $cache = new \ReflectionProperty($class, $prop);
            $cache->setAccessible(true);
            $cache->setValue(null, null);
        }
        PosAddonService::flushCache();
        PosFeatureService::flushGateCaches();
        $sales = new \ReflectionProperty(\App\Models\SaleCampaign::class, 'activeCache');
        $sales->setAccessible(true);
        $sales->setValue(null, null);

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->string('product_type')->nullable();
            $table->string('pos_integration_mode')->default('pra');
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('default_language', 5)->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('caller_id_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language', 5)->nullable();
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

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('price_quarterly', 10, 2)->nullable();
            $table->integer('invoice_limit')->nullable();
            $table->integer('user_limit')->nullable();
            $table->integer('branch_limit')->nullable();
            // Package and add-on gate columns used by the entitlement service.
            foreach (['riders_enabled', 'qr_menu_enabled', 'whatsapp_enabled', 'hazri_enabled',
                'rider_tracking_enabled', 'caller_id_enabled', 'custom_access_enabled'] as $col) {
                $table->boolean($col)->default(false);
            }
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('billing_cycle')->default('annual');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('final_price', 10, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->string('request_type')->default('subscription');
            $table->unsignedInteger('extra_branch_qty')->nullable();
            $table->text('addon_codes')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->date('payment_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamp('auto_access_until')->nullable();
            $table->timestamp('file_pruned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_addons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('addon_code', 40);
            $table->boolean('active')->default(true);
            $table->string('billing_cycle', 20)->default('annual');
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedBigInteger('payment_proof_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'addon_code']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('scope')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->boolean('read')->default(false);
            $table->text('metadata')->nullable();
            $table->timestamps();
        });

        Mail::fake();
        Storage::fake('local');
    }

    // ─── fixtures ────────────────────────────────────────────────────────

    private function makePlan(string $name = 'Business', array $gates = [], array $attrs = []): PricingPlan
    {
        $plan = PricingPlan::create(array_merge([
            'name' => $name,
            'product_type' => 'pos',
            'is_trial' => false,
            'price' => 34999,
            'price_quarterly' => 9999,
        ], $attrs));

        // Gate columns are not mass-assignable — write them the way the admin
        // panel does, or Eloquent drops them and every gate silently reads false.
        if ($gates) {
            PricingPlan::where('id', $plan->id)->update($gates);
            $plan = $plan->fresh();
        }

        return $plan;
    }

    private function makeShop(PricingPlan $plan, array $companyAttrs = [], array $subAttrs = []): Company
    {
        $company = Company::create(array_merge([
            'name' => 'Addon Traders',
            'email' => 'owner' . uniqid() . '@shop.test',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
        ], $companyAttrs));

        Subscription::create(array_merge([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual',
            'final_price' => $plan->price,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'active' => true,
            'override_type' => 'none',
        ], $subAttrs));

        PosAddonService::flushCache();
        PosFeatureService::flushGateCaches();

        return $company->fresh();
    }

    private function posUser(Company $company, array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Shop Owner',
            'email' => 'user' . uniqid() . '@shop.test',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'is_active' => true,
        ], $attrs));
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Approver',
            'email' => 'approver' . uniqid() . '@test.pk',
            'password' => Hash::make('secret-123'),
            'role' => 'super_admin',
        ]);
    }

    /** The shop's own purchase POST, exactly as the billing page sends it. */
    private function buy(Company $company, array $codes, string $cycle = 'annual', array $extra = [])
    {
        return $this->actingAs($this->posUser($company), 'pos')
            ->post('/pos/payment-proof', array_merge([
                'request_type' => 'pos_addon',
                'addon_codes' => $codes,
                'addon_cycle' => $cycle,
                'proof' => UploadedFile::fake()->image('addon.jpg'),
            ], $extra));
    }

    private function approve(PaymentProof $proof, array $payload = [])
    {
        return $this->actingAs($this->admin(), 'admin')
            ->post(route('saas.admin.payment-proofs.approve', $proof->id), $payload);
    }

    // ─── 1. who may buy ──────────────────────────────────────────────────

    public function test_starter_shop_cannot_buy_addons_and_is_told_to_upgrade(): void
    {
        $company = $this->makeShop($this->makePlan('Starter'));

        $eligibility = PosAddonService::purchaseEligibility($company);
        $this->assertFalse($eligibility['allowed']);
        $this->assertSame('pos.addons_upgrade_required', $eligibility['reason_key']);

        $this->buy($company, ['caller_id']);
        $this->assertSame(0, PaymentProof::count(), 'Starter must not be able to file an add-on request');
    }

    public function test_trial_shop_cannot_buy_addons(): void
    {
        $trial = $this->makePlan('POS Trial', [], ['is_trial' => true, 'price' => 0]);
        $company = $this->makeShop($trial, [], ['trial_ends_at' => now()->addDays(10)]);

        $this->assertFalse(PosAddonService::purchaseEligibility($company)['allowed']);
        $this->buy($company, ['caller_id']);
        $this->assertSame(0, PaymentProof::count(), 'A trial shop must upgrade before buying features');
    }

    public function test_fbr_shop_cannot_buy_pra_addons(): void
    {
        $company = $this->makeShop($this->makePlan('Business'), ['pos_integration_mode' => 'fbr']);

        $eligibility = PosAddonService::purchaseEligibility($company);
        $this->assertFalse($eligibility['allowed']);
        $this->assertSame('pos.addons_not_available', $eligibility['reason_key']);
    }

    public function test_business_shop_may_buy(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $this->assertTrue(PosAddonService::purchaseEligibility($company)['allowed']);
    }

    // ─── 2. never sell what the shop already owns ────────────────────────

    public function test_package_included_features_are_not_in_the_addon_catalogue(): void
    {
        foreach (['delivery_riders', 'qr_menu', 'staff_attendance'] as $code) {
            $this->assertArrayNotHasKey($code, PosAddonPricingService::ADDONS);
        }
        $this->assertSame(
            ['whatsapp_bill', 'rider_tracking', 'caller_id'],
            array_keys(PosAddonPricingService::ADDONS)
        );
    }

    public function test_server_rejects_a_retired_addon_code_from_a_tampered_post(): void
    {
        $company = $this->makeShop($this->makePlan('Pro', ['riders_enabled' => true]));

        // The form is only a hint — a retired code must make a tampered request
        // fail instead of charging an ambiguous subset.
        $response = $this->buy($company, ['delivery_riders', 'caller_id']);

        $response->assertSessionHasErrors();
        $this->assertSame(0, PaymentProof::count());
    }

    public function test_request_with_only_already_owned_codes_is_refused(): void
    {
        $company = $this->makeShop($this->makePlan('Pro', ['riders_enabled' => true]));

        $this->buy($company, ['delivery_riders']);
        $this->assertSame(0, PaymentProof::count());
    }

    public function test_only_the_three_optional_features_remain_purchasable(): void
    {
        $this->assertArrayNotHasKey('custom_access', PosAddonPricingService::ADDONS);
        $gates = array_column(PosAddonPricingService::ADDONS, 'gate');
        $this->assertNotContains('custom_access_enabled', $gates,
            'Custom Access is included from Business upward — it must never be sold as an add-on');
        foreach (['riders_enabled', 'qr_menu_enabled', 'hazri_enabled'] as $gate) {
            $this->assertNotContains($gate, $gates);
        }
        $this->assertCount(3, PosAddonPricingService::ADDONS);
    }

    // ─── 3. the request row ──────────────────────────────────────────────

    public function test_purchase_creates_a_pos_addon_proof_with_the_server_quote(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));

        $this->buy($company, ['caller_id', 'whatsapp_bill'], 'quarterly');

        $proof = PaymentProof::first();
        $this->assertNotNull($proof);
        $this->assertSame('pos_addon', $proof->request_type);
        $this->assertTrue($proof->isPosAddon());
        $this->assertFalse($proof->isExtraBranch());
        $this->assertSame('quarterly', $proof->billing_cycle);
        $this->assertSame('quarterly', PosAddonService::cycleForProof($proof));
        $this->assertEqualsCanonicalizing(['caller_id', 'whatsapp_bill'], $proof->addonCodeList());

        $expected = PosAddonPricingService::price('caller_id', 'quarterly')
            + PosAddonPricingService::price('whatsapp_bill', 'quarterly');
        $this->assertEquals($expected, (float) $proof->amount);
    }

    public function test_a_forged_browser_amount_cannot_change_the_server_quote(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));

        $this->buy($company, ['caller_id'], 'annual', ['amount' => 1]);

        $proof = PaymentProof::firstOrFail();
        $this->assertEquals(
            PosAddonPricingService::price('caller_id', 'annual'),
            (float) $proof->amount
        );
    }

    public function test_addon_request_never_grants_instant_access_or_touches_the_subscription(): void
    {
        $company = $this->makeShop($this->makePlan('Business'), ['company_status' => 'locked']);
        $before = Subscription::where('company_id', $company->id)->first();

        $this->buy($company, ['caller_id']);

        $proof = PaymentProof::first();
        $this->assertNull($proof->auto_access_until, 'A feature request must never unlock a locked company');

        $after = Subscription::where('company_id', $company->id)->first();
        $this->assertSame((string) $before->end_date, (string) $after->end_date);
        $this->assertTrue((bool) $after->active);
        $this->assertSame('locked', $company->fresh()->company_status);
    }

    public function test_second_pending_request_is_refused(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));

        $this->buy($company, ['caller_id']);
        $this->buy($company, ['whatsapp_bill']);

        $this->assertSame(1, PaymentProof::count(), 'Only one add-on request may sit in the queue');
    }

    public function test_pending_addon_request_does_not_hide_the_renewal_lane(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $this->buy($company, ['caller_id']);

        // The renewal form reads the subscription lane — a feature request must
        // be invisible to it (and vice-versa).
        $this->assertFalse(
            PaymentProof::subscriptionKind()->where('company_id', $company->id)->where('status', 'pending')->exists(),
            'An add-on proof leaked into the package lane — it would hide the renewal form'
        );
        $this->assertTrue(
            PaymentProof::posAddonKind()->where('company_id', $company->id)->where('status', 'pending')->exists()
        );
    }

    public function test_a_pending_code_is_not_offered_again(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $this->buy($company, ['caller_id']);

        PosAddonService::flushCache();
        $this->assertContains('caller_id', PosAddonService::pendingCodes($company->fresh()));
        $this->assertNotContains('caller_id', PosAddonService::purchasableCodes($company->fresh()));
    }

    // ─── 4. approval ─────────────────────────────────────────────────────

    public function test_approval_activates_only_the_requested_features(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $this->buy($company, ['caller_id', 'whatsapp_bill']);
        $proof = PaymentProof::first();

        $this->approve($proof);

        $codes = PosAddon::where('company_id', $company->id)->pluck('addon_code')->all();
        $this->assertEqualsCanonicalizing(['caller_id', 'whatsapp_bill'], $codes);
        $this->assertSame('verified', $proof->fresh()->status);
    }

    public function test_approval_never_creates_a_new_subscription_or_moves_the_expiry(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $before = Subscription::where('company_id', $company->id)->first();

        $this->buy($company, ['caller_id']);
        $this->approve(PaymentProof::first());

        $subs = Subscription::where('company_id', $company->id)->get();
        $this->assertCount(1, $subs, 'An add-on approval must never open a fresh subscription row');
        $this->assertSame((string) $before->end_date, (string) $subs->first()->end_date);
        $this->assertEquals($before->final_price, $subs->first()->final_price);
        $this->assertTrue((bool) $subs->first()->active);
    }

    public function test_addon_expiry_rides_the_current_package_expiry(): void
    {
        $plan = $this->makePlan('Business');
        $company = $this->makeShop($plan, [], ['end_date' => now()->addDays(40)->toDateString()]);

        $this->buy($company, ['caller_id']);
        $this->approve(PaymentProof::first());

        $addon = PosAddon::where('company_id', $company->id)->first();
        $this->assertSame(now()->addDays(40)->toDateString(), $addon->ends_at->toDateString());
    }

    public function test_admin_may_narrow_the_list_but_never_widen_it(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $this->buy($company, ['caller_id', 'whatsapp_bill']);
        $proof = PaymentProof::first();

        // Admin unticks one and tries to slip in a third the shop never paid for.
        $this->approve($proof, ['addon_codes' => ['caller_id', 'rider_tracking']]);

        $codes = PosAddon::where('company_id', $company->id)->pluck('addon_code')->all();
        $this->assertSame(['caller_id'], $codes, 'Approval must only ever activate features the shop requested');
    }

    public function test_approving_the_same_proof_twice_does_not_double_charge(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $this->buy($company, ['caller_id']);
        $proof = PaymentProof::first();

        $this->approve($proof);
        $this->approve($proof->fresh());

        $this->assertSame(1, PosAddon::where('company_id', $company->id)->count());
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'POS feature add-on approved')->count());
    }

    public function test_rejection_switches_nothing_on(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $this->buy($company, ['caller_id']);
        $proof = PaymentProof::first();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('saas.admin.payment-proofs.reject', $proof->id), ['reject_reason' => 'Amount short']);

        $this->assertSame('rejected', $proof->fresh()->status);
        $this->assertSame(0, PosAddon::where('company_id', $company->id)->count());
    }

    // ─── 5. the gate: one path, add-only ─────────────────────────────────

    public function test_an_approved_addon_opens_the_feature_through_the_normal_gate(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));

        // Package says no.
        $this->assertFalse(PosFeatureService::planAllows($company, 'caller_id_enabled'));

        $this->buy($company, ['caller_id']);
        $this->approve(PaymentProof::first());

        PosFeatureService::flushGateCaches();
        $this->assertTrue(PosFeatureService::planAllows($company->fresh(), 'caller_id_enabled'),
            'A bought feature must resolve through the SAME gate the whole app calls');
    }

    public function test_buying_one_feature_does_not_open_the_others(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $this->buy($company, ['caller_id']);
        $this->approve(PaymentProof::first());

        PosFeatureService::flushGateCaches();
        $fresh = $company->fresh();
        foreach (['whatsapp_enabled', 'rider_tracking_enabled'] as $gate) {
            $this->assertFalse(PosFeatureService::planAllows($fresh, $gate), "{$gate} must stay shut");
        }
    }

    public function test_an_expired_addon_stops_granting_access(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        PosAddon::create([
            'company_id' => $company->id,
            'addon_code' => 'caller_id',
            'active' => true,
            'billing_cycle' => 'annual',
            'amount' => 12000,
            'starts_at' => now()->subYear()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
        ]);

        PosAddonService::flushCache();
        PosFeatureService::flushGateCaches();
        $this->assertFalse(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_a_deactivated_addon_stops_granting_access(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        PosAddon::create([
            'company_id' => $company->id,
            'addon_code' => 'caller_id',
            'active' => false,
            'billing_cycle' => 'annual',
            'amount' => 12000,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
        ]);

        PosAddonService::flushCache();
        PosFeatureService::flushGateCaches();
        $this->assertFalse(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_one_shops_addon_never_leaks_to_another(): void
    {
        $plan = $this->makePlan('Business');
        $buyer = $this->makeShop($plan);
        $neighbour = $this->makeShop($plan, ['name' => 'Neighbour Store']);

        PosAddon::create([
            'company_id' => $buyer->id,
            'addon_code' => 'caller_id',
            'active' => true,
            'billing_cycle' => 'annual',
            'amount' => 12000,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
        ]);

        PosAddonService::flushCache();
        PosFeatureService::flushGateCaches();
        $this->assertTrue(PosFeatureService::planAllows($buyer, 'caller_id_enabled'));
        $this->assertFalse(PosFeatureService::planAllows($neighbour, 'caller_id_enabled'));
    }

    public function test_caller_id_screen_flag_needs_both_the_switch_and_the_gate(): void
    {
        // Switch ON but plan says no: the sale screen must NOT bake it live, or a
        // downgraded shop keeps a dead button and a poller earning only 403s.
        // (The column is set the way the app sets it — a query update, not mass
        // assignment; caller_id_enabled is deliberately not $fillable.)
        $company = $this->makeShop($this->makePlan('Business'));
        Company::where('id', $company->id)->update(['caller_id_enabled' => true]);
        PosFeatureService::flushGateCaches();
        $this->assertFalse(PosFeatureService::callerIdLive($company->fresh()));

        // Buy it — now both halves are true.
        $this->buy($company, ['caller_id']);
        $this->approve(PaymentProof::first());
        PosFeatureService::flushGateCaches();
        $this->assertTrue(PosFeatureService::callerIdLive($company->fresh()));

        // Plan-granted but the shop's own switch is off: still not live.
        $off = $this->makeShop($this->makePlan('Pro', ['caller_id_enabled' => true]), ['name' => 'Switch Off Store']);
        PosFeatureService::flushGateCaches();
        $this->assertTrue(PosFeatureService::planAllows($off, 'caller_id_enabled'));
        $this->assertFalse(PosFeatureService::callerIdLive($off));
    }

    // ─── 6. who may spend the shop's money ───────────────────────────────

    public function test_a_cashier_cannot_file_a_purchase_request(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $cashier = $this->posUser($company, ['pos_role' => 'pos_cashier', 'role' => 'user']);

        $this->actingAs($cashier, 'pos')
            ->post('/pos/payment-proof', [
                'request_type' => 'pos_addon',
                'addon_codes' => ['caller_id'],
                'addon_cycle' => 'annual',
                'proof' => UploadedFile::fake()->image('addon.jpg'),
            ])
            ->assertForbidden();

        $this->assertSame(0, PaymentProof::count(), 'A cashier must never be able to commit the shop to a payment');
    }

    public function test_shop_cannot_submit_a_new_subscription_proof_for_retired_pro_max(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $retired = $this->makePlan('Pro Max');

        $this->actingAs($this->posUser($company), 'pos')
            ->post('/pos/payment-proof', [
                'pricing_plan_id' => $retired->id,
                'billing_cycle' => 'annual',
                'proof' => UploadedFile::fake()->image('retired-plan.jpg'),
            ])
            ->assertSessionHasErrors('pricing_plan_id');

        $this->assertSame(0, PaymentProof::count());
    }

    public function test_the_billing_page_hides_the_purchase_box_from_a_cashier(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $cashier = $this->posUser($company, ['pos_role' => 'pos_cashier', 'role' => 'user']);

        $html = $this->actingAs($cashier, 'pos')->get('/pos/billing')->getContent();
        $this->assertStringNotContainsString('value="pos_addon"', $html);
    }

    public function test_billing_preselects_only_remembered_codes_that_are_still_purchasable(): void
    {
        $plan = $this->makePlan('Business', ['riders_enabled' => true]);
        $company = $this->makeShop($plan);
        $owner = $this->posUser($company);
        $remembered = [
            'codes' => ['delivery_riders', 'caller_id'],
            'cycle' => 'quarterly',
        ];

        $response = $this->withSession([PosAddonService::SIGNUP_SESSION_KEY => $remembered])
            ->actingAs($owner, 'pos')
            ->get('/pos/billing');

        $response->assertOk();
        $addons = $response->viewData('addons');
        $this->assertSame(['caller_id'], $addons['preselected']);
        $this->assertSame('quarterly', $addons['preselected_cycle']);
    }

    public function test_successful_addon_proof_clears_the_signup_selection(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $remembered = [
            'codes' => ['caller_id'],
            'cycle' => 'annual',
        ];

        $this->withSession([PosAddonService::SIGNUP_SESSION_KEY => $remembered]);
        $this->buy($company, ['caller_id'])->assertSessionHasNoErrors();

        $this->assertFalse(session()->has(PosAddonService::SIGNUP_SESSION_KEY));
        $this->assertSame(1, PaymentProof::where('request_type', 'pos_addon')->count());
    }

    public function test_failed_addon_proof_keeps_the_signup_selection_for_retry(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));
        $owner = $this->posUser($company);
        $remembered = [
            'codes' => ['caller_id'],
            'cycle' => 'annual',
        ];

        $this->withSession([PosAddonService::SIGNUP_SESSION_KEY => $remembered])
            ->actingAs($owner, 'pos')
            ->post('/pos/payment-proof', [
                'request_type' => 'pos_addon',
                'addon_codes' => ['caller_id'],
                'addon_cycle' => 'annual',
                // Deliberately no proof upload.
            ])
            ->assertSessionHasErrors('proof');

        $this->assertSame($remembered, session(PosAddonService::SIGNUP_SESSION_KEY));
        $this->assertSame(0, PaymentProof::count());
    }

    // ─── 7. the queue is not a time machine ──────────────────────────────

    public function test_approval_is_refused_if_the_shop_downgraded_while_waiting(): void
    {
        $business = $this->makePlan('Business');
        $starter = $this->makePlan('Starter');
        $company = $this->makeShop($business);

        $this->buy($company, ['caller_id']);
        $proof = PaymentProof::first();

        // Days pass; the shop drops to Starter before an admin looks at it.
        Subscription::where('company_id', $company->id)->update(['pricing_plan_id' => $starter->id]);

        $this->approve($proof);

        $this->assertSame(0, PosAddon::where('company_id', $company->id)->count(),
            'Approving a stale request must not grant a feature the shop is no longer entitled to');
        $this->assertSame('pending', $proof->fresh()->status);
    }

    public function test_approval_is_refused_if_the_package_lapsed_while_waiting(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));

        $this->buy($company, ['caller_id']);
        $proof = PaymentProof::first();

        Subscription::where('company_id', $company->id)->update(['active' => false]);

        $this->approve($proof);

        $this->assertSame(0, PosAddon::where('company_id', $company->id)->count());
        $this->assertSame('pending', $proof->fresh()->status,
            'A refused approval must leave the proof in the queue, not silently verify it');
    }

    public function test_activate_refuses_to_invent_an_expiry(): void
    {
        $company = $this->makeShop($this->makePlan('Business'));

        $this->expectException(\InvalidArgumentException::class);
        PosAddonService::activate($company, ['caller_id'], 'annual', 12000, 1, null);
    }

    // ─── 8. pricing ──────────────────────────────────────────────────────

    public function test_every_addon_has_both_admin_editable_rates(): void
    {
        foreach (array_keys(PosAddonPricingService::ADDONS) as $code) {
            foreach (['annual', 'quarterly'] as $cycle) {
                $this->assertGreaterThan(0, PosAddonPricingService::price($code, $cycle),
                    "{$code} has no {$cycle} rate — the billing page would quote zero");
            }
        }
    }

    public function test_an_admin_rate_change_moves_the_quote_and_the_charge(): void
    {
        PosAddonPricingService::save(['caller_id' => ['annual' => 20000]]);

        $company = $this->makeShop($this->makePlan('Business'));
        $this->buy($company, ['caller_id']);

        $proof = PaymentProof::first();
        $this->assertEquals(20000, (float) $proof->amount);

        $this->approve($proof);
        $this->assertEquals(20000, (float) PosAddon::where('company_id', $company->id)->value('amount'));
    }

    public function test_quote_ignores_an_unknown_code(): void
    {
        $quote = PosAddonService::quote(['caller_id', 'free_ferrari'], 'annual');
        $this->assertSame(['caller_id'], $quote['codes']);
        $this->assertEquals(PosAddonPricingService::price('caller_id', 'annual'), $quote['total']);
    }
}
