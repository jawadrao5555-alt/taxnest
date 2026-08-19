<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Models\FbrPosDraft;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * TASK 1271 REVIEW LOCKS — FBR drafts race-safety + WhatsApp toggle authorization.
 *
 * Locks the invariants added after the completion review:
 *
 *   1. POST /fbr-pos/settings/whatsapp-bill-toggle requires an EXPLICIT admin
 *      role (pos_admin / pos_manager / company_admin). Cashiers AND non-cashier
 *      staff (kitchen, waiter, no-role accounts) all get 403 — posCashierBlocked()
 *      alone would wave the latter through.
 *   2. Draft mutations are race-safe: saveDraft / lockDraft / deleteDraft /
 *      unlockDraft carry the lock predicate INSIDE the UPDATE/DELETE statement
 *      (lockFreeFor scope), so a competing fresh lock always wins:
 *        - update/delete against another user's fresh lock → 423, row untouched
 *        - expired locks are claimable/deletable
 *        - unlock never releases someone else's fresh lock
 *   3. Saving a draft PARKS it: the saver's lock is released on save (the UI
 *      clears the cart), so another cashier can recall it immediately.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosDraftLockWhatsappAuthTest.php
 */
class FbrPosDraftLockWhatsappAuthTest extends TestCase
{
    protected Company $company;
    protected User $admin;
    protected User $cashierA;
    protected User $cashierB;
    protected User $kitchen;
    protected User $noRole;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('fbr_pos_enabled')->default(true);
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('pos_whatsapp_bill_enabled')->default(false);
            $table->boolean('pos_whatsapp_bill_auto_open')->default(false);
            $table->softDeletes();
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
            $table->string('language')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('fbr_pos_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('cart_data');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->integer('items_count')->default(0);
            $table->unsignedBigInteger('locked_by_user_id')->nullable();
            $table->timestamp('lock_time')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        // fbrpos.auth / company.approval middleware touch these when resolving
        // the company banner state; keeping them empty is enough.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('active')->default(false);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });

        // Sales tables — the settlement tests run the REAL store() path (winner
        // creates a transaction; losers must create nothing).
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 8, 2)->default(0);
            $table->integer('loyalty_points_earned')->default(0);
            $table->integer('loyalty_points_redeemed')->default(0);
            $table->decimal('loyalty_redemption_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('payment_breakdown')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('status')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('offline_uuid')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('item_discount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->timestamps();
        });

        Schema::create('fbr_pos_loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('is_enabled')->default(false);
            $table->decimal('rs_per_point', 12, 2)->default(100);
            $table->decimal('point_value', 12, 2)->default(1);
            $table->integer('min_redeem_points')->default(50);
            $table->timestamps();
        });

        Schema::create('fbr_pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->nullable();
            $table->integer('sales_count')->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_cash', 12, 2)->default(0);
            $table->decimal('total_card', 12, 2)->default(0);
            $table->decimal('total_other', 12, 2)->default(0);
            $table->timestamps();
        });

        $this->company = Company::create([
            'name' => 'Draft QA Shop',
            'product_type' => 'fbrpos',
            'status' => 'approved',
            'company_status' => 'active',
        ]);

        $mk = fn (string $name, ?string $posRole, string $role = 'user') => User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@qa.test',
            'password' => bcrypt('secret-qa'),
            'company_id' => $this->company->id,
            'role' => $role,
            'pos_role' => $posRole,
            'is_active' => true,
        ]);

        $this->admin    = $mk('Admin User', null, 'company_admin');
        $this->cashierA = $mk('Cashier A', 'pos_cashier');
        $this->cashierB = $mk('Cashier B', 'pos_cashier');
        $this->kitchen  = $mk('Kitchen User', 'pos_kitchen');
        $this->noRole   = $mk('No Role User', null); // role=user, pos_role NULL
    }

    private function asUser(User $u)
    {
        return $this->actingAs($u, 'fbrpos');
    }

    private function makeDraft(User $owner, ?User $lockedBy = null, ?\DateTimeInterface $lockTime = null): FbrPosDraft
    {
        return FbrPosDraft::create([
            'company_id' => $this->company->id,
            'user_id' => $owner->id,
            'cart_data' => ['items' => [['id' => 1, 'name' => 'Item', 'price' => 10, 'qty' => 1]], 'total_amount' => 10],
            'total_amount' => 10,
            'items_count' => 1,
            'locked_by_user_id' => $lockedBy?->id,
            'lock_time' => $lockedBy ? ($lockTime ?? now()) : null,
        ]);
    }

    // ── 1. WhatsApp toggle authorization ─────────────────────────────────────

    public function test_whatsapp_toggle_admin_allowed(): void
    {
        // enabled=false avoids the Pro+ plan gate (turning OFF is always allowed).
        $this->asUser($this->admin)
            ->postJson('/fbr-pos/settings/whatsapp-bill-toggle', ['enabled' => false])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_whatsapp_toggle_blocks_cashier_kitchen_and_no_role(): void
    {
        foreach ([$this->cashierA, $this->kitchen, $this->noRole] as $user) {
            $this->asUser($user)
                ->postJson('/fbr-pos/settings/whatsapp-bill-toggle', ['enabled' => false])
                ->assertStatus(403);
        }
        $this->assertFalse((bool) $this->company->fresh()->pos_whatsapp_bill_enabled);
    }

    // ── 2. Draft mutation race-safety ────────────────────────────────────────

    public function test_save_existing_draft_blocked_by_fresh_foreign_lock(): void
    {
        $draft = $this->makeDraft($this->cashierA, lockedBy: $this->cashierB);

        $this->asUser($this->cashierA)
            ->postJson('/fbr-pos/api/drafts', [
                'draft_id' => $draft->id,
                'cart_data' => ['items' => [['id' => 2, 'name' => 'Hijack', 'price' => 99, 'qty' => 1]], 'total_amount' => 99],
            ])
            ->assertStatus(423);

        $fresh = $draft->fresh();
        $this->assertSame(10.0, (float) $fresh->total_amount, 'locked draft must not be overwritten');
        $this->assertSame($this->cashierB->id, (int) $fresh->locked_by_user_id, 'lock must not be stolen');
    }

    public function test_save_releases_lock_so_draft_is_parked(): void
    {
        $draft = $this->makeDraft($this->cashierA, lockedBy: $this->cashierA);

        $this->asUser($this->cashierA)
            ->postJson('/fbr-pos/api/drafts', [
                'draft_id' => $draft->id,
                'cart_data' => ['items' => [['id' => 1, 'name' => 'Item', 'price' => 20, 'qty' => 2]], 'total_amount' => 40],
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'updated' => true]);

        $fresh = $draft->fresh();
        $this->assertNull($fresh->locked_by_user_id, 'saving must release the lock (draft is parked)');
        $this->assertNull($fresh->lock_time);
        $this->assertSame(40.0, (float) $fresh->total_amount);

        // …and cashier B can recall it immediately, no 5-min wait.
        $this->asUser($this->cashierB)
            ->getJson("/fbr-pos/api/drafts/{$draft->id}/recall")
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_new_draft_created_unlocked(): void
    {
        $resp = $this->asUser($this->cashierA)
            ->postJson('/fbr-pos/api/drafts', [
                'cart_data' => ['items' => [['id' => 1, 'name' => 'Item', 'price' => 10, 'qty' => 1]], 'total_amount' => 10],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $draft = FbrPosDraft::findOrFail($resp->json('id'));
        $this->assertNull($draft->locked_by_user_id, 'a freshly saved draft must be recallable by anyone');
    }

    public function test_delete_blocked_by_fresh_foreign_lock_but_allowed_when_expired(): void
    {
        $locked = $this->makeDraft($this->cashierA, lockedBy: $this->cashierB);

        $this->asUser($this->cashierA)
            ->deleteJson("/fbr-pos/api/drafts/{$locked->id}")
            ->assertStatus(423);
        $this->assertNotNull($locked->fresh(), 'row must survive a blocked delete');

        // Expired lock (aged past LOCK_MINUTES) → delete succeeds.
        $expired = $this->makeDraft(
            $this->cashierA,
            lockedBy: $this->cashierB,
            lockTime: now()->subMinutes(FbrPosDraft::LOCK_MINUTES + 1)
        );
        $this->asUser($this->cashierA)
            ->deleteJson("/fbr-pos/api/drafts/{$expired->id}")
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertNull($expired->fresh());
    }

    public function test_lock_claim_blocked_when_fresh_and_wins_when_expired(): void
    {
        $draft = $this->makeDraft($this->cashierA, lockedBy: $this->cashierA);

        $this->asUser($this->cashierB)
            ->postJson("/fbr-pos/api/drafts/{$draft->id}/lock")
            ->assertStatus(423);
        $this->assertSame($this->cashierA->id, (int) $draft->fresh()->locked_by_user_id);

        // Age the lock past expiry → B's claim wins.
        $draft->forceFill(['lock_time' => now()->subMinutes(FbrPosDraft::LOCK_MINUTES + 1)])->save();
        $this->asUser($this->cashierB)
            ->postJson("/fbr-pos/api/drafts/{$draft->id}/lock")
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertSame($this->cashierB->id, (int) $draft->fresh()->locked_by_user_id);
    }

    public function test_unlock_never_releases_foreign_fresh_lock(): void
    {
        $draft = $this->makeDraft($this->cashierA, lockedBy: $this->cashierA);

        // B "unlocks" — endpoint answers success (idempotent) but the lock survives.
        $this->asUser($this->cashierB)
            ->postJson("/fbr-pos/api/drafts/{$draft->id}/unlock")
            ->assertOk();
        $this->assertSame($this->cashierA->id, (int) $draft->fresh()->locked_by_user_id);

        // A releases their own lock.
        $this->asUser($this->cashierA)
            ->postJson("/fbr-pos/api/drafts/{$draft->id}/unlock")
            ->assertOk();
        $this->assertNull($draft->fresh()->locked_by_user_id);
    }

    // ── 3. Active-recall lock RENEWAL (reviewer scenario) ────────────────────

    public function test_owner_renewal_keeps_lock_alive_past_original_expiry(): void
    {
        // A recalls the draft → 5-minute lock starts.
        $draft = $this->makeDraft($this->cashierA);
        $this->asUser($this->cashierA)
            ->getJson("/fbr-pos/api/drafts/{$draft->id}/recall")
            ->assertOk();

        // 4 minutes pass (lock would die at 5) — the sale screen's renewal timer
        // re-asserts the lock via the lock endpoint.
        $draft->forceFill(['lock_time' => now()->subMinutes(4)])->save();
        $this->asUser($this->cashierA)
            ->postJson("/fbr-pos/api/drafts/{$draft->id}/lock")
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertTrue(
            $draft->fresh()->lock_time->gt(now()->subMinute()),
            'renewal must refresh lock_time to now'
        );

        // Another 4 minutes (8 total — WELL past the original 5-minute window):
        // because A renewed, B must still be locked out.
        $this->asUser($this->cashierB)
            ->postJson("/fbr-pos/api/drafts/{$draft->id}/lock")
            ->assertStatus(423);
        $this->assertSame($this->cashierA->id, (int) $draft->fresh()->locked_by_user_id);
    }

    // ── 4. Competing SETTLEMENT (fbrpos.store draft_id claim) ────────────────

    private function grantLifetimeAccess(): void
    {
        // plan.limit middleware: lifetime override = allowed + bypasses caps.
        \DB::table('subscriptions')->insert([
            'company_id' => $this->company->id,
            'active' => 1,
            'override_type' => 'lifetime',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storePayload(int $draftId, float $cashReceived = 100, ?string $uuid = null): array
    {
        return [
            'items' => [['item_name' => 'Race Item', 'quantity' => 1, 'unit_price' => 10]],
            'payment_method' => 'cash',
            'cash_received' => $cashReceived,
            'save_as_provisional' => true, // never reaches FBR even if the gate failed
            'draft_id' => $draftId,
            'offline_uuid' => $uuid,
        ];
    }

    public function test_settlement_rejected_when_draft_claim_lost_to_fresh_foreign_lock(): void
    {
        $this->grantLifetimeAccess();

        // B holds a FRESH lock (recalled the draft after A's lock lapsed).
        $draft = $this->makeDraft($this->cashierA, lockedBy: $this->cashierB);

        // A still has the cart open and hits Pay → server claim must LOSE.
        $this->asUser($this->cashierA)
            ->postJson('/fbr-pos/store', $this->storePayload($draft->id))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'draft_conflict' => true]);

        $this->assertSame(0, \DB::table('fbr_pos_transactions')->count(),
            'a lost draft claim must never create a fiscal transaction');
        $this->assertNotNull($draft->fresh(), 'the competing cashier\'s draft must survive');
        $this->assertSame($this->cashierB->id, (int) $draft->fresh()->locked_by_user_id);
    }

    public function test_settlement_rejected_when_draft_already_consumed(): void
    {
        $this->grantLifetimeAccess();

        // Draft was already consumed by the winning settlement (row deleted).
        $draft = $this->makeDraft($this->cashierA);
        $goneId = $draft->id;
        $draft->delete();

        $this->asUser($this->cashierB)
            ->postJson('/fbr-pos/store', $this->storePayload($goneId))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'draft_conflict' => true]);

        $this->assertSame(0, \DB::table('fbr_pos_transactions')->count(),
            'settling an already-billed draft must never create a second fiscal transaction');
    }

    public function test_same_user_double_settlement_creates_exactly_one_transaction(): void
    {
        $this->grantLifetimeAccess();

        // Cashier A recalled the draft (fresh own lock) and has it open in TWO
        // tabs. Each tab submits with its OWN idempotency uuid, so the
        // offline_uuid replay guard cannot dedupe them — only the atomic
        // in-transaction draft consume may pick the winner.
        $draft = $this->makeDraft($this->cashierA, lockedBy: $this->cashierA);

        $this->asUser($this->cashierA)
            ->postJson('/fbr-pos/store', $this->storePayload($draft->id, uuid: 'tab-1-' . uniqid()))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNull($draft->fresh(), 'winner must consume the draft in the same transaction');
        $this->assertSame(1, \DB::table('fbr_pos_transactions')->count());

        $this->asUser($this->cashierA)
            ->postJson('/fbr-pos/store', $this->storePayload($draft->id, uuid: 'tab-2-' . uniqid()))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'draft_conflict' => true]);

        $this->assertSame(1, \DB::table('fbr_pos_transactions')->count(),
            'the second tab must NOT create a second fiscal transaction');
    }

    public function test_failed_sale_rolls_back_draft_consumption_and_retry_wins(): void
    {
        $this->grantLifetimeAccess();

        $draft = $this->makeDraft($this->cashierA, lockedBy: $this->cashierA);

        // Attempt 1 fails INSIDE the sale transaction (cash short) AFTER the
        // draft-consume DELETE ran — the rollback must resurrect the draft.
        $this->asUser($this->cashierA)
            ->postJson('/fbr-pos/store', $this->storePayload($draft->id, cashReceived: 1, uuid: 'try-1-' . uniqid()))
            ->assertStatus(422);

        $this->assertNotNull($draft->fresh(),
            'a failed sale must roll the draft consumption back (creation+consumption are one protocol)');
        $this->assertSame(0, \DB::table('fbr_pos_transactions')->count());

        // Retry with enough cash → wins, consumes the draft, exactly one sale.
        $this->asUser($this->cashierA)
            ->postJson('/fbr-pos/store', $this->storePayload($draft->id, uuid: 'try-2-' . uniqid()))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNull($draft->fresh());
        $this->assertSame(1, \DB::table('fbr_pos_transactions')->count());
    }

    public function test_recall_conditional_claim_blocks_second_cashier(): void
    {
        $draft = $this->makeDraft($this->cashierA);

        $this->asUser($this->cashierA)
            ->getJson("/fbr-pos/api/drafts/{$draft->id}/recall")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->asUser($this->cashierB)
            ->getJson("/fbr-pos/api/drafts/{$draft->id}/recall")
            ->assertStatus(423);
    }
}
