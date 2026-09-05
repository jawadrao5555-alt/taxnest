<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\BranchAddonService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RENEWAL vs PAID EXTRA-BRANCH SLOTS (owner approved, Aug 2026)
 *
 * Paid branch slots are a permanent counter on the company, and every renewal
 * quote already charges base package + (slots x 10,000). Nothing used to check
 * that the shop actually SENT that higher total: approving a short renewal left
 * the slots standing forever and nobody noticed. Locked here:
 *
 *   1. renewalReview() puts the expected total (base + slots) and the amount
 *      the shop claims side by side, and flags the shortfall — from the SAME
 *      pricing source the renewal is charged from.
 *   2. It only applies where the add-on is actually sold (both POS lines since
 *      23 Aug 2026, non-trial, slots > 0) — a DI renewal is untouched.
 *   3. Approving a renewal may KEEP or REDUCE the slot count, and the reduced
 *      count is what the new subscription is priced at.
 *   4. It may never drop below the branches the shop already has (the floor),
 *      and may never be used to ADD slots.
 *   5. Whatever the admin chooses lands in the audit trail with the same
 *      before/after shape as the manual slot edit, plus the renewal period.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosRenewalBranchSlotReviewTest.php --testdox
 */
class PosRenewalBranchSlotReviewTest extends TestCase
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

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->string('product_type')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('branch_limit_override')->nullable();
            $table->unsignedInteger('extra_branch_slots')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
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

        Schema::create('sale_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('product_type')->nullable();
            $table->decimal('percent', 5, 2)->default(0);
            $table->string('badge')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(false);
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
    }

    // ─── fixtures ────────────────────────────────────────────────────────

    private function makePlan(string $productType = 'pos', float $price = 24999, ?int $branches = 2, array $attrs = []): PricingPlan
    {
        return PricingPlan::create(array_merge([
            // Both POS lines sell "Business"; only DI gets a made-up name here.
            'name' => $productType === 'di' ? 'DI Premium' : 'Business',
            'product_type' => $productType,
            'is_trial' => false,
            'price' => $price,
            'branch_limit' => $branches,
        ], $attrs));
    }

    /** A shop whose package is up for renewal, with `slots` paid slots and `branches` branches. */
    private function makeShop(int $slots = 3, int $branches = 4, array $attrs = []): Company
    {
        $company = Company::create(array_merge([
            'name' => 'Slot Traders',
            'email' => 'owner@slot.test',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'extra_branch_slots' => $slots,
        ], $attrs));

        for ($i = 1; $i <= $branches; $i++) {
            DB::table('branches')->insert([
                'company_id' => $company->id,
                'name' => 'Branch ' . $i,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $company;
    }

    private function makeProof(Company $company, PricingPlan $plan, ?float $amount, string $cycle = 'annual'): PaymentProof
    {
        return PaymentProof::create([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'amount' => $amount,
            'proof_path' => 'payment-proofs/renewal.jpg',
            'status' => 'pending',
        ]);
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

    private function approve(PaymentProof $proof, PricingPlan $plan, array $extra = [], string $cycle = 'annual')
    {
        return $this->actingAs($this->admin(), 'admin')
            ->post(route('saas.admin.payment-proofs.approve', $proof->id), array_merge([
                'pricing_plan_id' => $plan->id,
                'billing_cycle' => $cycle,
                'verified_received_amount' => (float) $proof->amount,
            ], $extra));
    }

    private function activeSub(Company $company): ?Subscription
    {
        return Subscription::where('company_id', $company->id)
            ->where('active', true)->orderByDesc('id')->first();
    }

    // ─── 1. the review itself ────────────────────────────────────────────

    public function test_review_shows_expected_total_beside_the_amount_the_shop_claims(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4);

        // Shop only transferred the base package amount.
        $review = BranchAddonService::renewalReview($shop, $plan, 'annual', 24999);

        $this->assertTrue($review['applies']);
        $this->assertSame(24999.0, $review['base_price']);
        $this->assertSame(30000.0, $review['addon_price'], '3 slots x Rs 10,000 for a full year');
        $this->assertSame(54999.0, $review['expected_total']);
        $this->assertSame(24999.0, $review['paid']);
        $this->assertTrue($review['short'], 'A base-only transfer must be flagged short');
        $this->assertSame(30000.0, $review['shortfall']);
    }

    public function test_review_is_not_short_when_the_shop_paid_the_full_quoted_total(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4);

        $review = BranchAddonService::renewalReview($shop, $plan, 'annual', 54999);

        $this->assertFalse($review['short']);
        $this->assertSame(0.0, $review['shortfall']);
    }

    public function test_review_never_calls_a_missing_amount_short(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4);

        $review = BranchAddonService::renewalReview($shop, $plan, 'annual', null);

        $this->assertNull($review['paid']);
        $this->assertFalse($review['short'], 'No stated amount is not evidence of a short payment');
    }

    public function test_review_prices_a_non_annual_request_as_annual_pro_rata(): void
    {
        $plan = $this->makePlan('pos', 24999, 2, ['price_quarterly' => 7199]);
        $shop = $this->makeShop(slots: 2, branches: 2);

        $review = BranchAddonService::renewalReview($shop, $plan, 'quarterly', 7199);

        $this->assertSame('annual', $review['cycle']);
        $this->assertSame(24999.0, $review['base_price']);
        $this->assertSame(20000.0, $review['addon_price'], '2 slots x Rs 10,000 for a full year');
        $this->assertSame(44999.0, $review['expected_total']);
        $this->assertTrue($review['short']);
    }

    // ─── 2. scope: only where the add-on is sold ─────────────────────────

    public function test_review_does_not_apply_without_paid_slots(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 0, branches: 1);

        $review = BranchAddonService::renewalReview($shop, $plan, 'annual', 24999);

        $this->assertFalse($review['applies']);
        $this->assertSame(0.0, $review['addon_price']);
    }

    /**
     * (Task 1441) An unlimited-branch package already grants every branch, so
     * the stored slots buy nothing there — the two halves of the service must
     * agree: applicableSlots() treats them as inert, so the renewal quote must
     * NOT add slots x 10,000. Before this fix the review still billed 30,000
     * for capacity the package gave away for free.
     */
    public function test_review_never_bills_slots_on_an_unlimited_branch_package(): void
    {
        foreach ([null, -1] as $unlimited) {
            $plan = $this->makePlan('pos', 69999, $unlimited);
            $shop = $this->makeShop(slots: 3, branches: 4);

            $review = BranchAddonService::renewalReview($shop, $plan, 'annual', 69999);

            $this->assertFalse($review['applies'], 'branch_limit=' . var_export($unlimited, true) . ' must not charge the add-on');
            $this->assertSame(0.0, $review['addon_price']);
            $this->assertSame(69999.0, $review['expected_total'], 'expected total = base package only');
            $this->assertFalse($review['short'], 'paying the base package in full must not read as short');
        }
    }

    /**
     * (Task 1441) The stored slot count stays DORMANT (not zeroed) on an
     * unlimited package, so a later downgrade to a limited package restores
     * both the capacity and the billing with no admin cleanup step.
     */
    public function test_slots_stay_dormant_on_unlimited_and_bill_again_after_a_downgrade(): void
    {
        $unlimited = $this->makePlan('pos', 69999, -1);
        $limited = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 2);

        // Unlimited: nothing billed, but the count is left standing.
        $this->assertSame(0.0, BranchAddonService::addonForCycle($shop, $unlimited, 'annual'));
        $this->assertSame(3, (int) $shop->fresh()->extra_branch_slots);

        // Downgrade back to a limited package: the dormant slots bill again.
        $this->assertSame(30000.0, BranchAddonService::addonForCycle($shop, $limited, 'annual'), '3 dormant slots bill again');
    }

    public function test_review_does_not_apply_to_other_product_lines_or_trials(): void
    {
        $diPlan = $this->makePlan('di', 1500, 1);
        $trialPlan = $this->makePlan('pos', 0, 1, ['name' => 'Trial', 'is_trial' => true]);
        $shop = $this->makeShop(slots: 3, branches: 4);

        foreach ([$diPlan, $trialPlan] as $plan) {
            $review = BranchAddonService::renewalReview($shop, $plan, 'annual', 1);
            $this->assertFalse($review['applies'], $plan->product_type . ' must not charge the branch add-on');
            $this->assertSame(0.0, $review['addon_price']);
        }
    }

    /**
     * 23 Aug 2026: FBR POS sells the same Rs 10,000/branch/year add-on, so its
     * renewals must be reviewed exactly like PRA POS — a base-only transfer on
     * an FBR shop with paid slots is just as short.
     */
    public function test_review_applies_to_an_fbr_pos_renewal_too(): void
    {
        $fbrPlan = $this->makePlan('fbrpos', 27999, 2);
        $shop = $this->makeShop(slots: 3, branches: 5, attrs: ['product_type' => 'fbrpos']);

        $review = BranchAddonService::renewalReview($shop, $fbrPlan, 'annual', 27999);

        $this->assertTrue($review['applies']);
        $this->assertSame(27999.0, $review['base_price']);
        $this->assertSame(30000.0, $review['addon_price'], '3 slots x Rs 10,000 for a full year');
        $this->assertSame(57999.0, $review['expected_total']);
        $this->assertTrue($review['short'], 'a base-only FBR transfer must be flagged short');
    }

    // ─── 3. the floor ────────────────────────────────────────────────────

    public function test_floor_is_the_branches_the_shop_already_has_beyond_the_package(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);

        $this->assertSame(2, BranchAddonService::minimumSlotsForBranches($this->makeShop(3, 4), $plan), '4 branches - 2 included');
        $this->assertSame(0, BranchAddonService::minimumSlotsForBranches($this->makeShop(3, 2), $plan), 'package covers them all');
        $this->assertSame(0, BranchAddonService::minimumSlotsForBranches($this->makeShop(3, 1), $plan));
    }

    public function test_floor_never_demands_more_slots_than_the_shop_actually_owns(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        // 6 branches on a 2-branch package with only 1 paid slot: the shop is
        // ALREADY over the limit (an earlier manual edit). The renewal must
        // still be approvable — it just cannot be reduced any further.
        $shop = $this->makeShop(1, 6);

        $this->assertSame(1, BranchAddonService::minimumSlotsForBranches($shop, $plan));

        $proof = $this->makeProof($shop, $plan, 34999);
        $this->approve($proof, $plan, ['extra_branch_slots' => 1])->assertRedirect();

        $proof->refresh();
        $this->assertSame('verified', $proof->status, 'An already-over-limit shop must still be renewable');
        $this->assertSame(1, (int) $shop->fresh()->extra_branch_slots);
    }

    public function test_floor_is_zero_when_an_admin_override_or_internal_flag_governs_the_limit(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);

        $override = $this->makeShop(3, 6, ['branch_limit_override' => 9]);
        $internal = $this->makeShop(3, 6, ['is_internal_account' => true, 'email' => 'internal@slot.test']);

        $this->assertSame(0, BranchAddonService::minimumSlotsForBranches($override, $plan));
        $this->assertSame(0, BranchAddonService::minimumSlotsForBranches($internal, $plan));
    }

    // ─── 4. approving with a slot decision ───────────────────────────────

    public function test_approving_can_reduce_slots_and_the_renewal_is_priced_at_the_reduced_count(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4);
        $proof = $this->makeProof($shop, $plan, 24999);

        $this->approve($proof, $plan, ['extra_branch_slots' => 2])->assertRedirect();

        $proof->refresh();
        $shop->refresh();

        $this->assertSame('verified', $proof->status);
        $this->assertSame(2, (int) $shop->extra_branch_slots, 'The admin decision must be stored');

        $sub = $this->activeSub($shop);
        $this->assertSame($proof->subscription_id, $sub->id);
        $this->assertSame(44999.0, (float) $sub->final_price, 'Renewal must be priced at the REDUCED slot count');
    }

    public function test_approving_without_the_field_leaves_the_slot_count_exactly_as_it_was(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4);
        $proof = $this->makeProof($shop, $plan, 54999);

        $this->approve($proof, $plan)->assertRedirect();

        $proof->refresh();
        $shop->refresh();

        $this->assertSame('verified', $proof->status);
        $this->assertSame(3, (int) $shop->extra_branch_slots);
        $this->assertSame(54999.0, (float) $this->activeSub($shop)->final_price);
    }

    public function test_approving_refuses_to_strand_branches_above_the_limit(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4);
        $proof = $this->makeProof($shop, $plan, 24999);

        // 4 branches, package includes 2 → the floor is 2 paid slots.
        $this->approve($proof, $plan, ['extra_branch_slots' => 1])
            ->assertRedirect()
            ->assertSessionHas('error');

        $proof->refresh();
        $shop->refresh();

        $this->assertSame('pending', $proof->status, 'A stranding reduction must not approve the proof');
        $this->assertNull($proof->subscription_id);
        $this->assertSame(3, (int) $shop->extra_branch_slots, 'Slots must stay untouched when refused');
    }

    public function test_approving_can_never_be_used_to_add_slots(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4);
        $proof = $this->makeProof($shop, $plan, 90000);

        $this->approve($proof, $plan, ['extra_branch_slots' => 5])
            ->assertRedirect()
            ->assertSessionHas('error');

        $proof->refresh();
        $shop->refresh();

        $this->assertSame('pending', $proof->status);
        $this->assertSame(3, (int) $shop->extra_branch_slots);
    }

    public function test_slot_field_is_ignored_for_a_product_line_that_never_had_the_addon(): void
    {
        $plan = $this->makePlan('di', 3000, 2);
        $shop = $this->makeShop(3, 4, ['product_type' => 'di']);
        $proof = $this->makeProof($shop, $plan, 3000);

        $this->approve($proof, $plan, ['extra_branch_slots' => 0])->assertRedirect();

        $proof->refresh();
        $shop->refresh();

        $this->assertSame('verified', $proof->status);
        $this->assertSame(3, (int) $shop->extra_branch_slots, 'a DI renewal must not touch the (inert) stored count');
    }

    /** ...but an FBR POS renewal decides slots exactly like PRA POS does. */
    public function test_approving_an_fbr_renewal_can_reduce_slots_and_reprices_it(): void
    {
        $plan = $this->makePlan('fbrpos', 27999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4, attrs: ['product_type' => 'fbrpos']);
        $proof = $this->makeProof($shop, $plan, 47999);

        $this->approve($proof, $plan, ['extra_branch_slots' => 2])->assertRedirect();

        $proof->refresh();
        $shop->refresh();

        $this->assertSame('verified', $proof->status);
        $this->assertSame(2, (int) $shop->extra_branch_slots);
        $this->assertSame(47999.0, (float) $this->activeSub($shop)->final_price,
            'FBR renewal = base 27,999 + 2 paid branches');
    }

    // ─── 5. audit trail ──────────────────────────────────────────────────

    public function test_a_reduction_is_audited_with_the_same_before_after_shape_as_the_manual_edit(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4);
        $proof = $this->makeProof($shop, $plan, 24999);

        $this->approve($proof, $plan, ['extra_branch_slots' => 2]);

        $entry = AdminAuditLog::where('target_type', 'Company')
            ->where('target_id', $shop->id)
            ->latest('id')->first();

        $this->assertNotNull($entry, 'The slot decision must reach the company audit trail');
        $this->assertSame(3, $entry->metadata['extra_branch_slots_before']);
        $this->assertSame(2, $entry->metadata['extra_branch_slots_after']);
        $this->assertSame($proof->id, $entry->metadata['payment_proof_id']);
        $this->assertSame(54999.0, (float) $entry->metadata['expected_total']);
        $this->assertSame(24999.0, (float) $entry->metadata['amount_claimed']);
        $this->assertNotEmpty($entry->metadata['period_end'], 'The renewal period the slots were paid for must be recorded');
    }

    public function test_keeping_the_slots_is_recorded_too(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4);
        $proof = $this->makeProof($shop, $plan, 54999);

        $this->approve($proof, $plan, ['extra_branch_slots' => 3]);

        $entry = AdminAuditLog::where('target_type', 'Company')
            ->where('target_id', $shop->id)
            ->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame(3, $entry->metadata['extra_branch_slots_before']);
        $this->assertSame(3, $entry->metadata['extra_branch_slots_after']);
    }

    public function test_a_renewal_with_no_slot_decision_writes_no_company_audit_entry(): void
    {
        $plan = $this->makePlan('pos', 24999, 2);
        $shop = $this->makeShop(slots: 3, branches: 4);
        $proof = $this->makeProof($shop, $plan, 54999);

        $this->approve($proof, $plan);

        $this->assertSame(0, AdminAuditLog::where('target_type', 'Company')->where('target_id', $shop->id)->count());
        $this->assertSame(1, AdminAuditLog::where('target_type', 'PaymentProof')->count());
    }

    // ─────────────── retired packages are refused at approval ───────────────

    public function test_an_fbr_renewal_cannot_be_approved_onto_the_retired_package(): void
    {
        $retired = $this->makePlan('fbrpos', 2999, -1, ['name' => 'Pro']);
        $shop = $this->makeShop(slots: 0, branches: 1, attrs: ['product_type' => 'fbrpos']);
        $proof = $this->makeProof($shop, $retired, 2999.0);

        $this->approve($proof, $retired)->assertRedirect();

        $this->assertSame('pending', $proof->fresh()->status, 'the proof must stay in the queue');
        $this->assertNull($this->activeSub($shop), 'no subscription may be created on a retired package');
    }
}
