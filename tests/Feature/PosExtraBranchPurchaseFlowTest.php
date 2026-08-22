<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AgentCommissionService;
use App\Services\BranchAddonService;
use App\Services\PlanLimitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PAID EXTRA-BRANCH PURCHASE — request → approval → gate (owner approved, Aug 2026)
 *
 * PosExtraBranchSlotScopeTest locks the ENFORCEMENT half (which companies a
 * stored slot count may widen) and PosRenewalBranchSlotReviewTest locks the
 * RENEWAL half. This file covers the half that moves real money and was only
 * ever walked through by hand: the shop's request and the admin's approval.
 *
 * The regressions that would be expensive and silent:
 *
 *   1. An add-on approval slipping into the PACKAGE approval path. That path
 *      deactivates the live subscription row and creates a fresh one — which
 *      would destroy running admin grants and reset the package expiry, for a
 *      payment that has nothing to do with the package.
 *   2. An add-on proof leaking into a query that assumes every payment_proofs
 *      row is a package payment: the agent commission ledger (inventing a
 *      commission, or worse, demoting a real new sale to the renewal rate),
 *      the one-pending-proof guard, and the instant-access grant.
 *   3. The price quoted on the shop's screen drifting away from the number the
 *      request stores and the slots the approval actually credits.
 *   4. Shops that must not be able to buy at all (trial, admin branch override)
 *      being allowed to send money for slots the gate would ignore.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosExtraBranchPurchaseFlowTest.php --testdox
 */
class PosExtraBranchPurchaseFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // slotsColumnExists()/kindColumnExists() memoise per process — an
        // earlier test's schema must not decide this one's answer.
        foreach ([[BranchAddonService::class, 'slotsColumn'], [PaymentProof::class, 'kindColumn']] as [$class, $prop]) {
            $cache = new \ReflectionProperty($class, $prop);
            $cache->setAccessible(true);
            $cache->setValue(null, null);
        }
        // Same for the sale-campaign memo: a campaign cached by an earlier
        // suite would silently discount every package price here.
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
            $table->integer('branch_limit_override')->nullable();
            $table->unsignedInteger('extra_branch_slots')->default(0);
            $table->unsignedBigInteger('agent_id')->nullable();
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

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate_new', 5, 2)->default(0);
            $table->decimal('rate_renewal', 5, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('reactivated_at')->nullable();
            $table->text('termination_windows')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('company_name')->nullable();
            $table->unsignedBigInteger('payment_proof_id')->nullable();
            $table->string('type', 20);
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('rate_percent', 5, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('period_month');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
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

    private function makePlan(string $productType = 'pos', float $price = 24999, ?int $branches = 2, array $attrs = []): PricingPlan
    {
        return PricingPlan::create(array_merge([
            'name' => $productType === 'pos' ? 'Business' : strtoupper($productType) . ' Pro',
            'product_type' => $productType,
            'is_trial' => false,
            'price' => $price,
            'branch_limit' => $branches,
        ], $attrs));
    }

    /** A shop on a live PRA POS package with a full year still to run. */
    private function makeShop(PricingPlan $plan, int $branches = 0, int $slots = 0, array $companyAttrs = [], array $subAttrs = []): Company
    {
        $company = Company::create(array_merge([
            'name' => 'Branch Traders',
            'email' => 'owner' . uniqid() . '@shop.test',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
            'extra_branch_slots' => $slots,
        ], $companyAttrs));

        Subscription::create(array_merge([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual',
            'discount_percent' => 6,
            'final_price' => $plan->price,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'active' => true,
            'override_type' => 'none',
        ], $subAttrs));

        $this->addBranches($company, $branches);

        return $company->fresh();
    }

    private function addBranches(Company $company, int $count): void
    {
        $existing = DB::table('branches')->where('company_id', $company->id)->count();
        for ($i = 1; $i <= $count; $i++) {
            DB::table('branches')->insert([
                'company_id' => $company->id,
                'name' => 'Branch ' . ($existing + $i),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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

    private function linkAgent(Company $company): Agent
    {
        $agent = Agent::create([
            'name' => 'Intro Agent',
            'rate_new' => 15,
            'rate_renewal' => 7.5,
            'status' => 'active',
        ]);
        $company->update(['agent_id' => $agent->id]);

        return $agent;
    }

    private function activeSub(Company $company): ?Subscription
    {
        return Subscription::where('company_id', $company->id)
            ->where('active', true)->orderByDesc('id')->first();
    }

    /** A pending add-on request exactly as the shop's submission writes it. */
    private function extraBranchProof(Company $company, int $qty, float $amount, array $attrs = []): PaymentProof
    {
        return PaymentProof::create(array_merge([
            'company_id' => $company->id,
            'pricing_plan_id' => $this->activeSub($company)?->pricing_plan_id,
            'billing_cycle' => null,
            'request_type' => 'extra_branch',
            'extra_branch_qty' => $qty,
            'amount' => $amount,
            'proof_path' => 'payment-proofs/addon.jpg',
            'status' => 'pending',
        ], $attrs));
    }

    private function packageProof(Company $company, PricingPlan $plan, float $amount, array $attrs = []): PaymentProof
    {
        return PaymentProof::create(array_merge([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual',
            'request_type' => 'subscription',
            'amount' => $amount,
            'proof_path' => 'payment-proofs/renewal.jpg',
            'status' => 'pending',
        ], $attrs));
    }

    private function approve(PaymentProof $proof, array $payload = [])
    {
        return $this->actingAs($this->admin(), 'admin')
            ->post(route('saas.admin.payment-proofs.approve', $proof->id), $payload);
    }

    private function approvePackage(PaymentProof $proof, PricingPlan $plan, array $payload = [])
    {
        return $this->approve($proof, array_merge([
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual',
        ], $payload));
    }

    /** The shop's own extra-branch request, through the real POS panel route. */
    private function submitAddonRequest(User $user, int $qty, array $extra = [])
    {
        return $this->actingAs($user, 'pos')->post('/pos/payment-proof', array_merge([
            'request_type' => 'extra_branch',
            'extra_branch_qty' => $qty,
            'payment_method' => 'bank',
            'proof' => UploadedFile::fake()->create('addon.jpg', 20, 'image/jpeg'),
        ], $extra));
    }

    /** An ordinary package/renewal proof, through the same POS panel route. */
    private function submitPackageProof(User $user, PricingPlan $plan, float $amount, array $extra = [])
    {
        return $this->actingAs($user, 'pos')->post('/pos/payment-proof', array_merge([
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual',
            'amount' => $amount,
            'payment_method' => 'bank',
            'proof' => UploadedFile::fake()->create('renewal.jpg', 20, 'image/jpeg'),
        ], $extra));
    }

    // ─── 1. approval credits slots and touches NOTHING else ──────────────

    public function test_approving_an_extra_branch_request_credits_slots_and_leaves_the_subscription_row_untouched(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 2, subAttrs: [
            // A live admin grant rides ON the subscription row. The package
            // approval path deactivates that row and creates a new one, which
            // would silently throw the grant (and the expiry) away.
            'override_type' => 'temporary',
            'override_until' => now()->addDays(20),
            'override_granted_at' => now()->subDay(),
            'override_reason' => 'Owner grant',
            'override_by' => 7,
        ]);
        $sub = $this->activeSub($shop);
        $before = $sub->getAttributes();

        $proof = $this->extraBranchProof($shop, 2, 20000);

        $this->approve($proof)->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, (int) $shop->fresh()->extra_branch_slots, 'the slot count is the ONLY thing the add-on approval moves');
        $this->assertSame(1, Subscription::count(), 'the add-on path must never create a second subscription row');
        $this->assertSame(
            $before,
            Subscription::find($sub->id)->getAttributes(),
            'id, plan, cycle, price, expiry, active flag and the running grant must all survive untouched'
        );

        $proof->refresh();
        $this->assertSame('verified', $proof->status);
        $this->assertSame(2, (int) $proof->extra_branch_qty);
        $this->assertNull($proof->subscription_id, 'an add-on payment is not a subscription payment');
        $this->assertNull($proof->billing_cycle);
    }

    public function test_approving_adds_to_the_slots_the_shop_already_owns(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 2, slots: 2);

        $this->approve($this->extraBranchProof($shop, 3, 30000))->assertRedirect();

        $this->assertSame(5, (int) $shop->fresh()->extra_branch_slots, 'a purchase adds to the count, never replaces it');
    }

    public function test_the_admin_can_correct_the_quantity_and_only_that_many_slots_are_credited(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 2);
        $proof = $this->extraBranchProof($shop, 3, 30000);

        // Shop asked for 3 but only paid for 1.
        $this->approve($proof, ['extra_branch_qty' => 1])->assertRedirect();

        $this->assertSame(1, (int) $shop->fresh()->extra_branch_slots);
        $this->assertSame(1, (int) $proof->fresh()->extra_branch_qty, 'the credited quantity must be recorded on the proof');
    }

    public function test_an_extra_branch_proof_can_never_credit_slots_twice(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 2);
        $proof = $this->extraBranchProof($shop, 2, 20000);

        $this->approve($proof)->assertRedirect()->assertSessionHas('success');
        $this->approve($proof->fresh())->assertRedirect()->assertSessionHas('error');

        $this->assertSame(2, (int) $shop->fresh()->extra_branch_slots);
    }

    // ─── 2. the gate opens for exactly what was bought ───────────────────

    public function test_the_branch_gate_opens_for_exactly_the_branches_bought_and_closes_again(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);   // package includes 2 branches
        $shop = $this->makeShop($plan, branches: 2);

        $this->assertFalse(PlanLimitService::canAddBranch($shop->id)['allowed'], 'the package branches are already used up');

        $this->approve($this->extraBranchProof($shop, 2, 20000))->assertRedirect();

        $this->assertTrue(PlanLimitService::canAddBranch($shop->id)['allowed'], 'the first paid slot must open');
        $this->addBranches($shop, 1);
        $this->assertTrue(PlanLimitService::canAddBranch($shop->id)['allowed'], 'the second paid slot must open too');
        $this->addBranches($shop, 1);

        $verdict = PlanLimitService::canAddBranch($shop->id);
        $this->assertFalse($verdict['allowed'], 'the gate must close again once both paid slots are used');
        $this->assertSame(2, $verdict['included']);
        $this->assertSame(2, $verdict['slots']);
        $this->assertStringContainsString('4/4', $verdict['reason']);
    }

    // ─── 3. the add-on is not a package sale (commission ledger) ─────────

    public function test_an_approved_extra_branch_payment_never_creates_an_agent_commission_line(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 2);
        $agent = $this->linkAgent($shop);

        $this->approve($this->extraBranchProof($shop, 2, 20000))->assertRedirect();

        $this->assertSame(0, AgentCommission::count(), 'an add-on is not a package sale — no earn line, not even a skipped one');

        // The backfill safety net must not invent one either.
        AgentCommissionService::syncForAgent($agent->fresh());
        $this->assertSame(0, AgentCommission::count());
    }

    public function test_an_earlier_extra_branch_payment_never_demotes_a_real_new_sale_to_the_renewal_rate(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 2);
        $this->linkAgent($shop);

        // The add-on payment clears FIRST...
        $this->approve($this->extraBranchProof($shop, 1, 10000))->assertRedirect();

        // ...and the shop's FIRST package payment must still be a new sale.
        $this->approvePackage($this->packageProof($shop, $plan, 24999), $plan)->assertRedirect();

        $lines = AgentCommission::orderBy('id')->get();
        $this->assertCount(1, $lines, 'only the package payment earns');
        $this->assertSame('new', $lines[0]->type, "the add-on must not count as the company's earlier payment");
        $this->assertSame(3749.85, (float) $lines[0]->amount, '24,999 at the new-sale rate of 15%');

        // The next package payment is the renewal — that classification still works.
        $this->approvePackage($this->packageProof($shop, $plan, 24999), $plan)->assertRedirect();

        $lines = AgentCommission::orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('renewal', $lines[1]->type);
    }

    // ─── 4. who may buy at all ───────────────────────────────────────────

    public function test_a_shop_on_a_paid_package_can_submit_an_extra_branch_request(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 2);
        $user = $this->posUser($shop);
        $sub = $this->activeSub($shop);
        $before = $sub->getAttributes();

        $this->submitAddonRequest($user, 3)
            ->assertRedirect()
            ->assertSessionHas('payment_proof', 'submitted');

        $proof = PaymentProof::latest('id')->first();
        $this->assertNotNull($proof);
        $this->assertTrue($proof->isExtraBranch());
        $this->assertSame('pending', $proof->status);
        $this->assertSame(3, (int) $proof->extra_branch_qty);
        $this->assertSame(30000.0, (float) $proof->amount, '3 slots x Rs 10,000 for the full year left on the package');
        $this->assertNull($proof->billing_cycle, 'an add-on request carries no billing cycle');

        // A REQUEST changes nothing on its own.
        $this->assertSame(0, (int) $shop->fresh()->extra_branch_slots, 'slots move only on approval');
        $this->assertSame($before, Subscription::find($sub->id)->getAttributes(), 'the package must not move when a request is filed');
        $this->assertNull($proof->auto_access_until, 'an add-on request never grants instant temporary access');
    }

    public function test_a_trial_company_cannot_buy_extra_branch_slots(): void
    {
        $trial = $this->makePlan('pos', 0, 1, ['name' => 'Trial', 'is_trial' => true]);
        $shop = $this->makeShop($trial, branches: 1, subAttrs: ['trial_ends_at' => now()->addDays(7)]);
        $user = $this->posUser($shop);

        $this->submitAddonRequest($user, 2)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('pos.eb_reason_trial', BranchAddonService::purchaseEligibility($shop->fresh())['reason_key']);
        $this->assertSame(0, PaymentProof::count(), 'a trial shop must not even be able to file the request');
        $this->assertSame(0, (int) $shop->fresh()->extra_branch_slots);
    }

    public function test_a_company_whose_branch_limit_an_admin_set_by_hand_cannot_buy_slots(): void
    {
        $plan = $this->makePlan();
        // An admin override outranks package + slots, so buying one would be
        // money for a limit that never moves.
        $shop = $this->makeShop($plan, branches: 2, companyAttrs: ['branch_limit_override' => 9]);
        $user = $this->posUser($shop);

        $this->submitAddonRequest($user, 2)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('pos.eb_reason_admin_limit', BranchAddonService::purchaseEligibility($shop->fresh())['reason_key']);
        $this->assertSame(0, PaymentProof::count());
        $this->assertSame(0, (int) $shop->fresh()->extra_branch_slots);
    }

    // ─── 5. the two proof lanes never block or unlock each other ─────────

    public function test_a_pending_request_in_one_lane_never_blocks_the_other_lane(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 2);
        $user = $this->posUser($shop);

        $this->submitAddonRequest($user, 1)->assertSessionHas('payment_proof', 'submitted');
        $this->submitPackageProof($user, $plan, 24999)->assertSessionHas('payment_proof', 'submitted');

        $this->assertSame(1, PaymentProof::extraBranchKind()->count());
        $this->assertSame(1, PaymentProof::subscriptionKind()->count());

        // A SECOND request in either lane is still held back as already pending.
        $this->submitAddonRequest($user, 1)->assertSessionHas('payment_proof', 'pending');
        $this->submitPackageProof($user, $plan, 24999)->assertSessionHas('payment_proof', 'pending');

        $this->assertSame(2, PaymentProof::count());
    }

    public function test_a_rejected_extra_branch_request_never_blocks_instant_access_on_a_later_package_payment(): void
    {
        $plan = $this->makePlan();
        // Expired package = locked shop, exactly who instant access exists for.
        $shop = $this->makeShop($plan, branches: 1, subAttrs: [
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->subDays(3)->toDateString(),
        ]);
        $user = $this->posUser($shop);

        $this->extraBranchProof($shop, 1, 10000, ['status' => 'rejected', 'reject_reason' => 'Receipt unreadable']);

        $this->submitPackageProof($user, $plan, 24999)->assertRedirect();

        $proof = PaymentProof::subscriptionKind()->latest('id')->first();
        $this->assertNotNull($proof->auto_access_until, 'a rejected ADD-ON says nothing about whether a package payment is trustworthy');
        $this->assertSame('temporary', $this->activeSub($shop)->override_type);
    }

    public function test_a_rejected_package_payment_still_blocks_instant_access(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 1, subAttrs: [
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->subDays(3)->toDateString(),
        ]);
        $user = $this->posUser($shop);

        $this->packageProof($shop, $plan, 24999, ['status' => 'rejected', 'reject_reason' => 'Not received']);

        $this->submitPackageProof($user, $plan, 24999)->assertRedirect();

        $proof = PaymentProof::subscriptionKind()->where('status', 'pending')->latest('id')->first();
        $this->assertNotNull($proof);
        $this->assertNull($proof->auto_access_until, 'the prior-rejection safeguard must still bite in its own lane');
        $this->assertSame('none', $this->activeSub($shop)->override_type);
    }

    // ─── 6. one price, from the screen to the ledger ─────────────────────

    public function test_the_price_quoted_on_the_shops_screen_is_the_amount_the_request_records(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 2);
        $user = $this->posUser($shop);

        // What the Branches page shows for "3 extra branches".
        $quoted = (float) BranchAddonService::status($shop)['quotes'][3]['price'];
        $this->assertSame(30000.0, $quoted);

        $this->submitAddonRequest($user, 3);

        $proof = PaymentProof::latest('id')->first();
        $this->assertSame($quoted, (float) $proof->amount, 'the screen and the stored request must never disagree');

        $this->approve($proof)->assertRedirect();

        // And from now on the renewal charges that same money, every year.
        $review = BranchAddonService::renewalReview($shop->fresh(), $plan, 'annual', null);
        $this->assertSame(3, $review['slots']);
        $this->assertSame(30000.0, $review['addon_price'], 'the recurring charge must match the slots that were actually credited');
    }

    public function test_a_mid_year_purchase_is_quoted_pro_rata_and_the_request_records_that_same_amount(): void
    {
        $plan = $this->makePlan();
        $shop = $this->makeShop($plan, branches: 2, subAttrs: ['end_date' => now()->addMonths(4)->toDateString()]);
        $user = $this->posUser($shop);

        $quote = BranchAddonService::quote($shop, 2);
        $this->assertTrue($quote['prorated'], 'only part of the year is left on the package');
        $this->assertGreaterThan(0.0, $quote['price']);
        $this->assertLessThan(20000.0, $quote['price'], 'a part-year purchase must cost less than a full year');

        $this->submitAddonRequest($user, 2);

        $proof = PaymentProof::latest('id')->first();
        $this->assertSame($quote['price'], (float) $proof->amount);
        $this->assertSame($quote['price'], (float) BranchAddonService::status($shop)['quotes'][2]['price']);

        $this->approve($proof)->assertRedirect();
        $this->assertSame(2, (int) $shop->fresh()->extra_branch_slots, 'a pro-rata price still buys the full slots');
    }
}
