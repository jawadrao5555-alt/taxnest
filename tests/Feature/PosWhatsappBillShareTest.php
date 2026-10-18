<?php

namespace Tests\Feature;

use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1036 — WhatsApp Bill share extras: FINAL bills only.
 *
 * Pay responses (PosController::storeInvoice, RestaurantPosController::
 * payOrder) and the Reprint list (apiTodaysBills) expose wa_phone/share_url
 * so the sale screen can open wa.me with the public receipt PDF link.
 * LOCKED INVARIANTS:
 *
 *   1. A deliberate provisional (save_as_provisional → pra_status='local')
 *      NEVER gets wa extras — the bill is still editable/deletable, so a
 *      customer must never receive a public link to it. Applies to BOTH the
 *      normal POS path and the restaurant hold→pay path (shared predicate:
 *      PosTransaction::isDeliberateProvisional inside waBillPayload).
 *   2. Reporting-OFF finals (pra_status NULL, no fiscal) DO get extras —
 *      "local final" is not "provisional" (memory rule
 *      pos-provisional-and-receipt-rules).
 *   3. No routable phone (PkPhone::normalize null) → nulls, never an empty
 *      wa.me target.
 *   4. Company toggle OFF → nulls everywhere.
 *   5. Reprint list rows: wa_phone only on non-provisional rows (badge
 *      'provisional' rows must hide the WhatsApp action).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create, HTTP
 * through the real routes/middleware (same as PosMonthlyBillQuotaPathsTest).
 * Companies stay reporting-OFF — no network is ever attempted.
 */
class PosWhatsappBillShareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->integer('user_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->text('pra_production_token')->nullable();
            $table->string('pra_proxy_url')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $table->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->decimal('cashier_discount_limit', 8, 2)->nullable();
            $table->string('pos_tax_pricing_mode')->nullable();
            $table->boolean('pos_tax_inclusive')->default(false);
            $table->text('feature_flags')->nullable();
            // Task 1036 columns under test.
            $table->boolean('pos_whatsapp_bill_enabled')->default(true);
            $table->boolean('pos_whatsapp_bill_auto_open')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
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
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
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
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_menu_rate', 8, 2)->nullable();
            $table->text('notes')->nullable();
            // publicBillToken()'s hasColumn gate + lazy mint UPDATE need these.
            $table->string('share_token', 64)->nullable();
            $table->timestamp('share_token_created_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->text('special_notes')->nullable();
            $table->text('deal_snapshot')->nullable();
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

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
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

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('kitchen_notes')->nullable();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->string('item_discount_type')->nullable();
            $table->decimal('item_discount_value', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
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
    }

    // ── fixtures (same shape as PosMonthlyBillQuotaPathsTest) ───────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'WA Bill Test Co',
            'product_type' => 'pos',
            'status' => 'active',
            'is_internal_account' => false,
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            'inventory_enabled' => false,
            'pos_tax_rate_cash' => 16.00,
            'pos_tax_rate_card' => 16.00,
            'pos_whatsapp_bill_enabled' => true,
            'pos_whatsapp_bill_auto_open' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function subscribe(int $companyId, array $planAttrs = []): void
    {
        $planId = (int) DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Business',
            'product_type' => 'pos',
            'is_trial' => false,
            'invoice_limit' => -1,
            'user_limit' => null,
            'restaurant_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $planAttrs));

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
    }

    private function makeUser(int $companyId): \App\Models\User
    {
        static $seq = 0;
        $id = DB::table('users')->insertGetId([
            'name' => 'POS Owner',
            'email' => 'owner' . $companyId . '-' . (++$seq) . '@taxnest.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'pos_role' => null,
            'is_active' => true,
            'language' => 'en',
            'pra_reporting_enabled' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\User::find($id);
    }

    private function makeRestaurantCompany(array $attrs = []): int
    {
        $companyId = $this->makeCompany(array_merge([
            'feature_flags' => json_encode(['tables' => true, 'kot' => true, 'kitchen' => true, 'delivery' => true]),
        ], $attrs));
        $this->subscribe($companyId, ['restaurant_enabled' => true]);

        return $companyId;
    }

    private function makeOrder(int $companyId, array $attrs = []): int
    {
        $orderId = (int) DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id' => $companyId,
            'order_number' => 'ORD-001',
            'order_type' => 'delivery',
            'status' => 'pending',
            'customer_phone' => '0300-1234567',
            'delivery_address' => 'Test Street 1',
            'subtotal' => 100,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        DB::table('restaurant_order_items')->insert([
            'order_id' => $orderId,
            'item_type' => 'product',
            'item_id' => 9001, // no recipe rows → stock validation no-op
            'item_name' => 'Karahi',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'is_tax_exempt' => false,
            'item_discount_type' => 'percentage',
            'item_discount_value' => 0,
            'item_discount_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'items' => [[
                'type' => 'product',
                'name' => 'Chai',
                'quantity' => 1,
                'unit_price' => 100,
                'is_tax_exempt' => false,
                '_manual' => 1,
            ]],
            'payment_method' => 'cash',
            'discount_type' => 'amount',
            'discount_value' => 0,
            'customer_phone' => '0300-1234567', // PkPhone → 923001234567
        ], $overrides);
    }

    // ════════════════════════════════════════════════════════════════════════
    // PATH 1 — PosController::storeInvoice
    // ════════════════════════════════════════════════════════════════════════

    public function test_store_invoice_final_with_phone_gets_wa_extras(): void
    {
        $companyId = $this->makeCompany();
        $this->subscribe($companyId);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload());

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('923001234567', $response->json('wa_phone'));
        $shareUrl = (string) $response->json('share_url');
        $this->assertStringContainsString('/pos/invoice/share/', $shareUrl);
        $token = DB::table('pos_transactions')->value('share_token');
        $this->assertNotEmpty($token, 'share token must be minted');
        $this->assertStringContainsString($token, $shareUrl);
    }

    public function test_store_invoice_provisional_never_gets_wa_extras(): void
    {
        // THE review-locked invariant: a deliberate provisional is editable —
        // the customer must never receive a public link to it, even with a
        // routable phone and the feature ON.
        $companyId = $this->makeCompany();
        $this->subscribe($companyId);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload(['save_as_provisional' => true]));

        $response->assertOk()->assertJson(['success' => true]);
        $row = DB::table('pos_transactions')->first();
        $this->assertSame('local', $row->pra_status, 'sanity: provisional flow stamps pra_status=local');
        $this->assertNull($response->json('wa_phone'));
        $this->assertNull($response->json('share_url'));
        $this->assertNull($row->share_token, 'no token may be minted for a provisional');
    }

    public function test_store_invoice_without_routable_phone_gets_nulls(): void
    {
        $companyId = $this->makeCompany();
        $this->subscribe($companyId);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload(['customer_phone' => null]));

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertNull($response->json('wa_phone'));
        $this->assertNull($response->json('share_url'));
    }

    public function test_store_invoice_feature_off_gets_nulls(): void
    {
        $companyId = $this->makeCompany(['pos_whatsapp_bill_enabled' => false]);
        $this->subscribe($companyId);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload());

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertNull($response->json('wa_phone'));
        $this->assertNull($response->json('share_url'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // PATH 2 — RestaurantPosController::payOrder (hold → pay)
    // ════════════════════════════════════════════════════════════════════════

    public function test_restaurant_pay_order_final_gets_wa_extras(): void
    {
        $companyId = $this->makeRestaurantCompany();
        $orderId = $this->makeOrder($companyId);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", ['payment_method' => 'cash']);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('923001234567', $response->json('wa_phone'));
        $this->assertStringContainsString('/pos/invoice/share/', (string) $response->json('share_url'));
    }

    public function test_restaurant_pay_order_provisional_never_gets_wa_extras(): void
    {
        $companyId = $this->makeRestaurantCompany();
        $orderId = $this->makeOrder($companyId);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", [
                'payment_method' => 'cash',
                'save_as_provisional' => true,
            ]);

        $response->assertOk()->assertJson(['success' => true]);
        $row = DB::table('pos_transactions')->first();
        $this->assertSame('local', $row->pra_status, 'sanity: provisional pay stamps pra_status=local');
        $this->assertNull($response->json('wa_phone'));
        $this->assertNull($response->json('share_url'));
        $this->assertNull($row->share_token);
    }

    // ════════════════════════════════════════════════════════════════════════
    // PATH 3 — Reprint list (apiTodaysBills) row visibility
    // ════════════════════════════════════════════════════════════════════════

    public function test_todays_bills_hides_wa_phone_on_provisional_rows_only(): void
    {
        $companyId = $this->makeCompany();
        $this->subscribe($companyId);
        $user = $this->makeUser($companyId);

        // One FINAL and one PROVISIONAL bill, both with routable phones.
        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload())
            ->assertOk();
        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload([
                'save_as_provisional' => true,
                'customer_phone' => '0311-7654321',
            ]))
            ->assertOk();

        $list = $this->actingAs($user, 'pos')->getJson('/pos/api/todays-bills');
        $list->assertOk();
        $rows = collect($list->json('bills') ?? $list->json('data') ?? $list->json());

        $final = $rows->firstWhere('badge', '!=', 'provisional');
        $prov = $rows->firstWhere('badge', 'provisional');
        $this->assertNotNull($final, 'final row expected in reprint list');
        $this->assertNotNull($prov, 'provisional row expected in reprint list');
        $this->assertSame('923001234567', $final['wa_phone'], 'final row keeps its WhatsApp number');
        $this->assertNull($prov['wa_phone'], 'provisional row must never be WhatsApp-shareable');
    }

    // ════════════════════════════════════════════════════════════════════════
    // PATH 4 — generateShareLink endpoint + public PDF route (server-side lock)
    // ════════════════════════════════════════════════════════════════════════

    public function test_share_link_endpoint_refuses_provisional_and_mints_no_token(): void
    {
        // Review-locked: hiding the UI is not enough — an authenticated POST
        // straight to the share-link endpoint must be denied server-side.
        $companyId = $this->makeCompany();
        $this->subscribe($companyId);
        $user = $this->makeUser($companyId);

        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload(['save_as_provisional' => true]))
            ->assertOk();
        $txId = (int) DB::table('pos_transactions')->value('id');

        $response = $this->actingAs($user, 'pos')
            ->postJson("/pos/transaction/{$txId}/share-link");

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertNull($response->json('url'));
        $this->assertNull($response->json('token'));
        $this->assertNull(
            DB::table('pos_transactions')->where('id', $txId)->value('share_token'),
            'no public token may ever be minted for a provisional'
        );
    }

    public function test_share_link_endpoint_refuses_when_feature_disabled(): void
    {
        $companyId = $this->makeCompany(['pos_whatsapp_bill_enabled' => false]);
        $this->subscribe($companyId);
        $user = $this->makeUser($companyId);

        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload())
            ->assertOk();
        $txId = (int) DB::table('pos_transactions')->value('id');

        $this->actingAs($user, 'pos')
            ->postJson("/pos/transaction/{$txId}/share-link")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
        $this->assertNull(DB::table('pos_transactions')->where('id', $txId)->value('share_token'));
    }

    public function test_share_link_endpoint_allows_final_bill(): void
    {
        $companyId = $this->makeCompany();
        $this->subscribe($companyId);
        $user = $this->makeUser($companyId);

        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload())
            ->assertOk();
        $txId = (int) DB::table('pos_transactions')->value('id');

        $response = $this->actingAs($user, 'pos')
            ->postJson("/pos/transaction/{$txId}/share-link");

        $response->assertOk();
        $this->assertStringContainsString('/pos/invoice/share/', (string) $response->json('url'));
    }

    public function test_public_pdf_route_refuses_legacy_provisional_token(): void
    {
        // A token minted BEFORE this hardening (or by data drift) must be dead
        // while the bill is provisional — and start working after promotion.
        $companyId = $this->makeCompany();
        $token = bin2hex(random_bytes(32));
        $txId = (int) DB::table('pos_transactions')->insertGetId([
            'company_id' => $companyId,
            'invoice_number' => 'L-001',
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'local',
            'pra_status' => 'local', // deliberate provisional triple
            'customer_phone' => '0300-1234567',
            'subtotal' => 100,
            'total_amount' => 100,
            'payment_method' => 'cash',
            'share_token' => $token,
            'share_token_created_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // X-Forwarded-Proto passes the ForceHttps middleware (test host is not
        // in its localhost exemption list — same trick the middleware trusts
        // from the live proxy).
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->get("/pos/invoice/share/{$token}")->assertNotFound();

        // Promotion clears the provisional marker → the same token renders.
        DB::table('pos_transactions')->where('id', $txId)
            ->update(['invoice_mode' => 'pra', 'pra_status' => null]);
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->get("/pos/invoice/share/{$token}")->assertOk();
    }

    public function test_todays_bills_feature_off_hides_wa_phone_everywhere(): void
    {
        $companyId = $this->makeCompany(['pos_whatsapp_bill_enabled' => false]);
        $this->subscribe($companyId);
        $user = $this->makeUser($companyId);

        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload())
            ->assertOk();

        $list = $this->actingAs($user, 'pos')->getJson('/pos/api/todays-bills');
        $list->assertOk();
        $rows = collect($list->json('bills') ?? $list->json('data') ?? $list->json());
        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn ($r) => ($r['wa_phone'] ?? null) === null));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Task 1092/1093 — waBillPayload throw AFTER commit: all 4 outer catches
    // must degrade to success:true + null extras, never bubble a 500.
    // The static hook bypasses waBillPayload's own inner try/catch so the
    // outer controller-level \Throwable guard is the one under test.
    // ════════════════════════════════════════════════════════════════════════

    protected function tearDown(): void
    {
        \App\Models\PosTransaction::$__testForceWaBillThrow = false;
        parent::tearDown();
    }

    // ── Outer-catch path 1: PosController::storeInvoice JSON ─────────────

    public function test_store_invoice_wabill_throw_degrades_to_success_with_null_extras(): void
    {
        // Task 1093: even when waBillPayload() throws (deploy-window class skew),
        // an already-committed bill must return success:true — not a 500.
        $companyId = $this->makeCompany();
        $this->subscribe($companyId);
        $user = $this->makeUser($companyId);

        \App\Models\PosTransaction::$__testForceWaBillThrow = true;

        $response = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload());

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertNull($response->json('wa_phone'),   'wa_phone must be null when extras throw');
        $this->assertNull($response->json('share_url'),  'share_url must be null when extras throw');
        // The bill must still have been committed.
        $this->assertDatabaseCount('pos_transactions', 1);
    }

    // ── Outer-catch path 2: PosController::apiPromoteProvisional ─────────

    public function test_promote_provisional_wabill_throw_degrades_to_success_with_null_extras(): void
    {
        // Task 1093: promote commits the bill inside a DB::transaction; the
        // outer waBillPayload catch fires strictly post-commit.
        $companyId = $this->makeCompany();
        $this->subscribe($companyId);
        $user = $this->makeUser($companyId);

        // 1. Create a provisional so there is something to promote.
        $storeResp = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload(['save_as_provisional' => true]));
        $storeResp->assertOk()->assertJson(['success' => true]);
        $txId = (int) DB::table('pos_transactions')->value('id');
        $this->assertNotEmpty($txId);

        // 2. Arm the hook so waBillPayload throws during the promote response.
        \App\Models\PosTransaction::$__testForceWaBillThrow = true;

        $promoteResp = $this->actingAs($user, 'pos')
            ->postJson("/pos/api/provisional-bills/{$txId}/promote", ['send_to_pra' => true]);

        $promoteResp->assertOk()->assertJson(['success' => true]);
        $this->assertNull($promoteResp->json('wa_phone'),  'wa_phone must be null when extras throw');
        $this->assertNull($promoteResp->json('share_url'), 'share_url must be null when extras throw');
        // The bill must have been promoted (pra_status cleared from local).
        $row = DB::table('pos_transactions')->where('id', $txId)->first();
        $this->assertNotSame('local', $row->invoice_mode, 'bill must have been promoted out of local mode');
    }

    // ── Outer-catch path 3: RestaurantPosController::payOrder ─────────────

    public function test_restaurant_pay_order_wabill_throw_degrades_to_success_with_null_extras(): void
    {
        // Task 1093: the bill is committed inside payOrder's DB::transaction;
        // the waBillPayload outer catch fires after the commit.
        $companyId = $this->makeRestaurantCompany();
        $orderId   = $this->makeOrder($companyId);
        $user      = $this->makeUser($companyId);

        \App\Models\PosTransaction::$__testForceWaBillThrow = true;

        $response = $this->actingAs($user, 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", ['payment_method' => 'cash']);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertNull($response->json('wa_phone'),  'wa_phone must be null when extras throw');
        $this->assertNull($response->json('share_url'), 'share_url must be null when extras throw');
        $this->assertDatabaseCount('pos_transactions', 1);
    }

    // ── Outer-catch path 4: RestaurantPosController::replayPayByUuid ──────

    public function test_restaurant_replay_pay_uuid_wabill_throw_degrades_to_success_with_null_extras(): void
    {
        // Task 1093: the replay path returns the ORIGINAL bill's payload with
        // WhatsApp extras. When waBillPayload throws the outer catch must
        // degrade to null extras and still return success:true (replayed=true).
        $companyId = $this->makeRestaurantCompany();
        $orderId   = $this->makeOrder($companyId);
        $user      = $this->makeUser($companyId);

        // Pre-plant a completed transaction with a known offline_uuid so the
        // payOrder handler finds it immediately via replayPayByUuid (before any
        // DB insert) — this is exactly the lost-response retry scenario.
        $replayUuid = 'task-1093-replay-' . uniqid();
        DB::table('pos_transactions')->insert([
            'company_id'     => $companyId,
            'invoice_number' => 'L-REPLAY-001',
            'business_date'  => now()->toDateString(),
            'status'         => 'completed',
            'invoice_mode'   => 'pra',
            'pra_status'     => null,
            'offline_uuid'   => $replayUuid,
            'customer_phone' => '0300-1234567',
            'subtotal'       => 100,
            'total_amount'   => 116,
            'payment_method' => 'cash',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        \App\Models\PosTransaction::$__testForceWaBillThrow = true;

        $response = $this->actingAs($user, 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", [
                'payment_method' => 'cash',
                'pay_uuid'       => $replayUuid,
            ]);

        $response->assertOk()->assertJson(['success' => true, 'replayed' => true]);
        $this->assertNull($response->json('wa_phone'),  'wa_phone must be null when extras throw on replay');
        $this->assertNull($response->json('share_url'), 'share_url must be null when extras throw on replay');
    }

    // ── Client-side: null wa fields hide the WhatsApp button ─────────────

    /**
     * Task 1093: sanity-check that the sale screen's x-show guard uses &&
     * on both lastWaPhone and lastShareUrl, so null extras from either a
     * feature-off response or a degraded throw never show an empty wa.me link.
     *
     * This is a static blade/JS assertion — not an HTTP test — because the
     * actual Alpine evaluation happens in the browser. We grep the compiled
     * blade to confirm the guard expression is present.
     */
    public function test_sale_screen_wa_button_xshow_guards_both_fields(): void
    {
        // The universal sale screen must gate the WhatsApp button on BOTH
        // lastWaPhone AND lastShareUrl so a null from a degraded pay never
        // opens an empty wa.me link.
        $blade = file_get_contents(base_path('resources/views/pos/universal.blade.php'));
        $this->assertNotFalse($blade, 'universal.blade.php must be readable');

        // The x-show expression must AND both fields (the exact guard from the
        // task-1036 implementation; grep is line-level so we check substrings).
        $this->assertStringContainsString(
            'lastWaPhone && lastShareUrl',
            $blade,
            'WhatsApp button x-show must guard on both lastWaPhone AND lastShareUrl'
        );

        // setWaBill() coerces nulls so neither field is ever a truthy empty string.
        $this->assertStringContainsString(
            '(data && data.wa_phone) || null',
            $blade,
            'setWaBill must coerce wa_phone to null (not empty string) on null/missing'
        );
        $this->assertStringContainsString(
            '(data && data.share_url) || null',
            $blade,
            'setWaBill must coerce share_url to null (not empty string) on null/missing'
        );
    }
    private function autoSendFixture(bool $enabled = true, bool $package = true): array
    {
        (require database_path('migrations/2026_08_03_030000_whatsapp_business_api_phase2.php'))->up();
        Schema::table('companies', fn (Blueprint $t) => $t->text('wa_app_secret')->nullable());
        Schema::table('pricing_plans', fn (Blueprint $t) => $t->boolean('whatsapp_enabled')->default(false));
        (require database_path('migrations/2026_12_03_010000_create_pos_whatsapp_outbox.php'))->up();
        $id = $this->makeCompany();
        $this->subscribe($id, ['whatsapp_enabled' => $package]);
        $company = \App\Models\Company::find($id);
        $company->forceFill(['pos_whatsapp_auto_send' => $enabled, 'wa_api_enabled' => true,
            'wa_phone_number_id' => '123456789', 'wa_api_token' => \Illuminate\Support\Facades\Crypt::encryptString('synthetic-token'),
            'wa_template_name' => 'invoice_notification', 'wa_attach_pdf' => true, 'wa_app_secret' => 'synthetic-app-secret'])->save();
        PosFeatureService::flushGateCaches();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        return [$company, app(\App\Services\PosWhatsAppOutbox::class)];
    }

    private function autoBill(int $company, array $overrides = []): \App\Models\PosTransaction
    {
        return \App\Models\PosTransaction::create(array_merge([
            'company_id' => $company, 'invoice_number' => 'AUTO-' . uniqid(), 'status' => 'completed',
            'customer_phone' => '03001234567', 'total_amount' => 116, 'pra_status' => null,
        ], $overrides));
    }

    public function test_auto_send_defaults_off_and_preserves_manual_bill_payload(): void
    {
        [$company] = $this->autoSendFixture(false);
        $bill = $this->autoBill($company->id);
        $this->assertSame(0, DB::table('pos_whatsapp_outbox')->count());
        $this->assertSame('923001234567', $bill->waBillPayload($company)['wa_phone']);
        $this->assertFalse((bool) \App\Models\Company::find($this->makeCompany())->pos_whatsapp_auto_send);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_auto_bill_claim_is_idempotent_and_uses_the_actual_pos_pdf_route(): void
    {
        [$company, $outbox] = $this->autoSendFixture();
        \Illuminate\Support\Facades\Http::fake(['graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.POS1']]], 200)]);
        $bill = $this->autoBill($company->id);$id = $outbox->enqueue($bill);
        $outbox->process($id);$outbox->process($id);$outbox->enqueue($bill);$outbox->drain();
        $this->assertSame(1, DB::table('pos_whatsapp_outbox')->count());
        $this->assertSame('sent', DB::table('pos_whatsapp_outbox')->find($id)->status);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($bill) {
            $data = $request->data();
            $pdf = $data['template']['components'][0]['parameters'][0]['document']['link'];
            return $data['to'] === '923001234567' && str_ends_with($pdf, '/pos/invoice/share/' . $bill->fresh()->share_token)
                && $data['template']['components'][1]['parameters'][0]['text'] === $bill->invoice_number;
        });
    }

    public function test_provisional_draft_cancelled_and_invalid_number_never_enqueue(): void
    {
        [$company] = $this->autoSendFixture();
        foreach ([['pra_status' => 'local'], ['status' => 'draft'], ['status' => 'cancelled'], ['customer_phone' => 'bad']] as $attrs) $this->autoBill($company->id, $attrs);
        $this->assertSame(0, DB::table('pos_whatsapp_outbox')->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_promoting_provisional_enqueues_once_and_bill_rollback_rolls_back_outbox(): void
    {
        [$company, $outbox] = $this->autoSendFixture();
        $bill = $this->autoBill($company->id, ['pra_status' => 'local']);
        $bill->update(['pra_status' => 'pending']);$bill->update(['notes' => 'unchanged delivery']);
        $this->assertSame(1, DB::table('pos_whatsapp_outbox')->count());
        DB::beginTransaction();$this->autoBill($company->id);$this->assertSame(2, DB::table('pos_whatsapp_outbox')->count());DB::rollBack();
        $this->assertSame(1, DB::table('pos_whatsapp_outbox')->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_package_denial_and_opt_out_before_send_prevent_provider_calls(): void
    {
        [$company, $outbox] = $this->autoSendFixture(true, false);
        $this->autoBill($company->id);$this->assertSame(0, DB::table('pos_whatsapp_outbox')->count());
        DB::table('pricing_plans')->update(['whatsapp_enabled' => true]);PosFeatureService::flushGateCaches();
        $bill = $this->autoBill($company->id);$id = $outbox->enqueue($bill);
        $company->update(['pos_whatsapp_auto_send' => false]);$outbox->process($id);
        $this->assertSame('cancelled', DB::table('pos_whatsapp_outbox')->find($id)->status);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_rate_limit_retries_but_ambiguous_response_is_not_automatically_resent(): void
    {
        [$company, $outbox] = $this->autoSendFixture();
        \Illuminate\Support\Facades\Http::fake(['graph.facebook.com/*' => \Illuminate\Support\Facades\Http::sequence()->push([], 429)->push([], 503)]);
        $id = $outbox->enqueue($this->autoBill($company->id));$outbox->process($id);
        $this->assertSame('pending', DB::table('pos_whatsapp_outbox')->find($id)->status);
        $outbox->drain();\Illuminate\Support\Facades\Http::assertSentCount(1);
        DB::table('pos_whatsapp_outbox')->where('id', $id)->update(['next_attempt_at' => now()->subSecond()]);
        $outbox->drain();$this->assertSame('unknown', DB::table('pos_whatsapp_outbox')->find($id)->status);
        $outbox->drain();\Illuminate\Support\Facades\Http::assertSentCount(2);
    }

    public function test_early_webhook_is_replayed_and_foreign_company_cannot_advance_status(): void
    {
        [$company, $outbox] = $this->autoSendFixture();
        \Illuminate\Support\Facades\Http::fake(function () use ($outbox, $company) {
            $outbox->applyStatus($company->id, ['id' => 'wamid.EARLY', 'status' => 'delivered']);
            return \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.EARLY']]], 200);
        });
        $id = $outbox->enqueue($this->autoBill($company->id));$outbox->process($id);
        $this->assertSame('delivered', DB::table('pos_whatsapp_outbox')->find($id)->status);
        $outbox->applyStatus($company->id + 10, ['id' => 'wamid.EARLY', 'status' => 'read']);
        $outbox->applyStatus($company->id, ['id' => 'wamid.EARLY', 'status' => 'sent']);
        $this->assertSame('delivered', DB::table('pos_whatsapp_outbox')->find($id)->status);
        $outbox->applyStatus($company->id, ['id' => 'wamid.EARLY', 'status' => 'read']);
        $outbox->applyStatus($company->id, ['id' => 'wamid.EARLY', 'status' => 'failed']);
        $this->assertSame('read', DB::table('pos_whatsapp_outbox')->find($id)->status);
    }

    public function test_interrupted_send_becomes_unknown_instead_of_duplicate_retry(): void
    {
        [$company, $outbox] = $this->autoSendFixture();
        $id = $outbox->enqueue($this->autoBill($company->id));
        DB::table('pos_whatsapp_outbox')->where('id', $id)->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(6)]);
        $outbox->drain();$this->assertSame('unknown', DB::table('pos_whatsapp_outbox')->find($id)->status);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_pos_owner_settings_keep_blank_secrets_and_cashier_cannot_change_them(): void
    {
        [$company] = $this->autoSendFixture();$user = $this->makeUser($company->id);
        $this->actingAs($user, 'pos')->post('/pos/settings/whatsapp-delivery', [
            'auto_send' => 0, 'wa_api_token' => '', 'wa_app_secret' => '',
        ])->assertRedirect('/pos/settings/whatsapp-delivery');
        $company->refresh();$this->assertFalse($company->pos_whatsapp_auto_send);
        $this->assertSame('synthetic-token', \Illuminate\Support\Facades\Crypt::decryptString($company->wa_api_token));
        $this->assertSame('synthetic-app-secret', $company->wa_app_secret);
        $user->forceFill(['role' => 'user', 'pos_role' => 'pos_cashier'])->save();
        $this->actingAs($user, 'pos')->post('/pos/settings/whatsapp-delivery', ['auto_send' => 1])->assertForbidden();
    }

    public function test_real_sale_route_creates_durable_auto_send_without_changing_payment_result(): void
    {
        [$company, $outbox] = $this->autoSendFixture();$user = $this->makeUser($company->id);
        \Illuminate\Support\Facades\Http::fake(['graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.SALE']]], 200)]);
        $response = $this->actingAs($user, 'pos')->postJson('/pos/invoice', $this->storePayload());
        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, DB::table('pos_whatsapp_outbox')->count());
        $outbox->drain();\Illuminate\Support\Facades\Http::assertSentCount(1);
    }

}
