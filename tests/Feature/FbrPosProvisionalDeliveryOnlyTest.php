<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR "provisional sirf delivery" owner-rule — PRA parity (Task 164).
 *
 * Locks FbrPosController::store's order-type flow guard, ported from
 * PosController::store (~line 1667, owner rule Jul 2026):
 *
 *   1. Restaurant-ish company (any of tables/kot/kitchen/delivery ON) +
 *      save_as_provisional + order_type dine_in/takeaway → 422 with
 *      pos.provisional_delivery_only_flow, NO row written.
 *   2. order_type='delivery' provisional → allowed (local/local row).
 *   3. NO order_type in the payload (older queued offline replays) →
 *      guard skipped, provisional saved — replays are never stranded.
 *   4. Non-restaurant company (all four flags OFF) → guard skipped even
 *      for dine_in provisionals.
 *   5. FINAL bills (save_as_provisional false) are never touched by the
 *      guard regardless of order_type.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly with the currentCompanyId container binding
 * (mirrors FbrPosPendingDeliveriesPanelTest). Companies are internal
 * accounts so PosFeatureService::restaurantAllowed passes without plan
 * tables; feature_flags column drives the restaurant-ish check.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosProvisionalDeliveryOnlyTest.php
 */
class FbrPosProvisionalDeliveryOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->text('feature_flags')->nullable();
            $table->softDeletes();
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
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 12, 2)->nullable();
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
            $table->string('fbr_response_code')->nullable();
            $table->text('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->timestamps();
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
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->nullable();
            $table->decimal('item_discount', 12, 2)->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
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
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->decimal('default_price', 12, 2)->default(0);
            $table->boolean('is_price_editable')->default(true);
            $table->timestamps();
        });
    }

    private function makeCompany(array $featureFlags): Company
    {
        $company = new Company();
        $company->name = 'FBR Flow Test Co';
        $company->is_internal_account = true; // restaurantAllowed → 'internal', no plan tables needed
        $company->fbr_reporting_enabled = false;
        $company->feature_flags = $featureFlags;
        $company->save();

        app()->instance('currentCompanyId', (int) $company->id);

        return $company;
    }

    private function storeRequest(array $overrides = []): Request
    {
        $payload = array_merge([
            'items' => [[
                'item_name' => 'Chicken Karahi',
                'quantity' => 1,
                'unit_price' => 500,
                'tax_rate' => 0,
                'is_tax_exempt' => true,
            ]],
            'payment_method' => 'cash',
            'cash_received' => 5000,
        ], $overrides);

        $request = Request::create('/fbr-pos/store', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    private function callStore(Request $request)
    {
        return app(FbrPosController::class)->store($request);
    }

    // ── 1. Restaurant-ish + provisional + non-delivery order types → 422, no row ──

    public function test_dine_in_provisional_blocked_on_restaurant_company(): void
    {
        $this->makeCompany(['tables' => true, 'kot' => true, 'kitchen' => true, 'delivery' => true]);

        $response = $this->callStore($this->storeRequest([
            'save_as_provisional' => 1,
            'order_type' => 'dine_in',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertSame(__('pos.provisional_delivery_only_flow'), $data['error']);
        $this->assertSame(0, FbrPosTransaction::count());
    }

    public function test_takeaway_provisional_blocked_on_restaurant_company(): void
    {
        // Only ONE restaurant-ish flag on (delivery; customer_profile is its
        // dependency — without it resolve() drops delivery) — guard must still fire.
        $this->makeCompany(['delivery' => true, 'customer_profile' => true]);

        $response = $this->callStore($this->storeRequest([
            'save_as_provisional' => 1,
            'order_type' => 'takeaway',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, FbrPosTransaction::count());
    }

    // ── 2. Delivery provisional → allowed, local/local row ──

    public function test_delivery_provisional_allowed_and_saved_local(): void
    {
        $this->makeCompany(['tables' => true, 'kot' => true, 'kitchen' => true, 'delivery' => true]);

        $response = $this->callStore($this->storeRequest([
            'save_as_provisional' => 1,
            'order_type' => 'delivery',
            'delivery_address' => 'House 12, Street 5',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $txn = FbrPosTransaction::sole();
        $this->assertSame('local', $txn->invoice_mode);
        $this->assertSame('local', $txn->fbr_status);
        $this->assertSame('delivery', $txn->order_type);
        $this->assertSame('House 12, Street 5', $txn->delivery_address);
    }

    // ── 3. No order_type (offline replay) → guard skipped, provisional saved ──

    public function test_provisional_without_order_type_never_stranded(): void
    {
        $this->makeCompany(['tables' => true, 'kot' => true, 'kitchen' => true, 'delivery' => true]);

        $response = $this->callStore($this->storeRequest([
            'save_as_provisional' => 1,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $txn = FbrPosTransaction::sole();
        $this->assertSame('local', $txn->invoice_mode);
        $this->assertNull($txn->order_type);
    }

    // ── 4. Non-restaurant company → dine_in provisional allowed ──

    public function test_non_restaurant_company_not_gated(): void
    {
        $this->makeCompany(['tables' => false, 'kot' => false, 'kitchen' => false, 'delivery' => false]);

        $response = $this->callStore($this->storeRequest([
            'save_as_provisional' => 1,
            'order_type' => 'dine_in',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('local', FbrPosTransaction::sole()->invoice_mode);
    }

    // ── 5. Final bills never touched by the guard ──

    public function test_final_bill_with_dine_in_not_gated(): void
    {
        $this->makeCompany(['tables' => true, 'kot' => true, 'kitchen' => true, 'delivery' => true]);

        $response = $this->callStore($this->storeRequest([
            'order_type' => 'dine_in',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $txn = FbrPosTransaction::sole();
        // Reporting-OFF Finals Invariant: fbr mode + NULL fbr_status
        $this->assertSame('fbr', $txn->invoice_mode);
        $this->assertNull($txn->fbr_status);
        $this->assertSame('dine_in', $txn->order_type);
    }
}
