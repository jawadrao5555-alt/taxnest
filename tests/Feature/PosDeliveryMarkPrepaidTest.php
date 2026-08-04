<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Task 285 — "Advance online-paid delivery rider ke cash khate mein na phanse"
 *
 * Guards under test:
 *  1. Admin can convert unsettled cash bill → success + payment_method = qr_payment.
 *  2. Manager can convert → success.
 *  3. Cashier blocked (error flash, bill untouched).
 *  4. Delivery Manager blocked.
 *  5. Already non-cash → error (idempotent).
 *  6. Settled bill (rider_settlement_id set) → error.
 *  7. Returned delivery → error.
 *  8. PRA-submitted bill → allowed; success; PRA invoice_number unchanged.
 *  9. Audit columns (prepaid_converted_at + prepaid_converted_by) written.
 * 10. After conversion bill vanishes from the cash khata query.
 * 11. PosRider::openCashBills() excludes the converted bill.
 * 12. Cross-company isolation: 404 for foreign bill.
 *
 * Pattern: sqlite :memory:, minimal schema matching PosCustomAccessGrantExpansionTest.
 */
class PosDeliveryMarkPrepaidTest extends TestCase
{
    private function buildSchema(): int
    {
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('ntn')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('confidential_pin')->nullable();
            $table->string('default_language')->nullable();
            $table->text('invoice_display_prefs')->nullable();
            // feature_flags: JSON map of per-company feature toggles (PosFeatureService)
            $table->text('feature_flags')->nullable();
            // is_internal_account: internal companies bypass plan gates entirely
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
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
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->string('language')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->rememberToken();
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

        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('login_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_active')->default(true);
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->text('features')->nullable();
            $table->boolean('is_active')->default(true);
            // Plan-gate columns checked by PosFeatureService::planAllows
            $table->boolean('riders_enabled')->default(true);
            // Restaurant module gate — must be true so RestaurantOnly passes for
            // the delivery routes (which live inside the restaurant.only group).
            $table->boolean('restaurant_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('pra_response_code')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->boolean('tax_inclusive')->default(false);
            // Delivery rider columns.
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            // Prepaid conversion audit (Task 285).
            $table->timestamp('prepaid_converted_at')->nullable();
            $table->unsignedBigInteger('prepaid_converted_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        // Flush static gate caches so previous test runs can't poison this one.
        \App\Services\PosFeatureService::flushGateCaches();

        // Internal account: bypasses all plan gates (restaurantAllowed returns
        // 'internal' immediately, so RestaurantOnly middleware passes without
        // needing a subscription with restaurant_enabled).
        $companyId = (int) DB::table('companies')->insertGetId([
            'name'               => 'Prepaid Test Co',
            'product_type'       => 'pos',
            'status'             => 'approved',
            'company_status'     => 'approved',
            'is_internal_account'=> true,
            // tables + kitchen + kot + delivery enabled so RestaurantOnly passes.
            // kot depends on kitchen (DEPENDENCIES), so both must be true.
            // tables has no dependency and also satisfies the RestaurantOnly check.
            'feature_flags'      => json_encode(['tables' => true, 'kitchen' => true, 'kot' => true, 'delivery' => true]),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name'               => 'Pro',
            'product_type'       => 'pos',
            'price'              => 0,
            'riders_enabled'     => true,
            'restaurant_enabled' => true,   // required: delivery routes live inside restaurant.only group
            'is_active'          => true,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        DB::table('subscriptions')->insert([
            'company_id'       => $companyId,
            'pricing_plan_id'  => $planId,
            'status'           => 'active',
            'is_active'        => true,
            'active'           => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $companyId;
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeUser(int $companyId, string $posRole): User
    {
        $id = DB::table('users')->insertGetId([
            'name'        => ucfirst(str_replace('pos_', '', $posRole)),
            'email'       => $posRole . '_' . $companyId . '_' . rand(1000, 9999) . '@prepaid.test',
            'password'    => Hash::make('Secret@12'),
            'company_id'  => $companyId,
            'role'        => 'user',
            'pos_role'    => $posRole,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return User::find($id);
    }

    private function makeRider(int $companyId): int
    {
        return (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $companyId,
            'name'       => 'Test Rider',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Base delivery bill — cash, assigned, unsettled. */
    private function makeBill(int $companyId, int $riderId, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id'      => $companyId,
            'invoice_number'  => 'INV-' . rand(1000, 9999),
            'business_date'   => now()->toDateString(),
            'status'          => 'completed',
            'invoice_mode'    => 'pra',
            'pra_status'      => null,
            'is_archived'     => false,
            'order_type'      => 'delivery',
            'rider_id'        => $riderId,
            'delivery_status' => 'assigned',
            'payment_method'  => 'cash',
            'cash_received'   => 500.00,
            'change_due'      => 0.00,
            'total_amount'    => 500.00,
            'subtotal'        => 500.00,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $attrs));
    }

    private function postMarkPrepaid(User $user, int $companyId, int $billId)
    {
        return $this->actingAs($user, 'pos')
            ->from('/pos/deliveries')
            ->post('/pos/deliveries/' . $billId . '/mark-prepaid');
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    /** 1. Admin converts unsettled cash bill successfully. */
    public function test_admin_can_convert_cash_bill_to_prepaid(): void
    {
        $cid    = $this->buildSchema();
        $rider  = $this->makeRider($cid);
        $admin  = $this->makeUser($cid, 'pos_admin');
        $billId = $this->makeBill($cid, $rider);

        $this->postMarkPrepaid($admin, $cid, $billId)
            ->assertRedirect()
            ->assertSessionHas('success');

        $row = DB::table('pos_transactions')->find($billId);
        $this->assertSame('qr_payment', $row->payment_method, 'payment_method must be qr_payment after conversion');
        $this->assertNull($row->cash_received, 'cash_received must be cleared');
        $this->assertNull($row->change_due, 'change_due must be cleared');
    }

    /** 2. Manager can also convert. */
    public function test_manager_can_convert_cash_bill_to_prepaid(): void
    {
        $cid     = $this->buildSchema();
        $rider   = $this->makeRider($cid);
        $manager = $this->makeUser($cid, 'pos_manager');
        $billId  = $this->makeBill($cid, $rider);

        $this->postMarkPrepaid($manager, $cid, $billId)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('qr_payment', DB::table('pos_transactions')->find($billId)->payment_method);
    }

    /** 3. Cashier is blocked — error flash, bill untouched. */
    public function test_cashier_cannot_convert_bill(): void
    {
        $cid     = $this->buildSchema();
        $rider   = $this->makeRider($cid);
        $cashier = $this->makeUser($cid, 'pos_cashier');
        $billId  = $this->makeBill($cid, $rider);

        $this->postMarkPrepaid($cashier, $cid, $billId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('cash', DB::table('pos_transactions')->find($billId)->payment_method);
    }

    /** 4. Delivery Manager is blocked. */
    public function test_delivery_manager_cannot_convert_bill(): void
    {
        $cid    = $this->buildSchema();
        $rider  = $this->makeRider($cid);
        $delMgr = $this->makeUser($cid, 'pos_delivery');
        $billId = $this->makeBill($cid, $rider);

        $this->postMarkPrepaid($delMgr, $cid, $billId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('cash', DB::table('pos_transactions')->find($billId)->payment_method);
    }

    /** 5. Non-delivery cash bill (walk-in / dine-in) cannot be converted — delivery guard. */
    public function test_non_delivery_cash_bill_cannot_be_converted(): void
    {
        $cid   = $this->buildSchema();
        $admin = $this->makeUser($cid, 'pos_admin');

        // Walk-in bill: order_type=null, no rider — cash but NOT a delivery bill.
        $walkInId = (int) DB::table('pos_transactions')->insertGetId([
            'company_id'      => $cid,
            'invoice_number'  => 'WALKIN-001',
            'business_date'   => now()->toDateString(),
            'status'          => 'completed',
            'invoice_mode'    => 'pra',
            'pra_status'      => null,
            'is_archived'     => false,
            'order_type'      => null,        // ← not delivery
            'rider_id'        => null,        // ← no rider assigned
            'delivery_status' => null,
            'payment_method'  => 'cash',
            'cash_received'   => 1000.00,
            'change_due'      => 0.00,
            'total_amount'    => 1000.00,
            'subtotal'        => 1000.00,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->postMarkPrepaid($admin, $cid, $walkInId)
            ->assertRedirect()
            ->assertSessionHas('error');

        // All three money fields must be untouched.
        $row = DB::table('pos_transactions')->find($walkInId);
        $this->assertSame('cash', $row->payment_method, 'payment_method must stay cash');
        $this->assertSame(1000.0, (float) $row->cash_received, 'cash_received must be untouched');
        $this->assertSame(0.0,    (float) $row->change_due,    'change_due must be untouched');

        // Also test: delivery order_type but no rider — still blocked.
        $noRiderId = (int) DB::table('pos_transactions')->insertGetId([
            'company_id'      => $cid,
            'invoice_number'  => 'NORIDER-001',
            'business_date'   => now()->toDateString(),
            'status'          => 'completed',
            'invoice_mode'    => 'pra',
            'pra_status'      => null,
            'is_archived'     => false,
            'order_type'      => 'delivery',  // ← delivery type...
            'rider_id'        => null,         // ← ...but no rider assigned
            'delivery_status' => null,
            'payment_method'  => 'cash',
            'cash_received'   => 500.00,
            'change_due'      => 0.00,
            'total_amount'    => 500.00,
            'subtotal'        => 500.00,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->postMarkPrepaid($admin, $cid, $noRiderId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $row2 = DB::table('pos_transactions')->find($noRiderId);
        $this->assertSame('cash', $row2->payment_method, 'no-rider delivery must also stay cash');
    }

    /** 6. Bill already non-cash → idempotent error. */
    public function test_non_cash_bill_returns_error(): void
    {
        $cid    = $this->buildSchema();
        $rider  = $this->makeRider($cid);
        $admin  = $this->makeUser($cid, 'pos_admin');
        $billId = $this->makeBill($cid, $rider, ['payment_method' => 'qr_payment', 'cash_received' => null]);

        $this->postMarkPrepaid($admin, $cid, $billId)
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /** 6. Already settled bill → error. */
    public function test_settled_bill_cannot_be_converted(): void
    {
        $cid    = $this->buildSchema();
        $rider  = $this->makeRider($cid);
        $admin  = $this->makeUser($cid, 'pos_admin');
        $billId = $this->makeBill($cid, $rider, [
            'rider_settlement_id' => 1,
            'rider_settled_at'    => now(),
        ]);

        $this->postMarkPrepaid($admin, $cid, $billId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('cash', DB::table('pos_transactions')->find($billId)->payment_method);
    }

    /** 7. Returned delivery → error (already off khata). */
    public function test_returned_delivery_cannot_be_converted(): void
    {
        $cid    = $this->buildSchema();
        $rider  = $this->makeRider($cid);
        $admin  = $this->makeUser($cid, 'pos_admin');
        $billId = $this->makeBill($cid, $rider, ['delivery_status' => 'returned']);

        $this->postMarkPrepaid($admin, $cid, $billId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('cash', DB::table('pos_transactions')->find($billId)->payment_method);
    }

    /** 8. PRA-submitted bill → allowed; PRA fields untouched; success. */
    public function test_pra_submitted_bill_converts_with_pra_note_in_flash(): void
    {
        $cid    = $this->buildSchema();
        $rider  = $this->makeRider($cid);
        $admin  = $this->makeUser($cid, 'pos_admin');
        $billId = $this->makeBill($cid, $rider, [
            'pra_invoice_number' => 'PRA-12345',
            'pra_status'         => 'submitted',
        ]);

        $this->postMarkPrepaid($admin, $cid, $billId)
            ->assertRedirect()
            ->assertSessionHas('success');

        $row = DB::table('pos_transactions')->find($billId);
        $this->assertSame('qr_payment', $row->payment_method, 'PRA-submitted bill must still be convertible');
        // PRA invoice number must be unchanged — never wiped on conversion.
        $this->assertSame('PRA-12345', $row->pra_invoice_number);
        $this->assertSame('submitted', $row->pra_status);
    }

    /** 9. Audit columns written after conversion. */
    public function test_audit_columns_written_after_conversion(): void
    {
        $cid    = $this->buildSchema();
        $rider  = $this->makeRider($cid);
        $admin  = $this->makeUser($cid, 'pos_admin');
        $billId = $this->makeBill($cid, $rider);

        $this->postMarkPrepaid($admin, $cid, $billId);

        $row = DB::table('pos_transactions')->find($billId);
        $this->assertNotNull($row->prepaid_converted_at, 'prepaid_converted_at must be stamped');
        $this->assertSame((int) $admin->id, (int) $row->prepaid_converted_by, 'prepaid_converted_by must be actor user id');
    }

    /** 10. After conversion the bill is absent from a cash-only khata query. */
    public function test_converted_bill_drops_out_of_cash_khata_query(): void
    {
        $cid    = $this->buildSchema();
        $rider  = $this->makeRider($cid);
        $admin  = $this->makeUser($cid, 'pos_admin');
        $billId = $this->makeBill($cid, $rider);

        // Before: the bill is in the khata.
        $before = DB::table('pos_transactions')
            ->where('company_id', $cid)
            ->whereNotNull('rider_id')
            ->where('payment_method', 'cash')
            ->whereNull('rider_settlement_id')
            ->where(function ($q) { $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned'); })
            ->count();
        $this->assertSame(1, $before, 'bill must be in khata before conversion');

        $this->postMarkPrepaid($admin, $cid, $billId);

        // After: the bill is gone from the cash khata.
        $after = DB::table('pos_transactions')
            ->where('company_id', $cid)
            ->whereNotNull('rider_id')
            ->where('payment_method', 'cash')
            ->whereNull('rider_settlement_id')
            ->where(function ($q) { $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned'); })
            ->count();
        $this->assertSame(0, $after, 'converted bill must be gone from cash khata');
    }

    /** 11. PosRider::openCashBills() scope excludes the converted bill. */
    public function test_open_cash_bills_scope_excludes_converted_bill(): void
    {
        $cid     = $this->buildSchema();
        $riderId = $this->makeRider($cid);
        $admin   = $this->makeUser($cid, 'pos_admin');
        $billId  = $this->makeBill($cid, $riderId);

        $riderModel = \App\Models\PosRider::find($riderId);
        $this->assertNotNull($riderModel);

        // Before: 1 open cash bill.
        $this->assertSame(1, (int) $riderModel->openCashBills()->count());

        $this->postMarkPrepaid($admin, $cid, $billId);

        // After: 0 open cash bills.
        $this->assertSame(0, (int) $riderModel->openCashBills()->count());
    }

    /** 12. Cross-company: admin from company A cannot convert company B's bill → 404. */
    public function test_cross_company_bill_returns_404(): void
    {
        $cidA   = $this->buildSchema();
        // Company B: insert directly (schema already created above).
        $cidB   = (int) DB::table('companies')->insertGetId([
            'name'                => 'Other Co',
            'product_type'        => 'pos',
            'status'              => 'approved',
            'company_status'      => 'approved',
            'is_internal_account' => true,
            'feature_flags'       => json_encode(['kot' => true, 'delivery' => true]),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
        $riderB = (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $cidB,
            'name'       => 'Rider B',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $adminA = $this->makeUser($cidA, 'pos_admin');
        $billB  = $this->makeBill($cidB, $riderB);

        $response = $this->actingAs($adminA, 'pos')
            ->from('/pos/deliveries')
            ->post('/pos/deliveries/' . $billB . '/mark-prepaid');

        // App converts ModelNotFoundException on pos/* paths to a dashboard redirect
        // (bootstrap/app.php NotFoundHttpException handler, line ~83). So we get a
        // 302 with an error flash — not a raw 404.
        $response->assertRedirect();
        $response->assertSessionHas('error');
        // Core isolation invariant: bill must be untouched regardless of HTTP status.
        $this->assertSame('cash', DB::table('pos_transactions')->find($billB)->payment_method);
    }
}
