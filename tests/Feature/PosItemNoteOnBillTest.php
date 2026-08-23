<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Services\PosBusinessDay;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PER-ITEM COMMENT MUST REACH THE CUSTOMER BILL (owner voice note, 23 Aug 2026).
 *
 * The cashier writes a per-line comment on the sale screen ("mirch tez", "no
 * onions") with the pen icon on the cart row. It printed on the KITCHEN ticket
 * and then vanished: the customer's receipt never showed it. Two separate
 * breaks, both locked here:
 *
 *   1. STORAGE — RestaurantPosController::payOrder built each
 *      pos_transaction_items row WITHOUT special_notes, so for every bill
 *      settled from a held/dine-in order the note was simply never copied out
 *      of restaurant_order_items. No receipt change could have shown it.
 *      (PosController::storeInvoice — the straight cart→bill path — always
 *      mapped it; only the restaurant path dropped it.)
 *
 *   2. PRINTING — neither thermal template rendered the item note, so even a
 *      correctly stored note stayed invisible.
 *
 * Both halves are asserted: the pay endpoint over real HTTP, and both live
 * templates rendered. A regression in either half hides the shop's comment
 * from the customer again.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosItemNoteOnBillTest.php --testdox
 */
class PosItemNoteOnBillTest extends TestCase
{
    private const NOTE = 'mirch tez, pyaz nahi';

    /** Both live receipt templates — the note assertion runs against each. */
    private const TEMPLATES = ['pos.receipts.receipt_80mm', 'pos.receipts.receipt_58mm'];

    protected function setUp(): void
    {
        parent::setUp();

        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->string('status')->nullable();
            $t->string('company_status')->nullable();
            $t->string('default_language')->nullable();
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->integer('user_limit_override')->nullable();
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->boolean('agent_enabled')->default(false);
            $t->boolean('agent_submits_pra')->nullable();
            $t->string('pra_connection_mode')->nullable();
            $t->string('pra_environment')->nullable();
            $t->string('pra_pos_id')->nullable();
            $t->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $t->string('pos_business_day_cutoff')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->decimal('cashier_discount_limit', 8, 2)->nullable();
            $t->string('pos_tax_pricing_mode')->nullable();
            $t->boolean('pos_tax_inclusive')->default(false);
            $t->boolean('restaurant_mode')->default(true);
            $t->boolean('kot_on_final_if_unsent')->default(false);
            $t->boolean('delivery_kot_after_payment')->default(false);
            $t->text('feature_flags')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('language')->nullable();
            $t->boolean('pra_reporting_enabled')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('invoice_number');
            $t->string('business_date')->nullable();
            $t->string('status');
            $t->string('invoice_mode')->nullable();
            $t->string('order_type')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->string('pra_response_code')->nullable();
            $t->text('pra_qr_code')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('archived_by_report_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->nullable();
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('exempt_amount', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->decimal('cash_received', 12, 2)->nullable();
            $t->decimal('change_due', 12, 2)->nullable();
            $t->string('submission_hash')->nullable();
            $t->string('offline_uuid', 64)->nullable();
            $t->boolean('tax_inclusive')->default(false);
            $t->decimal('tax_menu_rate', 8, 2)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_type')->nullable();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->text('special_notes')->nullable();
            $t->text('deal_snapshot')->nullable();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('is_tax_exempt')->default(false);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->nullable();
            $t->string('item_discount_type')->nullable();
            $t->decimal('item_discount_value', 12, 2)->nullable();
            $t->decimal('item_discount_amount', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reference_number')->nullable();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_head_office')->default(false);
            $t->timestamps();
        });

        Schema::create('pra_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->text('request_payload')->nullable();
            $t->text('response_payload')->nullable();
            $t->string('response_code')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->integer('deleted_final_count')->default(0);
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->integer('user_limit')->nullable();
            $t->boolean('restaurant_enabled')->default(true);
            $t->boolean('offline_enabled')->default(true);
            $t->boolean('deals_enabled')->default(false);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('order_number')->nullable();
            $t->unsignedBigInteger('table_id')->nullable();
            $t->string('order_type')->nullable();
            $t->string('status')->nullable();
            $t->string('source')->default('pos');
            $t->unsignedBigInteger('assigned_cashier_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->nullable();
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->text('kitchen_notes')->nullable();
            $t->string('kitchen_status')->nullable();
            $t->timestamp('kot_sent_at')->nullable();
            $t->timestamp('kitchen_cleared_at')->nullable();
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('token_no')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('item_type')->nullable();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->text('special_notes')->nullable();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('is_tax_exempt')->default(false);
            $t->string('item_discount_type')->nullable();
            $t->decimal('item_discount_value', 12, 2)->nullable();
            $t->decimal('item_discount_amount', 12, 2)->nullable();
            $t->timestamp('kot_printed_at')->nullable();
            $t->integer('kot_batch_no')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('table_number')->nullable();
            $t->integer('seats')->default(4);
            $t->string('status')->default('occupied');
            $t->unsignedBigInteger('locked_by_user_id')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamp('occupied_since')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('product_recipes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('ingredient_id');
            $t->decimal('quantity_needed', 12, 4)->default(0);
            $t->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeShop(): int
    {
        $companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Item Note Co',
            'product_type' => 'pos',
            'status' => 'active',
            'company_status' => 'active',
            'is_internal_account' => true,
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            'inventory_enabled' => false,
            'restaurant_mode' => true,
            'kot_on_final_if_unsent' => false,
            'feature_flags' => json_encode(['tables' => true, 'kot' => true, 'kitchen' => true, 'kitchen_notes' => true]),
            'pos_tax_rate_cash' => 16.00,
            'pos_tax_rate_card' => 16.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PosBusinessDay::forgetCutoff($companyId);
        PosFeatureService::flushGateCaches();

        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name' => 'Business',
            'product_type' => 'pos',
            'is_trial' => false,
            'invoice_limit' => -1,
            'restaurant_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'override_type' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $companyId;
    }

    private function makeCashier(int $companyId): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'POS Owner',
            'email' => 'owner' . $companyId . '@taxnest.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'language' => 'en',
            'pra_reporting_enabled' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\User::find($id);
    }

    /** A held dine-in order whose FIRST line carries the cashier's comment. */
    private function makeOrder(int $companyId): int
    {
        $tableId = (int) DB::table('restaurant_tables')->insertGetId([
            'company_id' => $companyId,
            'table_number' => '05',
            'status' => 'occupied',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = (int) DB::table('restaurant_orders')->insertGetId([
            'company_id' => $companyId,
            'order_number' => 'ORD-' . uniqid(),
            'table_id' => $tableId,
            'order_type' => 'dine_in',
            'status' => 'held',
            'source' => 'pos',
            'subtotal' => 300,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total_amount' => 300,
            'kot_sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([['Chicken Karahi', self::NOTE], ['Coke', null]] as $i => [$name, $note]) {
            DB::table('restaurant_order_items')->insert([
                'order_id' => $orderId,
                'item_type' => 'product',
                'item_id' => 9001 + $i,
                'item_name' => $name,
                'special_notes' => $note,
                'quantity' => 1,
                'unit_price' => 150,
                'subtotal' => 150,
                'is_tax_exempt' => false,
                'item_discount_type' => 'percentage',
                'item_discount_value' => 0,
                'item_discount_amount' => 0,
                'kot_printed_at' => now(),
                'kot_batch_no' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $orderId;
    }

    // ── 1. STORAGE: payOrder must carry the note onto the bill line ─────────

    public function test_pay_order_copies_the_item_comment_onto_the_bill_line(): void
    {
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId);

        $res = $this->actingAs($user, 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", ['payment_method' => 'cash']);

        $res->assertOk()->assertJson(['success' => true]);

        $txnId = (int) DB::table('restaurant_orders')->where('id', $orderId)->value('pos_transaction_id');
        $this->assertGreaterThan(0, $txnId, 'paid order must be linked to its bill');

        $noted = DB::table('pos_transaction_items')
            ->where('transaction_id', $txnId)->where('item_name', 'Chicken Karahi')->first();
        $this->assertNotNull($noted, 'the noted line must exist on the bill');
        $this->assertSame(
            self::NOTE,
            $noted->special_notes,
            'the cashier comment must travel from restaurant_order_items to the BILL line — the receipt reads it from here'
        );

        $plain = DB::table('pos_transaction_items')
            ->where('transaction_id', $txnId)->where('item_name', 'Coke')->first();
        $this->assertNull($plain->special_notes, 'a line without a comment must stay clean');
    }

    // ── 2. PRINTING: both thermal templates show the note under its item ────

    public function test_both_receipt_templates_print_the_item_comment(): void
    {
        $company = new Company();
        $company->id = 771;
        $company->name = 'Item Note Co';
        $company->order_match_style = 'off';
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => false, 'bold' => false]];

        $txn = new PosTransaction([
            'invoice_number' => 'INV-000901',
            'order_type' => 'dine_in',
            'payment_method' => 'cash',
            'invoice_mode' => 'pra',
            'pra_status' => null,
            'subtotal' => 300,
            'tax_rate' => 16,
            'tax_amount' => 48,
            'discount_amount' => 0,
            'total_amount' => 348,
        ]);
        $txn->id = 9901;
        $txn->company_id = $company->id;
        $txn->created_at = now();

        $noted = new PosTransactionItem([
            'item_type' => 'product',
            'item_name' => 'Chicken Karahi',
            'special_notes' => self::NOTE,
            'quantity' => 1,
            'unit_price' => 150,
            'subtotal' => 150,
            'is_tax_exempt' => false,
        ]);
        $noted->id = 1;
        $plain = new PosTransactionItem([
            'item_type' => 'product',
            'item_name' => 'Coke',
            'special_notes' => null,
            'quantity' => 1,
            'unit_price' => 150,
            'subtotal' => 150,
            'is_tax_exempt' => false,
        ]);
        $plain->id = 2;

        $txn->setRelation('items', collect([$noted, $plain]));
        $txn->setRelation('payments', collect());
        $txn->setRelation('company', $company);
        $txn->setRelation('terminal', null);
        $txn->setRelation('creator', null);
        $txn->setRelation('rider', null);

        foreach (self::TEMPLATES as $template) {
            $html = view($template, ['transaction' => $txn, 'company' => $company])->render();
            $this->assertStringContainsString(
                self::NOTE,
                $html,
                "$template: the per-item comment must print on the customer bill"
            );
            // The note belongs UNDER its own dish, not glued into the name cell.
            $this->assertStringNotContainsString('Chicken Karahi ' . self::NOTE, $html, "$template: note must be its own row");
        }
    }
}
