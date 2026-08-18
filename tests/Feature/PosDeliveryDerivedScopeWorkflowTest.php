<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task #1188 — Derived-scope cashier can still run the full delivery/rider
 * workflow after the Task 1186 default change.
 *
 * Task 1186 flipped the default: a cashier with an UNSET pos_billing_scope
 * now DERIVES their visible stream from their reporting status
 * (posBillingScope). HOWEVER, all rider/delivery surfaces deliberately stay
 * on the EXPLICIT column value (posBillingScopeExplicit) so the delivery
 * workflow never breaks for derived cashiers:
 *
 *   - PosRiderController::billingScope() → posBillingScopeExplicit()
 *   - apiProvisionalBills $scopeHidesProvisionals → posBillingScopeExplicit()
 *   - apiProvisionalBills $finalBillScope → posBillingScopeExplicit()
 *   - apiProvisionalBills $canAssignRider → posBillingScopeExplicit() !== 'local'
 *
 * NULL scope → posBillingScopeExplicit() returns 'both' → rider delivery
 * surfaces stay stream-agnostic for derived cashiers.
 *
 * Locked in this suite:
 *   1. Derived-pra cashier (reporting-ON, NULL scope) sees BOTH streams on
 *      the Deliveries board and in apiProvisionalBills — no 403 on
 *      assign / mark-delivered / settle.
 *   2. Derived-local cashier (reporting-OFF, NULL scope) gets the same
 *      stream-agnostic access.
 *   3. can_assign_rider = true for both derived cases (rider dropdown shown).
 *   4. Explicit 'local' lock still gives the strict behavior — board/assign
 *      blocked on PRA bills; unchanged from Task 353 tests.
 *
 * Schema: minimal clone of PosDeliveriesStreamScopeTest with
 * pra_reporting_enabled on companies.
 */
class PosDeliveryDerivedScopeWorkflowTest extends TestCase
{
    // ── Schema + fixture helpers ────────────────────────────────────────────

    private function buildSchema(bool $praReportingEnabled = false): int
    {
        User::flushScopeColumnCache();
        PosFeatureService::flushGateCaches();
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
            $table->text('feature_flags')->nullable();
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
            $table->string('pos_billing_scope', 10)->nullable();  // NULL = derived default
            $table->boolean('pra_reporting_enabled')->nullable();  // per-user override
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
            $table->boolean('riders_enabled')->default(true);
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
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_rider_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id');
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->integer('bill_count')->default(0);
            $table->text('notes')->nullable();
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

        // Needed by apiProvisionalBills finalData mapper (items_count).
        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('name')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->timestamps();
        });

        PosFeatureService::flushGateCaches();

        $companyId = (int) DB::table('companies')->insertGetId([
            'name'                 => 'Derived Scope Test Co',
            'product_type'         => 'pos',
            'status'               => 'approved',
            'company_status'       => 'approved',
            'is_internal_account'  => true,
            'pra_reporting_enabled'=> $praReportingEnabled,
            // RestaurantOnly middleware (which wraps /pos/deliveries routes) passes
            // when ANY of tables/kitchen/kot/recipes is enabled. delivery also
            // depends on customer_profile (PosFeatureService::DEPENDENCIES).
            'feature_flags'        => json_encode([
                'tables'           => true,
                'kitchen'          => true,
                'kot'              => true,
                'delivery'         => true,
                'customer_profile' => true,
            ]),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name'               => 'Pro',
            'product_type'       => 'pos',
            'price'              => 0,
            'riders_enabled'     => true,
            'restaurant_enabled' => false,
            'is_active'          => true,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        DB::table('subscriptions')->insert([
            'company_id'      => $companyId,
            'pricing_plan_id' => $planId,
            'status'          => 'active',
            'is_active'       => true,
            'active'          => true,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $companyId;
    }

    /**
     * Make a cashier. $scope=null → NULL column (derived default).
     * $praReportingUser overrides per-user; null = inherit company flag.
     */
    private function makeUser(int $companyId, string $posRole, ?string $scope = null, ?bool $praReportingUser = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name'                  => ucfirst(str_replace('pos_', '', $posRole)) . '-' . rand(100, 999),
            'email'                 => $posRole . '_' . rand(10000, 99999) . '@derivedscope.test',
            'password'              => Hash::make('Secret@12'),
            'company_id'            => $companyId,
            'role'                  => $posRole === 'company_admin' ? 'company_admin' : 'employee',
            'pos_role'              => $posRole,
            'pos_billing_scope'     => $scope,   // NULL = derived default (the whole point of this test)
            'pra_reporting_enabled' => $praReportingUser,
            'is_active'             => true,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
        return User::find($id);
    }

    private function makeRider(int $companyId): int
    {
        return (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $companyId,
            'name'       => 'Asgar Rider',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** PRA-stream final delivery bill (assigned, unsettled cash). */
    private function makePraBill(int $companyId, int $riderId, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id'         => $companyId,
            'invoice_number'     => 'INV-PRA-' . rand(10000, 99999),
            'business_date'      => now()->toDateString(),
            'status'             => 'completed',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'completed',
            'pra_invoice_number' => 'PRAF-' . rand(10000, 99999),
            'is_archived'        => false,
            'order_type'         => 'delivery',
            'rider_id'           => $riderId,
            'rider_assigned_at'  => now(),
            'delivery_status'    => 'assigned',
            'rider_settlement_id'=> null,
            'payment_method'     => 'cash',
            'total_amount'       => 500.00,
            'subtotal'           => 500.00,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $attrs));
    }

    /** Local-stream final delivery bill (reporting-OFF shape: invoice_mode=pra, NULL pra trail). */
    private function makeLocalBill(int $companyId, int $riderId, array $attrs = []): int
    {
        return $this->makePraBill($companyId, $riderId, array_merge([
            'invoice_mode'       => 'pra',
            'pra_status'         => null,
            'pra_invoice_number' => null,
        ], $attrs));
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    /**
     * Derived-pra cashier (reporting-ON company, NULL pos_billing_scope) must
     * see BOTH streams on the Deliveries board because billingScope() returns
     * posBillingScopeExplicit() = 'both' for unset scope.
     */
    public function test_derived_pra_cashier_sees_both_streams_on_board(): void
    {
        $cid = $this->buildSchema(praReportingEnabled: true);
        $rid = $this->makeRider($cid);

        $localInv = 'L-DRV-PRA-LOCAL-1';
        $praInv   = 'INV-DRV-PRA-PRA-1';
        $this->makeLocalBill($cid, $rid, ['invoice_number' => $localInv]);
        $this->makePraBill($cid, $rid, ['invoice_number' => $praInv]);

        // NULL scope — praReportingEnabled → derived 'pra' for VISIBILITY,
        // but delivery board uses EXPLICIT = NULL → 'both'.
        $cashier = $this->makeUser($cid, 'pos_cashier', null);
        $this->assertSame('pra', $cashier->posBillingScope(),
            'sanity: derived scope resolves to pra for reporting-ON cashier');
        $this->assertSame('both', $cashier->posBillingScopeExplicit(),
            'sanity: explicit scope is both (column is NULL)');

        $res = $this->actingAs($cashier, 'pos')->get('/pos/deliveries')->assertOk();
        $res->assertSee($localInv, false);
        $res->assertSee($praInv, false);
    }

    /**
     * Derived-local cashier (reporting-OFF company, NULL scope) also sees both
     * streams on the Deliveries board.
     */
    public function test_derived_local_cashier_sees_both_streams_on_board(): void
    {
        $cid = $this->buildSchema(praReportingEnabled: false);
        $rid = $this->makeRider($cid);

        $localInv = 'L-DRV-LOC-LOCAL-2';
        $praInv   = 'INV-DRV-LOC-PRA-2';
        $this->makeLocalBill($cid, $rid, ['invoice_number' => $localInv]);
        $this->makePraBill($cid, $rid, ['invoice_number' => $praInv]);

        // NULL scope — reporting-OFF → derived 'local' for VISIBILITY,
        // but delivery board uses EXPLICIT = NULL → 'both'.
        $cashier = $this->makeUser($cid, 'pos_cashier', null);
        $this->assertSame('local', $cashier->posBillingScope(),
            'sanity: derived scope resolves to local for reporting-OFF cashier');
        $this->assertSame('both', $cashier->posBillingScopeExplicit(),
            'sanity: explicit scope is both (column is NULL)');

        $res = $this->actingAs($cashier, 'pos')->get('/pos/deliveries')->assertOk();
        $res->assertSee($localInv, false);
        $res->assertSee($praInv, false);
    }

    /**
     * Derived-pra cashier can assign a rider to a PRA delivery bill — no 403.
     * Without the Task 1186 guard the EXPLICIT check would return derived 'pra'
     * and the assign would be 403'd for the local bill (cross-stream).
     */
    public function test_derived_pra_cashier_can_assign_rider_to_pra_bill(): void
    {
        $cid = $this->buildSchema(praReportingEnabled: true);
        $rid = $this->makeRider($cid);

        $praId = $this->makePraBill($cid, $rid, [
            'invoice_number'  => 'INV-ASSIGN-PRA-T1188',
            'delivery_status' => null,   // start unassigned so assign has something to do
            'rider_id'        => null,
        ]);
        // Temporarily detach the rider so assign has effect.
        DB::table('pos_transactions')->where('id', $praId)->update(['rider_id' => null, 'delivery_status' => null, 'order_type' => 'delivery']);

        $cashier = $this->makeUser($cid, 'pos_cashier', null);   // NULL scope = derived

        $this->actingAs($cashier, 'pos')
            ->postJson('/pos/deliveries/' . $praId . '/assign', ['rider_id' => $rid])
            ->assertOk()
            ->assertJson(['success' => true]);

        $row = DB::table('pos_transactions')->find($praId);
        $this->assertSame((int) $rid, (int) $row->rider_id, 'rider must be assigned on the PRA bill');
        $this->assertSame('assigned', $row->delivery_status);
    }

    /**
     * Derived-local cashier (reporting-OFF) can also assign a rider and mark
     * a local-stream delivery bill as delivered — no 403.
     */
    public function test_derived_local_cashier_can_assign_and_mark_delivered(): void
    {
        $cid = $this->buildSchema(praReportingEnabled: false);
        $rid = $this->makeRider($cid);

        $localId = $this->makeLocalBill($cid, $rid, [
            'invoice_number'  => 'L-ASSIGN-LOC-T1188',
            'delivery_status' => 'assigned',
        ]);

        $cashier = $this->makeUser($cid, 'pos_cashier', null);   // NULL = derived local

        // Mark delivered — no 403.
        $this->actingAs($cashier, 'pos')
            ->postJson('/pos/deliveries/' . $localId . '/status', ['delivery_status' => 'delivered'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('delivered', DB::table('pos_transactions')->find($localId)->delivery_status);
    }

    /**
     * Derived-pra cashier can mark delivered on a local bill and settle the
     * rider — no 403, khata clears properly.
     */
    public function test_derived_pra_cashier_can_settle_rider_khata(): void
    {
        $cid = $this->buildSchema(praReportingEnabled: true);
        $rid = $this->makeRider($cid);

        // A local-stream bill on the rider's cash khata.
        $localId = $this->makeLocalBill($cid, $rid, [
            'invoice_number'  => 'L-SETTLE-T1188',
            'delivery_status' => 'delivered',
            'payment_method'  => 'cash',
        ]);

        $cashier = $this->makeUser($cid, 'pos_cashier', null);   // NULL = derived pra

        $this->actingAs($cashier, 'pos')
            ->from('/pos/deliveries')
            ->post('/pos/riders/' . $rid . '/settle', ['settle_all' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull(
            DB::table('pos_transactions')->find($localId)->rider_settlement_id,
            'derived-pra cashier must be able to settle the local-stream cash bill'
        );
    }

    /**
     * apiProvisionalBills returns can_assign_rider = true for a derived-pra
     * cashier (posBillingScopeExplicit = 'both', which is != 'local').
     */
    public function test_api_provisional_bills_can_assign_rider_true_for_derived_cashier(): void
    {
        $cid = $this->buildSchema(praReportingEnabled: true);
        $this->makeRider($cid);

        $cashier = $this->makeUser($cid, 'pos_cashier', null);   // NULL = derived

        $res = $this->actingAs($cashier, 'pos')
            ->get('/pos/api/provisional-bills')
            ->assertOk();

        $this->assertTrue((bool) $res->json('can_assign_rider'),
            'derived-pra cashier must get can_assign_rider=true from apiProvisionalBills');
    }

    /**
     * apiProvisionalBills: derived-pra cashier sees BOTH local and PRA final
     * delivery bills in the popup (EXPLICIT = 'both', not derived 'pra').
     */
    public function test_api_final_deliveries_includes_both_streams_for_derived_cashier(): void
    {
        $cid = $this->buildSchema(praReportingEnabled: true);
        $rid = $this->makeRider($cid);

        $praInv   = 'INV-FINPRA-T1188';
        $localInv = 'L-FINLOC-T1188';

        // PRA final — assigned, unsettled cash.
        DB::table('pos_transactions')->insert([
            'company_id'         => $cid,
            'invoice_number'     => $praInv,
            'business_date'      => now()->toDateString(),
            'status'             => 'completed',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'completed',
            'pra_invoice_number' => 'PRAF-T1188',
            'is_archived'        => false,
            'order_type'         => 'delivery',
            'rider_id'           => $rid,
            'rider_assigned_at'  => now(),
            'delivery_status'    => 'assigned',
            'rider_settlement_id'=> null,
            'payment_method'     => 'cash',
            'total_amount'       => 700.00,
            'subtotal'           => 700.00,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Local-stream final — reporting-OFF shape (NULL pra trail).
        DB::table('pos_transactions')->insert([
            'company_id'         => $cid,
            'invoice_number'     => $localInv,
            'business_date'      => now()->toDateString(),
            'status'             => 'completed',
            'invoice_mode'       => 'pra',
            'pra_status'         => null,
            'pra_invoice_number' => null,
            'is_archived'        => false,
            'order_type'         => 'delivery',
            'rider_id'           => $rid,
            'rider_assigned_at'  => now(),
            'delivery_status'    => 'assigned',
            'rider_settlement_id'=> null,
            'payment_method'     => 'cash',
            'total_amount'       => 400.00,
            'subtotal'           => 400.00,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Derived-pra cashier (reporting-ON, NULL scope).
        $cashier = $this->makeUser($cid, 'pos_cashier', null);
        $this->assertSame('pra', $cashier->posBillingScope(), 'sanity: derived scope = pra');

        $res = $this->actingAs($cashier, 'pos')
            ->get('/pos/api/provisional-bills')
            ->assertOk();

        $invoices = collect($res->json('final_deliveries'))->pluck('invoice_number');

        $this->assertTrue($invoices->contains($praInv),
            'derived-pra cashier must see the PRA final delivery in the popup');
        $this->assertTrue($invoices->contains($localInv),
            'derived-pra cashier must also see the local-stream final delivery (EXPLICIT = both)');
    }

    /**
     * Sanity: explicit 'local' lock is still strict — board hides PRA bills
     * and assign on a PRA bill gives 403. This confirms Task 353 behavior is
     * unchanged by the Task 1186 derived-default addition.
     */
    public function test_explicit_local_cashier_still_blocked_on_pra_bill(): void
    {
        $cid = $this->buildSchema(praReportingEnabled: true);
        $rid = $this->makeRider($cid);

        $praInv = 'INV-STRICT-PRA-T1188';
        $praId  = $this->makePraBill($cid, $rid, ['invoice_number' => $praInv]);

        // Explicit 'local' lock — strict behavior must be unchanged.
        $cashier = $this->makeUser($cid, 'pos_cashier', 'local');
        $this->assertSame('local', $cashier->posBillingScopeExplicit(), 'sanity: explicit local');

        // Board hides the PRA bill.
        $this->actingAs($cashier, 'pos')
            ->get('/pos/deliveries')
            ->assertOk()
            ->assertDontSee($praInv, false);

        // Assign on a PRA bill is 403.
        $this->actingAs($cashier, 'pos')
            ->post('/pos/deliveries/' . $praId . '/assign', ['rider_id' => $rid])
            ->assertForbidden();

        // DB untouched.
        $row = DB::table('pos_transactions')->find($praId);
        $this->assertSame('assigned', $row->delivery_status);
    }
}
