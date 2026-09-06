<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Models\Company;
use App\Services\PosUnitCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR sale submit — unit rules come from ONE catalogue (PosUnitCatalog):
 *   • every catalogue code passes the items.*.uom validator (lower-case too);
 *   • a made-up code is rejected;
 *   • a NEW measure unit (LB) takes a decimal quantity AND value-mode entry
 *     exactly like KG; a count unit (PCS / STRIP) with a decimal is rejected
 *     and value-mode on it is rejected.
 *
 * Schema/pattern copied from FbrPetiRateStoreTest (direct store() call).
 */
class FbrPosSaleUnitRulesTest extends TestCase
{
    private int $companyId;
    private object $user;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
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
            // Task 1414 columns.
            $table->boolean('fbr_peti_rate_enabled')->default(false);
            $table->decimal('fbr_peti_margin_pct', 6, 2)->default(3.00);
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
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            // Task 1414 marker.
            $table->boolean('is_peti_rate')->default(false);
            $table->timestamps();
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

        // products — fixed-price flag + pack_size drive the peti decision.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('default_price', 12, 2)->default(0);
            $table->boolean('is_price_editable')->default(true);
            $table->unsignedInteger('pack_size')->nullable();
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

        $company = Company::create([
            'name' => 'Unit Rules Shop',
            'fbr_reporting_enabled' => false,
            'agent_enabled' => false,
            'fbr_connection_mode' => 'cloud',
            'inventory_enabled' => false,
            'fbr_peti_rate_enabled' => true,
            'fbr_peti_margin_pct' => 3.00,
        ]);
        $this->companyId = $company->id;

        DB::table('fbr_pos_loyalty_settings')->insert([
            'company_id' => $this->companyId, 'is_enabled' => false,
            'rs_per_point' => 100.00, 'point_value' => 1.00, 'min_redeem_points' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = (object) [
            'id' => DB::table('users')->insertGetId([
                'name' => 'Cashier', 'email' => 'uom@fbrtest.pk', 'password' => bcrypt('t'),
                'company_id' => $this->companyId, 'role' => 'company_admin',
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'role' => 'company_admin',
        ];

        app()->bind('currentCompanyId', fn () => $this->companyId);
        app()->bind('currentBranchId', fn () => null);
    }

    private function callStore(array $payload)
    {
        $userModel = new \App\Models\User();
        $userModel->id = $this->user->id;
        $userModel->role = $this->user->role;
        $userModel->company_id = $this->companyId;
        \Illuminate\Support\Facades\Auth::guard('fbrpos')->setUser($userModel);

        $req = Request::create('/fbr-pos/store', 'POST', $payload);
        $req->headers->set('Accept', 'application/json');
        return (new FbrPosController())->store($req);
    }

    private function payload(array $item, string $uuid): array
    {
        return [
            'items' => [array_merge([
                'item_name' => 'Line', 'quantity' => 1, 'unit_price' => 100, 'uom' => 'U',
                'tax_rate' => 0, 'is_tax_exempt' => true, 'item_discount' => 0,
            ], $item)],
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'tax_inclusive' => false,
            'offline_uuid' => $uuid,
        ];
    }

    private function soldItem(int $txId): object
    {
        return DB::table('fbr_pos_transaction_items')->where('transaction_id', $txId)->first();
    }

    public function test_every_catalogue_code_passes_the_sale_validator(): void
    {
        foreach (PosUnitCatalog::validCodes() as $i => $code) {
            $res = $this->callStore($this->payload(['uom' => $code, 'item_name' => 'Line ' . $code], 'code-' . $i));
            $data = $res->getData(true);
            $this->assertTrue($data['success'] ?? false, "code {$code} rejected: " . json_encode($data));
            $this->assertSame($code, $this->soldItem($data['transaction_id'])->uom);
        }
        // lower-case from an old queue/agent replay still lands upper-cased
        $data = $this->callStore($this->payload(['uom' => 'strip'], 'lower-1'))->getData(true);
        $this->assertTrue($data['success'] ?? false);
        $this->assertSame('STRIP', $this->soldItem($data['transaction_id'])->uom);
    }

    public function test_made_up_code_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->callStore($this->payload(['uom' => 'FOO'], 'bad-1'));
    }

    public function test_new_measure_unit_takes_decimal_qty_and_value_mode_like_kg(): void
    {
        // LB — decimal qty
        $data = $this->callStore($this->payload(['uom' => 'LB', 'quantity' => 2.5, 'unit_price' => 400], 'lb-qty'))->getData(true);
        $this->assertTrue($data['success'] ?? false, json_encode($data));
        $row = $this->soldItem($data['transaction_id']);
        $this->assertEqualsWithDelta(2.5, (float) $row->quantity, 0.0001);
        $this->assertEqualsWithDelta(1000.0, (float) $row->subtotal, 0.01);

        // LB — value mode (Rs 300 at Rs 400/lb = 0.75 lb), same as KG today
        $data = $this->callStore($this->payload(['uom' => 'LB', 'quantity' => 1, 'unit_price' => 400, 'value_input' => 300], 'lb-val'))->getData(true);
        $this->assertTrue($data['success'] ?? false, json_encode($data));
        $this->assertEqualsWithDelta(0.75, (float) $this->soldItem($data['transaction_id'])->quantity, 0.0001);

        $data = $this->callStore($this->payload(['uom' => 'KG', 'quantity' => 1, 'unit_price' => 400, 'value_input' => 300], 'kg-val'))->getData(true);
        $this->assertTrue($data['success'] ?? false, json_encode($data));
        $this->assertEqualsWithDelta(0.75, (float) $this->soldItem($data['transaction_id'])->quantity, 0.0001);
    }

    public function test_count_unit_with_decimal_qty_is_rejected(): void
    {
        foreach (['PCS', 'STRIP', 'NGT'] as $i => $code) {
            try {
                $this->callStore($this->payload(['uom' => $code, 'quantity' => 1.5], 'cnt-' . $i));
                $this->fail("decimal qty on {$code} must be rejected");
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertStringContainsString('Decimal quantity not allowed', json_encode($e->errors()));
            }
        }
    }

    public function test_value_mode_on_count_unit_is_rejected(): void
    {
        try {
            $this->callStore($this->payload(['uom' => 'STRIP', 'quantity' => 1, 'unit_price' => 100, 'value_input' => 50], 'cnt-val'));
            $this->fail('value-mode on a count unit must be rejected');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('measure units', json_encode($e->errors()));
        }
    }
}
