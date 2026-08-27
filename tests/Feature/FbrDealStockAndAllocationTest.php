<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\FbrPosController;
use App\Models\FbrPosDeal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * FBR POS Deals (Task 1273) — stock blocking + paisa-exact allocation tests.
 *
 * Locks the review-critical invariants of the deal sale path:
 *   1. A deal sale stores COMPONENT rows (own tax rates) whose gross amounts
 *      sum EXACTLY to deal price × quantity, tagged with deal_* metadata.
 *   2. Component stock is deducted per sale.
 *   3. Insufficient stock on an EXISTING stock row blocks the sale (422) with
 *      the component name — atomically (no transaction row, no deduction).
 *   4. The recheck runs INSIDE the sale DB transaction against committed
 *      post-deduction quantities (sequential-depletion test — the sqlite
 *      behavioural twin of the lockForUpdate race guard; sqlite ignores
 *      FOR UPDATE so the row-lock itself is exercised on MySQL only).
 *   5. Products never stocked (no inventory_stocks row) do NOT block.
 *   6. Inactive deals and offline-queued deal replays are rejected.
 *   7. Companies whose plan lacks deals_enabled cannot store a deal line.
 *   8. fbrAllocateDealUnits: exact-sum, exempt-rate and equal-split-fallback
 *      invariants (reflection unit tests).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * direct-call store() (same as FbrPosStoreReplayGuardTest). FBR reporting
 * OFF → no network call.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrDealStockAndAllocationTest.php --testdox
 */
class FbrDealStockAndAllocationTest extends TestCase
{
    protected int $companyId;
    protected int $userId;
    protected int $burgerId;
    protected int $friesId;
    protected int $drinkId;
    protected int $dealId;

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
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('fbr_connection_mode')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->decimal('cashier_discount_limit', 5, 2)->nullable();
            $table->decimal('manager_discount_limit', 5, 2)->nullable();
            $table->string('pos_invoice_prefix')->nullable();
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
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('invoice_number');
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('status')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_response_code')->nullable();
            $table->text('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 12, 2)->nullable();
            $table->decimal('loyalty_points_earned', 12, 2)->nullable();
            $table->decimal('loyalty_points_redeemed', 12, 2)->nullable();
            $table->decimal('loyalty_redemption_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('payment_breakdown')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('offline_uuid', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'offline_uuid'], 'fbr_txn_offline_uuid_unique');
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->decimal('discount', 12, 2)->nullable();
            $table->decimal('item_discount', 12, 2)->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_third_schedule')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            // Deal-grouping metadata (Task 1273)
            $table->string('deal_group', 40)->nullable();
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->string('deal_name')->nullable();
            $table->unsignedInteger('deal_quantity')->nullable();
            $table->decimal('deal_unit_price', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('active_days')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('deal_type', 20)->default('regular');
            $table->time('special_start_time')->nullable();
            $table->time('special_end_time')->nullable();
            $table->unsignedInteger('total_deal_units_limit')->nullable();
            $table->unsignedInteger('daily_deal_units_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_deal_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deal_id')->index();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });
        Schema::create('fbr_pos_deal_choice_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deal_id')->index();
            $table->string('label');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('fbr_pos_deal_choice_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
        });

        Schema::create('fbr_pos_deal_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('deal_id')->index();
            $table->date('usage_date');
            $table->unsignedInteger('units_used')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'deal_id', 'usage_date']);
        });

        Schema::create('fbr_pos_loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('rs_per_point', 8, 2)->default(100);
            $table->decimal('point_value', 8, 2)->default(1);
            $table->integer('min_redeem_points')->default(50);
            $table->integer('points_expiry_days')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('open');
            $table->decimal('sales_count', 12, 2)->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_cash', 12, 2)->default(0);
            $table->decimal('total_card', 12, 2)->default(0);
            $table->decimal('total_other', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->decimal('khata_balance', 12, 2)->default(0);
            $table->integer('loyalty_points')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->integer('total_orders')->default(0);
            $table->timestamps();
        });

        Schema::create('fbr_customer_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('entry_type');
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // products — fbrAllocateDealUnits reads tax_type / default_tax_rate /
        // is_third_schedule / hs_code / uom / is_active on top of price.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('default_price', 12, 2)->default(0);
            $table->decimal('default_tax_rate', 8, 2)->nullable();
            $table->string('tax_type')->default('taxable');
            $table->boolean('is_third_schedule')->default(false);
            $table->boolean('is_price_editable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('min_stock_level', 12, 4)->default(0);
            $table->decimal('avg_purchase_price', 12, 2)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 4)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // Plan-gate tables (real schema — so PosFeatureService does NOT hit its
        // schema-lag fail-open branch; the locked test relies on a REAL "no
        // subscription" answer).
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('fbrpos');
            $table->boolean('is_trial')->default(false);
            $table->boolean('deals_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });

        // ── seed: internal-account company (plan gate passes), inventory ON ──
        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'FBR Deals Test Shop',
            'fbr_reporting_enabled' => false,
            'agent_enabled' => false,
            'fbr_connection_mode' => 'cloud',
            'inventory_enabled' => true,
            'is_internal_account' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('fbr_pos_loyalty_settings')->insert([
            'company_id' => $this->companyId, 'is_enabled' => false,
            'rs_per_point' => 100.00, 'point_value' => 1.00, 'min_redeem_points' => 50,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);

        $this->userId = (int) DB::table('users')->insertGetId([
            'name' => 'Deal Cashier', 'email' => 'dealcashier@fbrtest.pk',
            'password' => bcrypt('test'), 'company_id' => $this->companyId,
            'role' => 'company_admin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->bind('currentCompanyId', fn () => $this->companyId);
        app()->bind('currentBranchId', fn () => null);

        // ── catalog: Burger 500 @18%, Fries 200 exempt, Drink 100 @18% ──
        $this->burgerId = $this->makeProduct('Zinger Burger', 500, 'taxable', 18);
        $this->friesId  = $this->makeProduct('Masala Fries', 200, 'exempt', null);
        $this->drinkId  = $this->makeProduct('Cola 345ml', 100, 'taxable', 18);

        foreach ([$this->burgerId, $this->friesId, $this->drinkId] as $pid) {
            DB::table('inventory_stocks')->insert([
                'company_id' => $this->companyId, 'product_id' => $pid, 'branch_id' => null,
                'quantity' => 10, 'min_stock_level' => 0,
                'avg_purchase_price' => 50, 'last_purchase_price' => 50,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ── the deal: 1 burger + 1 fries + 2 drinks, fixed price 850 ──
        $this->dealId = (int) DB::table('fbr_pos_deals')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Family Combo',
            'description' => 'Burger + fries + 2 drinks', 'price' => 850,
            'is_active' => true, 'active_days' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$this->burgerId, 1], [$this->friesId, 1], [$this->drinkId, 2]] as [$pid, $q]) {
            DB::table('fbr_pos_deal_items')->insert([
                'deal_id' => $this->dealId, 'product_id' => $pid, 'quantity' => $q,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeProduct(string $name, float $price, string $taxType, ?float $rate, ?int $companyId = null): int
    {
        return (int) DB::table('products')->insertGetId([
            'company_id' => $companyId ?? $this->companyId,
            'name' => $name, 'default_price' => $price,
            'tax_type' => $taxType, 'default_tax_rate' => $rate,
            'is_third_schedule' => false, 'is_active' => true,
            'uom' => 'U', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dealPayload(int $dealQty, array $override = []): array
    {
        return array_merge([
            'items' => [[
                'item_name' => 'Family Combo',
                'quantity' => $dealQty,
                'unit_price' => 850,
                'deal_id' => $this->dealId,
                'uom' => 'U',
                'tax_rate' => 0,
                'is_tax_exempt' => false,
                'item_discount' => 0,
            ]],
            'payment_method' => 'cash',
            'cash_received' => 850 * $dealQty,
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'tax_inclusive' => false,
            'offline_uuid' => 'deal-test-' . uniqid(),
        ], $override);
    }

    private function callStore(array $payload, ?int $userId = null, ?int $companyId = null)
    {
        $userModel = new \App\Models\User();
        $userModel->id = $userId ?? $this->userId;
        $userModel->role = 'company_admin';
        $userModel->company_id = $companyId ?? $this->companyId;
        Auth::guard('fbrpos')->setUser($userModel);

        $req = Request::create('/fbr-pos/store', 'POST', $payload);
        $req->headers->set('Accept', 'application/json');

        return (new FbrPosController())->store($req);
    }

    private function stockQty(int $productId): float
    {
        return (float) DB::table('inventory_stocks')
            ->where('company_id', $this->companyId)
            ->where('product_id', $productId)
            ->value('quantity');
    }

    /** Expect store() to throw ValidationException; return its first items error. */
    private function expectStoreRejection(array $payload, ?int $userId = null, ?int $companyId = null): string
    {
        try {
            $this->callStore($payload, $userId, $companyId);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('items', $errors, 'rejection rides the items error bag');
            return (string) ($errors['items'][0] ?? '');
        }
        $this->fail('store() should have thrown ValidationException');
    }

    // ── 1. allocation + component tagging ───────────────────────────────────

    public function test_deal_sale_stores_component_rows_summing_exactly_to_deal_price(): void
    {
        $res = $this->callStore($this->dealPayload(2));
        $data = $res->getData(true);
        $this->assertTrue($data['success'], 'deal sale success');
        $this->assertSame(1700.0, (float) $data['total_amount'], 'total = 850 × 2');

        $rows = DB::table('fbr_pos_transaction_items')
            ->where('transaction_id', $data['transaction_id'])->get();
        $this->assertCount(3, $rows, 'one component row per deal component');

        $groups = $rows->pluck('deal_group')->unique();
        $this->assertCount(1, $groups, 'single deal_group uuid');
        $this->assertNotEmpty($groups->first());

        foreach ($rows as $row) {
            $this->assertSame($this->dealId, (int) $row->deal_id);
            $this->assertSame('Family Combo', $row->deal_name);
            $this->assertSame(2, (int) $row->deal_quantity);
            $this->assertSame(850.0, (float) $row->deal_unit_price);
            $this->assertNotNull($row->product_id, 'component rows carry real product_id');
        }

        // Paisa-exact: Σ(subtotal + tax_amount) == deal price × deal qty
        $grossSum = $rows->sum(fn ($r) => (float) $r->subtotal + (float) $r->tax_amount);
        $this->assertSame('1700.00', number_format($grossSum, 2, '.', ''), 'group gross = 850 × 2 EXACTLY');

        // Component tax rates are their OWN FBR rates (not the deal's)
        $byProduct = $rows->keyBy('product_id');
        $this->assertSame(18.0, (float) $byProduct[$this->burgerId]->tax_rate);
        $this->assertSame(0.0, (float) $byProduct[$this->friesId]->tax_rate, 'exempt component rate 0');
        $this->assertSame(1, (int) $byProduct[$this->friesId]->is_tax_exempt);
        $this->assertSame(0.0, (float) $byProduct[$this->friesId]->tax_amount);
        // Component quantities scale with deal qty (2 drinks per deal × 2 deals)
        $this->assertSame(4.0, (float) $byProduct[$this->drinkId]->quantity);

        // Header mirrors the item rows
        $tx = DB::table('fbr_pos_transactions')->where('id', $data['transaction_id'])->first();
        $this->assertSame(
            number_format((float) $tx->subtotal + (float) $tx->tax_amount, 2, '.', ''),
            '1700.00',
            'header subtotal+tax = deal gross'
        );
    }

    // ── 2. stock deduction ───────────────────────────────────────────────────

    public function test_deal_sale_deducts_component_stock(): void
    {
        $res = $this->callStore($this->dealPayload(1));
        $this->assertTrue($res->getData(true)['success']);

        $this->assertSame(9.0, $this->stockQty($this->burgerId), 'burger 10 → 9');
        $this->assertSame(9.0, $this->stockQty($this->friesId), 'fries 10 → 9');
        $this->assertSame(8.0, $this->stockQty($this->drinkId), 'drink 10 → 8 (2 per deal)');

        $this->assertSame(3, (int) DB::table('inventory_movements')
            ->where('company_id', $this->companyId)->count(), 'one movement per component');
    }

    // ── 3. insufficient stock blocks atomically ─────────────────────────────

    public function test_insufficient_component_stock_blocks_sale_atomically(): void
    {
        // 2 deals need 4 drinks; only 3 available.
        DB::table('inventory_stocks')->where('product_id', $this->drinkId)->update(['quantity' => 3]);

        $msg = $this->expectStoreRejection($this->dealPayload(2));
        $this->assertStringContainsString('Cola 345ml', $msg, '422 names the short component');

        // Atomic: nothing persisted, nothing deducted (burger stock untouched too).
        $this->assertSame(0, (int) DB::table('fbr_pos_transactions')->count(), 'no transaction row');
        $this->assertSame(0, (int) DB::table('inventory_movements')->count(), 'no movements');
        $this->assertSame(10.0, $this->stockQty($this->burgerId));
        $this->assertSame(3.0, $this->stockQty($this->drinkId));
    }

    public function test_sequential_sales_deplete_then_block_on_committed_quantity(): void
    {
        // sqlite twin of the concurrent race: the recheck must read the
        // POST-deduction committed quantity, not a stale pre-sale snapshot.
        // (On MySQL the lockForUpdate on the stock rows serialises two
        // concurrent sales into exactly this sequential order.)
        DB::table('inventory_stocks')->where('product_id', $this->drinkId)->update(['quantity' => 3]);

        $res1 = $this->callStore($this->dealPayload(1)); // uses 2 drinks → 1 left
        $this->assertTrue($res1->getData(true)['success'], 'first sale passes on qty 3');
        $this->assertSame(1.0, $this->stockQty($this->drinkId));

        $msg = $this->expectStoreRejection($this->dealPayload(1)); // needs 2, has 1
        $this->assertStringContainsString('Cola 345ml', $msg);
        $this->assertSame(1, (int) DB::table('fbr_pos_transactions')->count(), 'only the first sale exists');
        $this->assertSame(1.0, $this->stockQty($this->drinkId), 'second sale deducted nothing');
    }

    // ── 4. never-stocked component does not block ────────────────────────────

    public function test_component_without_stock_row_never_blocks(): void
    {
        DB::table('inventory_stocks')->where('product_id', $this->friesId)->delete();

        $res = $this->callStore($this->dealPayload(2));
        $this->assertTrue($res->getData(true)['success'], 'no stock row = no block (FBR retail rule)');
    }

    // ── 5. inactive deal + offline replay rejected ───────────────────────────

    public function test_inactive_deal_rejected(): void
    {
        DB::table('fbr_pos_deals')->where('id', $this->dealId)->update(['is_active' => false]);

        $this->expectStoreRejection($this->dealPayload(1));
        $this->assertSame(0, (int) DB::table('fbr_pos_transactions')->count());
    }

    public function test_special_deal_quota_is_checked_against_server_price_and_bundle_units(): void
    {
        DB::table('fbr_pos_deals')->where('id', $this->dealId)->update([
            'deal_type' => 'special',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
            'special_start_time' => '00:00',
            'special_end_time' => '23:59',
            'total_deal_units_limit' => 2,
            'daily_deal_units_limit' => 2,
        ]);

        // A tampered client price is ignored; the server still books the
        // configured deal price and consumes one bundle, not its components.
        $res = $this->callStore($this->dealPayload(1, [
            'items' => [[
                'item_name' => 'Family Combo',
                'quantity' => 1,
                'unit_price' => 1,
                'deal_id' => $this->dealId,
                'uom' => 'U',
                'tax_rate' => 0,
                'is_tax_exempt' => false,
                'item_discount' => 0,
            ]],
            'cash_received' => 850,
        ]));
        $this->assertTrue($res->getData(true)['success']);
        $this->assertSame(1, (int) DB::table('fbr_pos_deal_usages')->value('units_used'));
        $this->assertSame('850.00', number_format((float) DB::table('fbr_pos_transactions')->latest('id')->value('total_amount'), 2, '.', ''));
        $this->assertSame(1, \App\Services\PosDealQuotaService::remainingTotal(
            \App\Models\FbrPosDeal::findOrFail($this->dealId)
        ));
        $res2 = $this->callStore($this->dealPayload(2));
        $data2 = $res2->getData(true);
        $this->assertFalse($data2['success']);
        $this->assertStringContainsString('no remaining', strtolower($data2['message']));
        $this->assertSame(1, (int) DB::table('fbr_pos_deal_usages')->value('units_used'));
    }

    public function test_offline_queued_deal_replay_rejected(): void
    {
        // Deals never ride the offline queue: the sale screen blocks offline
        // deal checkout; the server rejects any queued replay from a stale
        // cached client ONCE with a clear message (no eternal retry loop).
        $msg = $this->expectStoreRejection($this->dealPayload(1, [
            'offline_queued_at' => now()->subHour()->toISOString(),
        ]));
        $this->assertSame(__('pos.deal_offline_block'), $msg);
        $this->assertSame(0, (int) DB::table('fbr_pos_transactions')->count());
        $this->assertSame(10.0, $this->stockQty($this->burgerId), 'no deduction');
    }

    // ── 6. plan gate ─────────────────────────────────────────────────────────

    public function test_plan_locked_company_cannot_store_deal_line(): void
    {
        // Non-internal company with NO subscription — planAllows() = false via
        // a real query (tables exist, no schema-lag fail-open).
        $lockedCompanyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Locked Shop', 'fbr_reporting_enabled' => false,
            'agent_enabled' => false, 'fbr_connection_mode' => 'cloud',
            'inventory_enabled' => false, 'is_internal_account' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fbr_pos_loyalty_settings')->insert([
            'company_id' => $lockedCompanyId, 'is_enabled' => false,
            'rs_per_point' => 100, 'point_value' => 1, 'min_redeem_points' => 50,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);
        $lockedUserId = (int) DB::table('users')->insertGetId([
            'name' => 'Locked Cashier', 'email' => 'locked@fbrtest.pk',
            'password' => bcrypt('test'), 'company_id' => $lockedCompanyId,
            'role' => 'company_admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $lockedCompanyId);

        // A deal belonging to the locked company (gate must fire BEFORE lookup).
        $lockedDealId = (int) DB::table('fbr_pos_deals')->insertGetId([
            'company_id' => $lockedCompanyId, 'name' => 'Locked Combo', 'price' => 100,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $msg = $this->expectStoreRejection(
            $this->dealPayload(1, ['items' => [[
                'item_name' => 'Locked Combo', 'quantity' => 1, 'unit_price' => 100,
                'deal_id' => $lockedDealId, 'uom' => 'U', 'tax_rate' => 0,
                'is_tax_exempt' => false, 'item_discount' => 0,
            ]], 'cash_received' => 100]),
            $lockedUserId,
            $lockedCompanyId
        );
        $this->assertSame(__('pos.plan_locked_feature'), $msg);
        $this->assertSame(0, (int) DB::table('fbr_pos_transactions')->where('company_id', $lockedCompanyId)->count());
    }

    // ── 7. allocation unit invariants (reflection) ───────────────────────────

    private function allocate(int $dealId): array
    {
        $deal = FbrPosDeal::with(['items', 'choiceGroups.options'])->findOrFail($dealId);
        $m = new \ReflectionMethod(FbrPosController::class, 'fbrAllocateDealUnits');
        $m->setAccessible(true);
        return $m->invoke(new FbrPosController(), $deal);
    }

    public function test_allocation_sums_exactly_even_on_awkward_prices(): void
    {
        // 999.99 across three unevenly-weighted components — the sequential-
        // remainder split must land the last paisa somewhere, never drift.
        $dealId = (int) DB::table('fbr_pos_deals')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Paisa Combo', 'price' => 999.99,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$this->burgerId, 1], [$this->friesId, 3], [$this->drinkId, 1]] as [$pid, $q]) {
            DB::table('fbr_pos_deal_items')->insert([
                'deal_id' => $dealId, 'product_id' => $pid, 'quantity' => $q,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $units = $this->allocate($dealId);
        $this->assertCount(3, $units);

        $grossSum = array_sum(array_map(fn ($u) => $u['unit_gross'], $units));
        $this->assertSame('999.99', number_format($grossSum, 2), 'Σ gross = price EXACTLY');

        foreach ($units as $u) {
            // net + tax must reassemble the gross share with no rounding leak
            $this->assertSame(
                number_format($u['unit_gross'], 2),
                number_format($u['unit_net'] + $u['unit_tax'], 2),
                'net + tax = gross per component'
            );
            if ($u['is_tax_exempt']) {
                $this->assertSame(0.0, $u['tax_rate']);
                $this->assertSame(0.0, $u['unit_tax']);
            } else {
                $this->assertSame(18.0, $u['tax_rate']);
            }
        }
    }

    public function test_allocation_equal_split_fallback_for_zero_priced_components(): void
    {
        $freeA = $this->makeProduct('Gift A', 0, 'taxable', 18);
        $freeB = $this->makeProduct('Gift B', 0, 'exempt', null);

        $dealId = (int) DB::table('fbr_pos_deals')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Gift Combo', 'price' => 100.01,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$freeA, $freeB] as $pid) {
            DB::table('fbr_pos_deal_items')->insert([
                'deal_id' => $dealId, 'product_id' => $pid, 'quantity' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $units = $this->allocate($dealId);
        $this->assertCount(2, $units);
        $grossSum = array_sum(array_map(fn ($u) => $u['unit_gross'], $units));
        $this->assertSame('100.01', number_format($grossSum, 2), 'equal-split still sums exactly');
    }

    public function test_allocation_empty_when_component_inactive(): void
    {
        DB::table('products')->where('id', $this->friesId)->update(['is_active' => false]);
        $this->assertSame([], $this->allocate($this->dealId), 'inactive component = deal unsellable');
    }

    public function test_choice_product_is_allocated_as_a_real_fbr_component(): void
    {
        $groupId = (int) DB::table('fbr_pos_deal_choice_groups')->insertGetId([
            'deal_id' => $this->dealId, 'label' => 'Drink', 'quantity' => 2,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fbr_pos_deal_choice_options')->insert([
            'group_id' => $groupId, 'product_id' => $this->drinkId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $deal = FbrPosDeal::with(['items', 'choiceGroups.options'])->findOrFail($this->dealId);
        $m = new \ReflectionMethod(FbrPosController::class, 'fbrAllocateDealUnits');
        $m->setAccessible(true);
        $units = $m->invoke(new FbrPosController(), $deal, [[
            'group_id' => $groupId, 'product_id' => $this->drinkId,
        ]]);

        $picked = collect($units)->firstWhere('choice_group_id', $groupId);
        $this->assertNotNull($picked);
        $this->assertSame($this->drinkId, (int) $picked['product']->id);
        $this->assertSame(2, (int) $picked['component_qty']);
        $this->assertSame('Drink', $picked['choice_group_label']);
    }

    public function test_choice_product_must_belong_to_its_configured_group(): void
    {
        $groupId = (int) DB::table('fbr_pos_deal_choice_groups')->insertGetId([
            'deal_id' => $this->dealId, 'label' => 'Drink', 'quantity' => 1,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fbr_pos_deal_choice_options')->insert([
            'group_id' => $groupId, 'product_id' => $this->drinkId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $deal = FbrPosDeal::with(['items', 'choiceGroups.options'])->findOrFail($this->dealId);
        $m = new \ReflectionMethod(FbrPosController::class, 'fbrAllocateDealUnits');
        $m->setAccessible(true);
        $this->expectException(ValidationException::class);
        $m->invoke(new FbrPosController(), $deal, [[
            'group_id' => $groupId, 'product_id' => $this->burgerId,
        ]]);
    }
}
