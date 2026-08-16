<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantPosController;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KOT DELTA = ADDED QTY ONLY — Task 778 (Pizza Master video, Aug 2026).
 *
 * Recall + re-hold recreates a held order. The KOT-carry used to match lines
 * QUANTITY-INCLUSIVE (type/id/name/QTY/notes), so bumping a sent item's qty
 * made the WHOLE line unprinted → the add-on slip printed the CUMULATIVE qty
 * (kitchen fired 1+2=3 bottles for a 2-bottle order), and the replacement
 * order got a brand-new ORD number, so the kitchen read it as a second order.
 *
 * Conventions locked here:
 *   1. QUANTITY-AWARE CARRY — identity match (type/id/name/notes) + printed-qty
 *      chunks: an increased line SPLITS into stamped "sent" row(s) + ONE
 *      unprinted delta row. Every delta path (iframe ?delta=1, agent jobs,
 *      KotPrintService) derives from whereNull(kot_printed_at) → prints only
 *      the added qty.
 *   2. ORDER IDENTITY CARRY — replacement keeps the original order_number and
 *      token_no; the superseded row's number is suffixed '~<id>' (unique).
 *   3. Money columns split proportionally; split rows sum EXACTLY to the
 *      original line (bill totals unchanged).
 *   4. Decrease/remove consumes fewer chunks — NO unprinted row, no phantom KOT.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly (same as PosRecallSupersedeGhostTest).
 */
class PosKotDeltaQtyCarryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        $prop = new \ReflectionProperty(\App\Services\PosFeatureService::class, 'restaurantAllowedCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->text('feature_flags')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            // payOrder path (end-to-end settle test):
            $table->integer('invoice_limit_override')->nullable();
            $table->integer('user_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->text('pra_production_token')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $table->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->string('pos_tax_pricing_mode')->nullable();
            $table->boolean('pos_tax_inclusive')->default(false);
            $table->string('default_language')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->string('language')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('pra_response_code')->nullable();
            $table->text('pra_qr_code')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('submission_hash')->nullable();
            $table->string('offline_uuid')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->text('special_notes')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->string('item_discount_type')->nullable();
            $table->decimal('item_discount_value', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reference_number')->nullable();
            $table->timestamps();
        });

        Schema::create('pra_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->integer('deleted_final_count')->default(0);
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->integer('user_limit')->nullable();
            $table->boolean('restaurant_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('target_printer')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->text('render_query')->nullable();
            $table->string('status')->default('queued');
            $table->string('claim_token')->nullable();
            $table->text('printed_item_ids')->nullable();
            $table->text('error')->nullable();
            $table->integer('attempts')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
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
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_floors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('floor_id')->nullable();
            $table->string('table_number');
            $table->integer('seats')->default(4);
            $table->string('status')->default('available');
            $table->unsignedBigInteger('locked_by_user_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('occupied_since')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number');
            $table->string('token_no')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->default('dine_in');
            $table->string('status')->default('held');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('estimated_cost', 12, 2)->default(0);
            $table->string('kitchen_notes')->nullable();
            $table->boolean('priority')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_cashier_id')->nullable();
            $table->string('source')->default('cashier');
            $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->timestamp('kot_sent_at')->nullable();
            $table->integer('kot_print_count')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('superseded_at')->nullable();
            // Task 841: KDS void-items badge column.
            $table->text('void_items')->nullable();
            // Task 1001: hold_uuid idempotency key — must match live schema.
            $table->string('hold_uuid', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_type')->default('manual');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('special_notes')->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->string('item_discount_type')->nullable();
            $table->decimal('item_discount_value', 12, 2)->default(0);
            $table->decimal('item_discount_amount', 12, 2)->default(0);
            $table->timestamp('kot_printed_at')->nullable();
            $table->integer('kot_batch_no')->nullable();
            $table->timestamps();
        });

        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity_needed', 12, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Task 794: void enqueue walks PosStation::activeFor — table must exist
        // even when empty (zero stations = single job on the company KOT printer).
        Schema::create('pos_stations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('categories')->nullable();
            $table->string('printer_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    // ── Seed helpers ─────────────────────────────────────────────────────

    private function makeCompany(): Company
    {
        $company = Company::create([
            'name' => 'Delta Carry Co',
            'product_type' => 'pos',
            'status' => 'active',
            'is_internal_account' => true,
            'restaurant_mode' => true,
            'feature_flags' => ['tables' => true, 'kot' => true, 'kitchen' => true],
            'pra_reporting_enabled' => false,
            'pos_tax_rate_cash' => 0,
            'pos_tax_rate_card' => 0,
        ]);
        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => $company->id);
        return $company;
    }

    /** Unlimited paid plan — payOrder's quota gate needs a subscription row. */
    private function subscribe(Company $c): void
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business', 'product_type' => 'pos', 'is_trial' => false,
            'invoice_limit' => -1, 'restaurant_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $c->id, 'pricing_plan_id' => $planId, 'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'override_type' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeCashier(Company $c): User
    {
        $u = User::create([
            'company_id' => $c->id, 'name' => 'Cashier C',
            'pos_role' => 'pos_admin', 'is_active' => true,
        ]);
        Auth::guard('pos')->setUser($u);
        return $u;
    }

    private function makeTable(Company $c): int
    {
        $floorId = DB::table('restaurant_floors')->insertGetId([
            'company_id' => $c->id, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('restaurant_tables')->insertGetId([
            'company_id' => $c->id, 'floor_id' => $floorId,
            'table_number' => 'T-1', 'seats' => 4, 'status' => 'available',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function hold(array $payload)
    {
        cache()->flush();
        $request = Request::create('/pos/restaurant/orders/hold', 'POST', $payload);
        return app(RestaurantPosController::class)->holdOrder($request);
    }

    private function bottle(int $qty, array $extra = []): array
    {
        return array_merge(
            ['item_type' => 'manual', 'item_name' => 'Bottle', 'unit_price' => 100, 'quantity' => $qty],
            $extra
        );
    }

    /** Simulate a successful KOT print for every item of an order. */
    private function stampPrinted(int $orderId, int $batch = 1): void
    {
        RestaurantOrderItem::where('order_id', $orderId)
            ->whereNull('kot_printed_at')
            ->update(['kot_printed_at' => now(), 'kot_batch_no' => $batch]);
    }

    // ── 1. Pizza Master scenario: +1 on a sent item → delta = 1, same ORD/token ──

    public function test_qty_increase_delta_row_carries_only_added_qty_same_order_number_and_token(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold(['items' => [$this->bottle(1)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $this->assertSame(200, $res1->getStatusCode(), $res1->getContent());
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $origNumber = RestaurantOrder::find($oldId)->order_number;
        RestaurantOrder::where('id', $oldId)->update(['token_no' => 7]); // Order Matching token on slip #1
        $this->stampPrinted($oldId, 1); // KOT #1 physically printed "1"

        // Cashier recalls, bumps the bottle to 2, re-holds.
        $res2 = $this->hold([
            'items' => [$this->bottle(2)],
            'order_type' => 'dine_in',
            'table_id' => $tableId,
            'recalled_order_id' => $oldId,
        ]);
        $this->assertSame(200, $res2->getStatusCode(), $res2->getContent());
        $newId = json_decode($res2->getContent(), true)['order']['id'];

        $new = RestaurantOrder::find($newId);
        $old = RestaurantOrder::find($oldId);

        // Order identity carry — both slips read as ONE order.
        $this->assertSame($origNumber, $new->order_number, 'replacement order must keep the original ORD number');
        $this->assertSame($origNumber . '~' . $oldId, $old->order_number, 'superseded row frees the unique number via ~id suffix');
        $this->assertEquals(7, (int) $new->token_no, 'replacement order must carry the original token');
        $this->assertSame(2, (int) $new->kot_print_count, 'print count carried +1 → header shows KOT #2 semantics');

        // Quantity-aware carry — sent part stays stamped, delta row = added qty only.
        $items = RestaurantOrderItem::where('order_id', $newId)->orderBy('id')->get();
        $this->assertCount(2, $items, 'increased line splits into sent + delta rows');
        $printed = $items->whereNotNull('kot_printed_at')->values();
        $unprinted = $items->whereNull('kot_printed_at')->values();
        $this->assertCount(1, $printed);
        $this->assertCount(1, $unprinted);
        $this->assertEquals(1.0, (float) $printed[0]->quantity, 'already-sent qty stays stamped');
        $this->assertSame(1, (int) $printed[0]->kot_batch_no, 'carried stamp keeps its original batch');
        $this->assertEquals(1.0, (float) $unprinted[0]->quantity, 'delta ticket must print ONLY the added qty');

        // System totals unchanged — split rows sum exactly to the line.
        $this->assertEquals(2.0, (float) $items->sum('quantity'));
        $this->assertEquals(200.0, (float) $items->sum('subtotal'));
        $this->assertEquals(200.0, (float) $new->subtotal);
    }

    // ── 2. Decrease/remove → NO unprinted rows, no phantom KOT ──────────

    public function test_qty_decrease_creates_no_unprinted_rows(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold(['items' => [$this->bottle(2)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);

        $res2 = $this->hold([
            'items' => [$this->bottle(1)],
            'order_type' => 'dine_in',
            'table_id' => $tableId,
            'recalled_order_id' => $oldId,
        ]);
        $this->assertSame(200, $res2->getStatusCode(), $res2->getContent());
        $newId = json_decode($res2->getContent(), true)['order']['id'];

        $items = RestaurantOrderItem::where('order_id', $newId)->get();
        $this->assertCount(1, $items);
        $this->assertNotNull($items[0]->kot_printed_at, 'decreased line stays fully stamped');
        $this->assertEquals(1.0, (float) $items[0]->quantity);
        $this->assertSame(0, $items->whereNull('kot_printed_at')->count(), 'no delta row → no phantom KOT on decrease');
    }

    public function test_item_removed_entirely_no_unprinted_rows(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold([
            'items' => [$this->bottle(1), ['item_type' => 'manual', 'item_name' => 'Fries', 'unit_price' => 50, 'quantity' => 1]],
            'order_type' => 'dine_in', 'table_id' => $tableId,
        ]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);

        // Fries removed; Bottle unchanged.
        $res2 = $this->hold([
            'items' => [$this->bottle(1)],
            'order_type' => 'dine_in',
            'table_id' => $tableId,
            'recalled_order_id' => $oldId,
        ]);
        $newId = json_decode($res2->getContent(), true)['order']['id'];

        $items = RestaurantOrderItem::where('order_id', $newId)->get();
        $this->assertCount(1, $items);
        $this->assertSame(0, $items->whereNull('kot_printed_at')->count(), 'removing an item must not un-stamp anything');
    }

    // ── 3. Unchanged line = single row, stamp + batch preserved ─────────

    public function test_unchanged_line_stays_single_stamped_row(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold(['items' => [$this->bottle(2)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 3);

        $res2 = $this->hold([
            'items' => [$this->bottle(2)],
            'order_type' => 'dine_in',
            'table_id' => $tableId,
            'recalled_order_id' => $oldId,
        ]);
        $newId = json_decode($res2->getContent(), true)['order']['id'];

        $items = RestaurantOrderItem::where('order_id', $newId)->get();
        $this->assertCount(1, $items, 'unchanged line must NOT split');
        $this->assertNotNull($items[0]->kot_printed_at);
        $this->assertSame(3, (int) $items[0]->kot_batch_no);
        $this->assertEquals(2.0, (float) $items[0]->quantity);
        $this->assertEquals(200.0, (float) $items[0]->subtotal);
    }

    // ── 4. Money split: discounts prorated, rows sum EXACTLY to the line ─

    public function test_percentage_discount_split_rows_sum_exactly(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        // 10% line discount, qty 3 @ 100 → subtotal 270, discount 30.
        $res1 = $this->hold([
            'items' => [$this->bottle(3, ['item_discount_type' => 'percentage', 'item_discount_value' => 10])],
            'order_type' => 'dine_in', 'table_id' => $tableId,
        ]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);

        // Bump to 5 → line subtotal 450, discount 50. Split 3 (sent) + 2 (delta).
        $res2 = $this->hold([
            'items' => [$this->bottle(5, ['item_discount_type' => 'percentage', 'item_discount_value' => 10])],
            'order_type' => 'dine_in',
            'table_id' => $tableId,
            'recalled_order_id' => $oldId,
        ]);
        $newId = json_decode($res2->getContent(), true)['order']['id'];

        $items = RestaurantOrderItem::where('order_id', $newId)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertEquals(5.0, (float) $items->sum('quantity'));
        $this->assertEquals(450.0, (float) $items->sum('subtotal'), 'split rows must sum EXACTLY to the discounted line');
        $this->assertEquals(50.0, (float) $items->sum('item_discount_amount'));
        // Percentage value stays per-row (rate, not amount) — recall re-applies it per line.
        $this->assertEquals([10.0, 10.0], $items->map(fn ($i) => (float) $i->item_discount_value)->all());
        $delta = $items->whereNull('kot_printed_at')->values();
        $this->assertCount(1, $delta);
        $this->assertEquals(2.0, (float) $delta[0]->quantity, 'delta = added qty only');
    }

    public function test_amount_discount_value_splits_so_recall_never_doubles_it(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        // Rs 50 line discount, qty 2 @ 100 → subtotal 150.
        $res1 = $this->hold([
            'items' => [$this->bottle(2, ['item_discount_type' => 'amount', 'item_discount_value' => 50])],
            'order_type' => 'dine_in', 'table_id' => $tableId,
        ]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);

        $res2 = $this->hold([
            'items' => [$this->bottle(4, ['item_discount_type' => 'amount', 'item_discount_value' => 50])],
            'order_type' => 'dine_in',
            'table_id' => $tableId,
            'recalled_order_id' => $oldId,
        ]);
        $newId = json_decode($res2->getContent(), true)['order']['id'];

        $items = RestaurantOrderItem::where('order_id', $newId)->get();
        $this->assertCount(2, $items);
        // 4 @ 100 - 50 = 350; amount VALUE prorated across rows so the next
        // recall re-hold (rows → cart lines 1:1) reproduces Rs 50 total, not 100.
        $this->assertEquals(350.0, (float) $items->sum('subtotal'));
        $this->assertEquals(50.0, (float) $items->sum('item_discount_amount'));
        $this->assertEquals(50.0, (float) $items->sum('item_discount_value'), 'amount value must split — full value on both rows would double on next re-hold');
    }

    // ── 5. Duplicate identical lines keep the shared-pool shift behaviour ─

    public function test_duplicate_identical_lines_share_the_printed_pool(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold(['items' => [$this->bottle(1)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);

        // Recall produces one cart line; cashier adds a SECOND separate Bottle line.
        $res2 = $this->hold([
            'items' => [$this->bottle(1), $this->bottle(1)],
            'order_type' => 'dine_in',
            'table_id' => $tableId,
            'recalled_order_id' => $oldId,
        ]);
        $newId = json_decode($res2->getContent(), true)['order']['id'];

        $items = RestaurantOrderItem::where('order_id', $newId)->get();
        $this->assertCount(2, $items);
        $this->assertSame(1, $items->whereNotNull('kot_printed_at')->count(), 'exactly ONE line claims the printed chunk');
        $this->assertSame(1, $items->whereNull('kot_printed_at')->count(), 'the other prints as the delta');
        $this->assertEquals(2.0, (float) $items->sum('quantity'));
    }

    // ── 6. Second append keeps identity AND per-batch chunks ────────────

    public function test_second_append_carries_both_batches_and_same_number(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold(['items' => [$this->bottle(1)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $id1 = json_decode($res1->getContent(), true)['order']['id'];
        $origNumber = RestaurantOrder::find($id1)->order_number;
        $this->stampPrinted($id1, 1);

        $res2 = $this->hold([
            'items' => [$this->bottle(2)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $id1,
        ]);
        $id2 = json_decode($res2->getContent(), true)['order']['id'];
        $this->stampPrinted($id2, 2); // delta KOT #2 printed the added "1"

        $res3 = $this->hold([
            'items' => [$this->bottle(3)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $id2,
        ]);
        $this->assertSame(200, $res3->getStatusCode(), $res3->getContent());
        $id3 = json_decode($res3->getContent(), true)['order']['id'];

        $order3 = RestaurantOrder::find($id3);
        $this->assertSame($origNumber, $order3->order_number, 'identity survives repeated appends');
        $this->assertSame(3, (int) $order3->kot_print_count);

        $items = RestaurantOrderItem::where('order_id', $id3)->orderBy('id')->get();
        $this->assertCount(3, $items, 'batch-1 chunk + batch-2 chunk + new delta');
        $this->assertEquals([1, 2], $items->whereNotNull('kot_printed_at')->pluck('kot_batch_no')->map(fn ($b) => (int) $b)->sort()->values()->all());
        $delta = $items->whereNull('kot_printed_at')->values();
        $this->assertCount(1, $delta);
        $this->assertEquals(1.0, (float) $delta[0]->quantity, 'third slip again prints only the newly added 1');
        $this->assertEquals(3.0, (float) $items->sum('quantity'));
    }

    // ── 7. Ticket render: markers on delta vs full-mode update slips ────

    private function renderTicket(array $overrides = []): string
    {
        $company = Company::first() ?: $this->makeCompany();
        $order = new RestaurantOrder([
            'order_number' => 'ORD-260815-TEST1',
            'order_type' => 'dine_in',
        ]);
        $order->created_at = now();
        $order->kot_print_count = 2;
        $order->priority = false;
        $order->setRelation('table', null);
        $order->setRelation('creator', null);

        $row = new RestaurantOrderItem([
            'item_type' => 'manual', 'item_name' => 'Bottle',
            'quantity' => 1, 'unit_price' => 100,
        ]);
        $row->id = 991;
        $items = collect([$row]);

        return view('pos.restaurant.kitchen-ticket', array_merge([
            'order' => $order,
            'company' => $company,
            'ticketItems' => $items,
            'grouped' => collect(['ALL' => $items]),
            'stationLabel' => null,
            'delta' => true,
            'kotBatchNo' => 2,
            'newItemIds' => collect(),
        ], $overrides))->render();
    }

    public function test_delta_slip_renders_addon_marker_with_same_order_number(): void
    {
        $this->makeCompany();
        app()->setLocale('en');
        $html = $this->renderTicket();

        $this->assertStringContainsString('ORD-260815-TEST1', $html, 'update slip carries the SAME order number');
        $this->assertStringContainsString('KOT #2', $html);
        $this->assertStringContainsString(__('pos.kot_addon_marker'), $html, 'delta slip is marked as an add-on');
        $this->assertStringNotContainsString(__('pos.kot_updated_banner'), $html, 'full-ticket banner is a full-mode thing');
    }

    public function test_full_mode_update_ticket_renders_updated_banner_and_new_tags(): void
    {
        $this->makeCompany();
        app()->setLocale('en');
        $html = $this->renderTicket(['newItemIds' => collect([991])]);

        $this->assertStringContainsString(__('pos.kot_updated_banner'), $html, 'full-mode update ticket must be clearly marked UPDATED');
        $this->assertStringContainsString(__('pos.kot_new_tag'), $html, 'genuinely new rows are tagged NEW');
        $this->assertStringNotContainsString(__('pos.kot_addon_marker'), $html, 'ADD-ON marker is for added-qty-only slips');
    }

    // ── 8. CUSTOMER BILL: split rows merge back into ONE line ───────────

    public function test_pay_after_qty_increase_bills_one_consolidated_line(): void
    {
        $c = $this->makeCompany();
        $this->subscribe($c);
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        // Pizza Master scenario up to the delta split…
        $res1 = $this->hold(['items' => [$this->bottle(1)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);
        $res2 = $this->hold([
            'items' => [$this->bottle(2)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $oldId,
        ]);
        $newId = json_decode($res2->getContent(), true)['order']['id'];
        $this->assertSame(2, DB::table('restaurant_order_items')->where('order_id', $newId)->count(), 'precondition: KOT split rows exist');

        // …then the customer pays. The bill must NOT show "Bottle 1 / Bottle 1".
        $payReq = Request::create("/pos/restaurant/orders/{$newId}/pay", 'POST', ['payment_method' => 'cash']);
        $payRes = app(RestaurantPosController::class)->payOrder($payReq, $newId);
        $this->assertSame(200, $payRes->getStatusCode(), $payRes->getContent());

        $txId = RestaurantOrder::find($newId)->pos_transaction_id;
        $billLines = DB::table('pos_transaction_items')->where('transaction_id', $txId)->get();
        $this->assertCount(1, $billLines, 'customer bill must show ONE line per dish, not the KOT split rows');
        $this->assertEquals(2.0, (float) $billLines[0]->quantity, 'merged line carries the cumulative qty');
        $this->assertEquals(200.0, (float) $billLines[0]->subtotal);
        $this->assertEquals(200.0, (float) DB::table('pos_transactions')->where('id', $txId)->value('subtotal'));
        // Kitchen bookkeeping rows stay split underneath (delta history intact).
        $this->assertSame(2, DB::table('restaurant_order_items')->where('order_id', $newId)->count());
    }

    public function test_proof_bill_renders_split_rows_as_one_line(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold(['items' => [$this->bottle(1)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);
        $res2 = $this->hold([
            'items' => [$this->bottle(2)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $oldId,
        ]);
        $newId = json_decode($res2->getContent(), true)['order']['id'];

        app()->setLocale('en');
        $html = app(RestaurantPosController::class)->proofBill($newId)->render();
        $this->assertSame(1, substr_count($html, 'Bottle'), 'pre-bill must list the dish ONCE with merged qty');
    }

    public function test_agent_proof_job_renders_split_rows_as_one_line(): void
    {
        // Silent-print path: shops with the Desktop Agent print proof bills via
        // AgentController::printJobContent (type=proof) — it must consolidate
        // exactly like the iframe route or agent shops still see double lines.
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold(['items' => [$this->bottle(1)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);
        $res2 = $this->hold([
            'items' => [$this->bottle(2)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $oldId,
        ]);
        $newId = json_decode($res2->getContent(), true)['order']['id'];
        $this->assertSame(2, DB::table('restaurant_order_items')->where('order_id', $newId)->count(), 'precondition: KOT split rows exist');

        $jobId = DB::table('pos_print_jobs')->insertGetId([
            'company_id' => $c->id, 'type' => 'proof',
            'restaurant_order_id' => $newId, 'status' => 'claimed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $req = Request::create("/api/agent/print-jobs/{$jobId}/content", 'GET');
        $req->attributes->set('agent_company', $c);
        $res = app(\App\Http\Controllers\AgentController::class)->printJobContent($req, $jobId);
        $this->assertSame(200, $res->getStatusCode());
        $html = $res->getContent();
        $this->assertSame(1, substr_count($html, 'Bottle'), 'agent proof job must list the dish ONCE with merged qty');
    }

    // ── 9. Task 794: VOID slip — kitchen told to STOP removed printed dishes ─

    /** Decode the void_items payload baked into the iframe fallback URL. */
    private function decodeVoidUrl(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $items = json_decode(base64_decode((string) ($q['void_items'] ?? '')), true);
        $this->assertIsArray($items, 'void_items must decode to a JSON array');
        return $items;
    }

    public function test_qty_decrease_emits_void_slip_for_removed_printed_qty(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold(['items' => [$this->bottle(3)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1); // kitchen already fired 3

        $res2 = $this->hold([
            'items' => [$this->bottle(1)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $oldId,
        ]);
        $this->assertSame(200, $res2->getStatusCode(), $res2->getContent());
        $data = json_decode($res2->getContent(), true);

        $this->assertNotNull($data['kot_void_url'], 'decreased printed qty must produce a void slip URL');
        $this->assertFalse($data['kot_void_queued'], 'agent disabled → not queued, client falls back to iframe');
        $items = $this->decodeVoidUrl($data['kot_void_url']);
        $this->assertCount(1, $items);
        $this->assertSame('Bottle', $items[0]['item_name']);
        $this->assertEquals(2.0, (float) $items[0]['qty'], 'void slip lists ONLY the removed qty (3 sent - 1 kept)');
    }

    public function test_item_removed_entirely_emits_void_slip_for_that_item(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold([
            'items' => [$this->bottle(1), ['item_type' => 'manual', 'item_name' => 'Fries', 'unit_price' => 50, 'quantity' => 2]],
            'order_type' => 'dine_in', 'table_id' => $tableId,
        ]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);

        // Fries dropped; Bottle unchanged → void slip lists ONLY Fries.
        $res2 = $this->hold([
            'items' => [$this->bottle(1)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $oldId,
        ]);
        $data = json_decode($res2->getContent(), true);

        $this->assertNotNull($data['kot_void_url']);
        $items = $this->decodeVoidUrl($data['kot_void_url']);
        $this->assertCount(1, $items, 'unchanged Bottle must NOT appear on the void slip');
        $this->assertSame('Fries', $items[0]['item_name']);
        $this->assertEquals(2.0, (float) $items[0]['qty']);
    }

    public function test_no_void_slip_on_fresh_hold_or_pure_increase(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        // Fresh hold — nothing printed yet, nothing to void.
        $res1 = $this->hold(['items' => [$this->bottle(2)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $data1 = json_decode($res1->getContent(), true);
        $this->assertNull($data1['kot_void_url'], 'fresh hold must not void anything');
        $this->assertFalse($data1['kot_void_queued']);

        $oldId = $data1['order']['id'];
        $this->stampPrinted($oldId, 1);

        // Pure increase — every printed chunk re-claimed; delta prints, no void.
        $res2 = $this->hold([
            'items' => [$this->bottle(3)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $oldId,
        ]);
        $data2 = json_decode($res2->getContent(), true);
        $this->assertNull($data2['kot_void_url'], 'qty increase must keep printing normal delta slips, never a void');
        $newId = $data2['order']['id'];
        $this->assertSame(1, RestaurantOrderItem::where('order_id', $newId)->whereNull('kot_printed_at')->count(), 'delta row still created on increase');
    }

    public function test_unprinted_removed_item_does_not_void(): void
    {
        // Only PRINTED qty voids — removing a dish the kitchen never saw
        // (recall before any KOT) must stay silent.
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold([
            'items' => [$this->bottle(1), ['item_type' => 'manual', 'item_name' => 'Fries', 'unit_price' => 50, 'quantity' => 1]],
            'order_type' => 'dine_in', 'table_id' => $tableId,
        ]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        // NO stampPrinted — kitchen never got a slip.

        $res2 = $this->hold([
            'items' => [$this->bottle(1)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $oldId,
        ]);
        $data = json_decode($res2->getContent(), true);
        $this->assertNull($data['kot_void_url'], 'nothing printed was removed → no void slip');
    }

    public function test_agent_enabled_queues_kot_void_print_job(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);
        DB::table('companies')->where('id', $c->id)->update([
            'agent_enabled' => true,
            'agent_last_seen' => now(),
            'pos_printer_settings' => json_encode(['silent_print_enabled' => true, 'kot_printer' => 'Kitchen-1']),
        ]);

        $res1 = $this->hold(['items' => [$this->bottle(2)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);

        $res2 = $this->hold([
            'items' => [$this->bottle(1)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $oldId,
        ]);
        $data = json_decode($res2->getContent(), true);
        $this->assertTrue($data['kot_void_queued'], 'agent online + silent print ON → server queues the void job');

        $job = DB::table('pos_print_jobs')->where('type', 'kot_void')->first();
        $this->assertNotNull($job, 'kot_void print job row must exist');
        $this->assertSame('Kitchen-1', $job->target_printer);
        $this->assertSame('pending', $job->status);
        $payload = json_decode($job->render_query, true);
        $this->assertCount(1, $payload);
        $this->assertSame('Bottle', $payload[0]['item_name']);
        $this->assertEquals(1.0, (float) $payload[0]['qty']);
    }

    public function test_agent_kot_void_job_renders_void_slip(): void
    {
        // Desktop Agent path end-to-end: printJobContent must render the same
        // void slip HTML the iframe route serves.
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold(['items' => [$this->bottle(1)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $orderId = json_decode($res1->getContent(), true)['order']['id'];

        $jobId = DB::table('pos_print_jobs')->insertGetId([
            'company_id' => $c->id, 'type' => 'kot_void',
            'restaurant_order_id' => $orderId, 'status' => 'claimed',
            'render_query' => json_encode([['item_type' => 'manual', 'item_id' => null, 'item_name' => 'Fries', 'notes' => '', 'qty' => 2]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->setLocale('en');
        $req = Request::create("/api/agent/print-jobs/{$jobId}/content", 'GET');
        $req->attributes->set('agent_company', $c);
        $res = app(\App\Http\Controllers\AgentController::class)->printJobContent($req, $jobId);
        $this->assertSame(200, $res->getStatusCode());
        $html = $res->getContent();
        $this->assertStringContainsString(__('pos.kot_void_header'), $html);
        $this->assertStringContainsString('Fries', $html);
        $this->assertStringContainsString('const ticketHasItems = true', $html, 'auto-print blank-guard must count void items or the slip never prints');
    }

    public function test_void_ticket_route_renders_slim_bold_marker_no_normal_sections(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold(['items' => [$this->bottle(2)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);
        $res2 = $this->hold([
            'items' => [$this->bottle(1)], 'order_type' => 'dine_in',
            'table_id' => $tableId, 'recalled_order_id' => $oldId,
        ]);
        $data = json_decode($res2->getContent(), true);
        $newId = $data['order']['id'];
        parse_str((string) parse_url($data['kot_void_url'], PHP_URL_QUERY), $q);

        app()->setLocale('en');
        $req = Request::create($data['kot_void_url'], 'GET', ['void_items' => $q['void_items']]);
        $res = app(RestaurantPosController::class)->voidTicket($req, $newId);
        $html = $res->render();

        $this->assertStringContainsString(__('pos.kot_void_header'), $html);
        $this->assertStringContainsString(__('pos.kot_void_subline'), $html);
        $this->assertStringContainsString('Bottle', $html);
        // Same order identity as the running order (kitchen pairs the slips).
        $this->assertStringContainsString(RestaurantOrder::find($newId)->order_number, $html);
        // Normal-KOT sections stay off a void slip.
        $this->assertStringNotContainsString(__('pos.kot_updated_banner'), $html);
        $this->assertStringNotContainsString(__('pos.kot_no_items_counter'), $html);
        // Thermal-safe marker: slim bold lines only — no reversed white-on-black
        // block (renders as an empty box on ESC/POS printers).
        $this->assertStringNotContainsString('background: #000', $html);
        $this->assertStringNotContainsString('background:#000', $html);
    }

    // ── 10. Task 840: WHOLE-ORDER CANCEL void slip ───────────────────────

    /** Helper: cancel a held order via deleteOrder. */
    private function cancel(int $orderId, array $extra = [])
    {
        $request = Request::create("/pos/restaurant/orders/{$orderId}/delete", 'POST', $extra);
        return app(RestaurantPosController::class)->deleteOrder($request, $orderId);
    }

    public function test_cancel_after_kot_emits_void_slip_for_all_printed_items(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res = $this->hold([
            'items' => [
                $this->bottle(2),
                ['item_type' => 'manual', 'item_name' => 'Fries', 'unit_price' => 50, 'quantity' => 1],
            ],
            'order_type' => 'dine_in', 'table_id' => $tableId,
        ]);
        $orderId = json_decode($res->getContent(), true)['order']['id'];
        $this->stampPrinted($orderId, 1); // KOT already fired all items

        $del = $this->cancel($orderId);
        $this->assertSame(200, $del->getStatusCode(), $del->getContent());
        $data = json_decode($del->getContent(), true);

        $this->assertTrue($data['success'], 'cancel must succeed');
        $this->assertNotNull($data['kot_void_url'], 'cancelling after KOT must produce a void slip URL');
        $this->assertFalse($data['kot_void_queued'], 'agent disabled → not queued; client uses iframe fallback');

        $items = $this->decodeVoidUrl($data['kot_void_url']);
        $this->assertCount(2, $items, 'void slip must list ALL printed items');
        $names = array_column($items, 'item_name');
        $this->assertContains('Bottle', $names);
        $this->assertContains('Fries', $names);

        $bottle = collect($items)->firstWhere('item_name', 'Bottle');
        $this->assertEquals(2.0, (float) $bottle['qty'], 'void qty must match the printed qty');

        // Order itself must be soft-cancelled.
        $this->assertSame('cancelled', RestaurantOrder::find($orderId)->status);
    }

    public function test_cancel_before_kot_emits_no_void_slip(): void
    {
        // Fresh hold cancelled before any KOT — nothing printed, nothing to void.
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res = $this->hold(['items' => [$this->bottle(1)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $orderId = json_decode($res->getContent(), true)['order']['id'];
        // NO stampPrinted — KOT never printed.

        $del = $this->cancel($orderId);
        $this->assertSame(200, $del->getStatusCode());
        $data = json_decode($del->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertNull($data['kot_void_url'], 'no KOT fired → no void slip needed');
        $this->assertFalse($data['kot_void_queued']);
    }

    public function test_cancel_with_agent_online_queues_kot_void_job(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);
        DB::table('companies')->where('id', $c->id)->update([
            'agent_enabled'        => true,
            'agent_last_seen'      => now(),
            'pos_printer_settings' => json_encode(['silent_print_enabled' => true, 'kot_printer' => 'Kitchen-1']),
        ]);

        $res = $this->hold(['items' => [$this->bottle(1)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $orderId = json_decode($res->getContent(), true)['order']['id'];
        $this->stampPrinted($orderId, 1);

        // Re-fetch company so agent_enabled/pos_printer_settings are loaded fresh.
        $c->refresh();
        $del = $this->cancel($orderId);
        $data = json_decode($del->getContent(), true);

        $this->assertTrue($data['kot_void_queued'], 'agent online + silent print ON → server queues the void job');
        $job = DB::table('pos_print_jobs')->where('type', 'kot_void')->first();
        $this->assertNotNull($job, 'kot_void print job must exist');
        $this->assertSame('Kitchen-1', $job->target_printer);
        $payload = json_decode($job->render_query, true);
        $this->assertCount(1, $payload);
        $this->assertSame('Bottle', $payload[0]['item_name']);
        $this->assertEquals(1.0, (float) $payload[0]['qty']);
    }

    // ── 11. Waiter void-slip invariants ──────────────────────────────────────

    /**
     * Waiter appendItems is ADDITIVE ONLY — it can never remove a fired dish,
     * so the void-slip path is never entered. Confirm that calling appendItems
     * on an order that already has printed items creates zero kot_void print
     * jobs and the response carries no void URL.
     *
     * This is the "waiter edits" regression: the waiter app has no endpoint to
     * remove individual items from a running order; only appendItems (add) and
     * cancelOrder (whole-order cancel) exist. Task 794's void detection lives
     * exclusively in holdOrder's printed carry-pool — the appendItems path
     * never touches that pool, so it is structurally impossible for it to fire
     * a void slip.
     */
    public function test_waiter_append_items_does_not_fire_void_slip(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c); // set pos guard; appendItems uses auth('pos')->user()
        $tableId = $this->makeTable($c);

        // 1. Create a held order with a PRINTED item — kitchen already got the KOT.
        $order = RestaurantOrder::create([
            'company_id'      => $c->id,
            'order_number'    => 'ORD-TEST-APPEND',
            'table_id'        => $tableId,
            'order_type'      => 'dine_in',
            'status'          => 'held',
            'source'          => 'waiter',
            'subtotal'        => 100,
            'total_amount'    => 100,
            'created_by'      => Auth::guard('pos')->id(),
            'kot_sent_at'     => now(),
            'kot_print_count' => 1,
        ]);
        RestaurantOrderItem::create([
            'order_id'       => $order->id,
            'item_type'      => 'manual',
            'item_name'      => 'Bottle',
            'quantity'       => 1,
            'unit_price'     => 100,
            'subtotal'       => 100,
            'kot_printed_at' => now(),
            'kot_batch_no'   => 1,
        ]);

        // 2. Waiter APPENDS a new item (additive — Bottle stays untouched).
        cache()->flush();
        $req = \Illuminate\Http\Request::create(
            '/pos/waiter/orders/' . $order->id . '/append', 'POST',
            ['items' => [['name' => 'Chai', 'quantity' => 2, 'unit_price' => 50, 'item_id' => null]]]
        );
        $res = app(\App\Http\Controllers\RestaurantWaiterController::class)->appendItems($req, $order->id);

        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertTrue(json_decode($res->getContent(), true)['success']);

        // 3. Zero kot_void jobs must ever be created by the append path.
        $voidJobs = DB::table('pos_print_jobs')->where('type', 'kot_void')->count();
        $this->assertSame(0, $voidJobs, 'appendItems is additive-only — no void slip must be fired');

        // 4. The printed Bottle is still there; the new Chai is unprinted (ready for delta KOT).
        $items = RestaurantOrderItem::where('order_id', $order->id)->get();
        $this->assertCount(2, $items);
        $this->assertSame(1, $items->whereNotNull('kot_printed_at')->count(), 'Bottle stamp intact');
        $this->assertSame(1, $items->whereNull('kot_printed_at')->count(), 'Chai queued for delta KOT');
        $this->assertSame('Chai', $items->whereNull('kot_printed_at')->first()->item_name);
    }

    /**
     * When a CASHIER recalls a waiter-originated order (source='waiter') and
     * removes a dish that was already printed, the void slip IS fired through
     * holdOrder's carry-pool logic — the waiter source flag has no effect on
     * void detection (it runs on EVERY recalled re-hold with leftover printed
     * chunks). This proves the food-waste hole is closed for waiter orders too.
     */
    public function test_cashier_recall_of_waiter_sourced_order_with_item_removal_fires_void(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        // 1. Create an order and mark it as waiter-sourced (simulates a waiter punch).
        $res1 = $this->hold([
            'items'      => [$this->bottle(1), ['item_type' => 'manual', 'item_name' => 'Fries', 'unit_price' => 50, 'quantity' => 2]],
            'order_type' => 'dine_in',
            'table_id'   => $tableId,
        ]);
        $this->assertSame(200, $res1->getStatusCode());
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        // Mark as waiter-sourced to prove source doesn't affect void detection.
        DB::table('restaurant_orders')->where('id', $oldId)->update(['source' => 'waiter']);
        $this->stampPrinted($oldId, 1); // kitchen already fired Bottle + Fries

        // 2. Cashier recalls the waiter order and REMOVES the Fries (Bottle kept).
        $res2 = $this->hold([
            'items'              => [$this->bottle(1)],
            'order_type'         => 'dine_in',
            'table_id'           => $tableId,
            'recalled_order_id'  => $oldId,
        ]);
        $this->assertSame(200, $res2->getStatusCode(), $res2->getContent());
        $data = json_decode($res2->getContent(), true);

        // 3. Void was detected: a non-null URL proves the carry pool found the
        //    removed Fries qty and built a void payload for the kitchen.
        $this->assertNotNull(
            $data['kot_void_url'],
            'removing a printed dish from a waiter-sourced order must produce a void slip URL'
        );

        // 4. The void payload must encode ONLY the removed Fries (not the Bottle).
        $voidItems = $this->decodeVoidUrl($data['kot_void_url']);
        $this->assertCount(1, $voidItems, 'unchanged Bottle must NOT appear on the void slip');
        $this->assertSame('Fries', $voidItems[0]['item_name']);
        $this->assertEquals(2.0, (float) $voidItems[0]['qty'], 'full printed Fries qty must be voided');
    }

    // ── 12. Task 841: void_items persisted on order for KDS cancelled badge ─

    public function test_void_items_persisted_on_replacement_order_for_kds(): void
    {
        // When printed qty is removed (decrease or full removal), the replacement
        // order must carry void_items so the KDS board can show a CANCELLED badge.
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res1 = $this->hold([
            'items' => [
                $this->bottle(2),
                ['item_type' => 'manual', 'item_name' => 'Fries', 'unit_price' => 50, 'quantity' => 1],
            ],
            'order_type' => 'dine_in', 'table_id' => $tableId,
        ]);
        $oldId = json_decode($res1->getContent(), true)['order']['id'];
        $this->stampPrinted($oldId, 1);

        // Remove Fries entirely; keep 1 Bottle (decrease from 2).
        $res2 = $this->hold([
            'items' => [$this->bottle(1)],
            'order_type' => 'dine_in',
            'table_id' => $tableId,
            'recalled_order_id' => $oldId,
        ]);
        $this->assertSame(200, $res2->getStatusCode(), $res2->getContent());
        $newId = json_decode($res2->getContent(), true)['order']['id'];

        $order = RestaurantOrder::find($newId);
        $this->assertNotNull($order->void_items, 'void_items must be persisted when printed dishes are removed');
        $vi = json_decode($order->void_items, true);
        $this->assertIsArray($vi);
        $this->assertCount(2, $vi, 'both the decreased Bottle qty and the removed Fries must appear');

        $names = array_column($vi, 'item_name');
        $this->assertContains('Bottle', $names);
        $this->assertContains('Fries', $names);

        $bottleEntry = collect($vi)->firstWhere('item_name', 'Bottle');
        $this->assertEquals(1.0, (float) $bottleEntry['qty'], 'Bottle: 2 sent - 1 kept = 1 voided');
        $friesEntry = collect($vi)->firstWhere('item_name', 'Fries');
        $this->assertEquals(1.0, (float) $friesEntry['qty'], 'Fries: entirely removed = full qty voided');
    }

    public function test_void_items_null_on_fresh_hold_and_pure_increase(): void
    {
        // Fresh holds and pure increases must NOT set void_items — the KDS badge
        // must stay hidden when nothing was cancelled.
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        // Fresh hold (no prior printed rows).
        $res1 = $this->hold(['items' => [$this->bottle(2)], 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $id1 = json_decode($res1->getContent(), true)['order']['id'];
        $this->assertNull(RestaurantOrder::find($id1)->void_items, 'fresh hold must not set void_items');

        $this->stampPrinted($id1, 1);

        // Pure increase — every printed chunk re-claimed; nothing voided.
        $res2 = $this->hold([
            'items' => [$this->bottle(3)],
            'order_type' => 'dine_in',
            'table_id' => $tableId,
            'recalled_order_id' => $id1,
        ]);
        $id2 = json_decode($res2->getContent(), true)['order']['id'];
        $this->assertNull(RestaurantOrder::find($id2)->void_items, 'pure qty increase must not set void_items');
    }

    public function test_pay_with_distinct_lines_keeps_them_separate(): void
    {
        // Genuinely different lines (different notes) must NOT merge on the bill.
        $c = $this->makeCompany();
        $this->subscribe($c);
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        $res = $this->hold([
            'items' => [
                $this->bottle(1, ['special_notes' => 'chilled']),
                $this->bottle(1),
            ],
            'order_type' => 'dine_in', 'table_id' => $tableId,
        ]);
        $orderId = json_decode($res->getContent(), true)['order']['id'];

        $payReq = Request::create("/pos/restaurant/orders/{$orderId}/pay", 'POST', ['payment_method' => 'cash']);
        $payRes = app(RestaurantPosController::class)->payOrder($payReq, $orderId);
        $this->assertSame(200, $payRes->getStatusCode(), $payRes->getContent());

        $txId = RestaurantOrder::find($orderId)->pos_transaction_id;
        $this->assertSame(2, DB::table('pos_transaction_items')->where('transaction_id', $txId)->count(), 'distinct notes = distinct bill lines');
    }
}
