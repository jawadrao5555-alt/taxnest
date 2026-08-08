<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Task #353 — Deliveries board: Local vs PRA stream distinction (ZFC).
 *
 * Locked in this suite:
 *  1. Deliveries list shows a Local chip for local bills and a PRA chip for
 *     PRA-pipeline bills (badge rendered per row).
 *  2. Stream lock: local-scoped staff see ONLY local delivery bills;
 *     pra-scoped staff see ONLY PRA delivery bills (predicate mirrors
 *     applyReportFilters / billingScopeAllowsRow).
 *  3. Default 'both' scope (admin / pos_delivery) stays stream-agnostic —
 *     sees every delivery bill (in-flight rider cash must never be hidden).
 *
 * Pattern: sqlite :memory:, minimal schema copied from PosDeliveryMarkPrepaidTest
 * (+ users.pos_billing_scope column).
 */
class PosDeliveriesStreamScopeTest extends TestCase
{
    private function buildSchema(): int
    {
        User::flushScopeColumnCache();
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
            $table->string('pos_billing_scope', 10)->nullable();
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
            $table->timestamp('prepaid_converted_at')->nullable();
            $table->unsignedBigInteger('prepaid_converted_by')->nullable();
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

        \App\Services\PosFeatureService::flushGateCaches();

        $companyId = (int) DB::table('companies')->insertGetId([
            'name'                => 'Stream Scope Test Co',
            'product_type'        => 'pos',
            'status'              => 'approved',
            'company_status'      => 'approved',
            'is_internal_account' => true,
            'feature_flags'       => json_encode(['tables' => true, 'kitchen' => true, 'kot' => true, 'delivery' => true, 'customer_profile' => true]),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name'               => 'Pro',
            'product_type'       => 'pos',
            'price'              => 0,
            'riders_enabled'     => true,
            'restaurant_enabled' => true,
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

    private function makeUser(int $companyId, string $posRole, ?string $scope = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name'              => ucfirst(str_replace('pos_', '', $posRole)),
            'email'             => $posRole . '_' . $companyId . '_' . rand(10000, 99999) . '@streamscope.test',
            'password'          => Hash::make('Secret@12'),
            'company_id'        => $companyId,
            'role'              => 'user',
            'pos_role'          => $posRole,
            'pos_billing_scope' => $scope,
            'is_active'         => true,
            'created_at'        => now(),
            'updated_at'        => now(),
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

    private function makeBill(int $companyId, int $riderId, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id'      => $companyId,
            'invoice_number'  => 'INV-' . rand(10000, 99999),
            'business_date'   => now()->toDateString(),
            'status'          => 'completed',
            'invoice_mode'    => 'pra',
            'pra_status'      => 'completed',
            'pra_invoice_number' => 'PRA-' . rand(10000, 99999),
            'is_archived'     => false,
            'order_type'      => 'delivery',
            'rider_id'        => $riderId,
            'rider_assigned_at' => now(),
            'delivery_status' => 'assigned',
            // cash on purpose: bills enter the rider-khata settle modal too, so
            // page assertions prove BOTH the table and the khata panel are
            // stream-scoped for locked staff.
            'payment_method'  => 'cash',
            'total_amount'    => 500.00,
            'subtotal'        => 500.00,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $attrs));
    }

    private function makeLocalBill(int $companyId, int $riderId, array $attrs = []): int
    {
        return $this->makeBill($companyId, $riderId, array_merge([
            'invoice_mode'       => 'local',
            'pra_status'         => 'local',
            'pra_invoice_number' => null,
        ], $attrs));
    }

    private function getBoard(User $user)
    {
        return $this->actingAs($user, 'pos')->get('/pos/deliveries');
    }

    /** Seed one local + one PRA open delivery bill; return [localInv, praInv]. */
    private function seedBothStreams(int $cid, int $riderId): array
    {
        $localInv = 'L-LOCAL-777';
        $praInv   = 'INV-PRA-888';
        $this->makeLocalBill($cid, $riderId, ['invoice_number' => $localInv]);
        $this->makeBill($cid, $riderId, ['invoice_number' => $praInv]);
        return [$localInv, $praInv];
    }

    // ── tests ────────────────────────────────────────────────────────────────

    /** Admin ('both') sees both streams, each with its stream chip. */
    public function test_admin_sees_both_streams_with_chips(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        [$localInv, $praInv] = $this->seedBothStreams($cid, $rid);
        $admin = $this->makeUser($cid, 'pos_admin');

        $res = $this->getBoard($admin)->assertOk();
        $res->assertSee($localInv);
        $res->assertSee($praInv);
        // Both chip labels rendered (Local + PRA).
        $res->assertSee(__('pos.local_word'));
        $res->assertSee(__('pos.pra_word'));
    }

    /** Delivery manager (no scope column value → 'both') also sees both. */
    public function test_delivery_manager_sees_both_streams(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        [$localInv, $praInv] = $this->seedBothStreams($cid, $rid);
        $delMgr = $this->makeUser($cid, 'pos_delivery');

        $res = $this->getBoard($delMgr)->assertOk();
        $res->assertSee($localInv);
        $res->assertSee($praInv);
    }

    /** Local-scoped manager sees ONLY the local bill. */
    public function test_local_scoped_manager_sees_only_local_bills(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        [$localInv, $praInv] = $this->seedBothStreams($cid, $rid);
        $mgr = $this->makeUser($cid, 'pos_manager', 'local');

        $res = $this->getBoard($mgr)->assertOk();
        $res->assertSee($localInv);
        $res->assertDontSee($praInv);
    }

    /** PRA-scoped manager sees ONLY the PRA bill. */
    public function test_pra_scoped_manager_sees_only_pra_bills(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        [$localInv, $praInv] = $this->seedBothStreams($cid, $rid);
        $mgr = $this->makeUser($cid, 'pos_manager', 'pra');

        $res = $this->getBoard($mgr)->assertOk();
        $res->assertDontSee($localInv);
        $res->assertSee($praInv);
    }

    /** Reporting-OFF final (invoice_mode='pra' but NULL pra trail) counts as LOCAL. */
    public function test_reporting_off_final_counts_as_local_stream(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        $offInv = 'INV-REPOFF-999';
        // pra invoice_mode but no PRA trail at all → local stream (mirrors isLocalBill()).
        $this->makeBill($cid, $rid, [
            'invoice_number'     => $offInv,
            'invoice_mode'       => 'pra',
            'pra_status'         => null,
            'pra_invoice_number' => null,
        ]);

        $localMgr = $this->makeUser($cid, 'pos_manager', 'local');
        $this->getBoard($localMgr)->assertOk()->assertSee($offInv);

        $praMgr = $this->makeUser($cid, 'pos_manager', 'pra');
        $this->getBoard($praMgr)->assertOk()->assertDontSee($offInv);
    }

    /** Local-scoped manager cannot mutate a PRA bill (assign / status / prepaid). */
    public function test_local_scoped_manager_blocked_on_pra_bill_mutations(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        $praId = $this->makeBill($cid, $rid, ['invoice_number' => 'INV-PRA-MUT']);
        $mgr = $this->makeUser($cid, 'pos_manager', 'local');

        $this->actingAs($mgr, 'pos')->post('/pos/deliveries/' . $praId . '/assign', ['rider_id' => $rid])
            ->assertForbidden();
        $this->actingAs($mgr, 'pos')->post('/pos/deliveries/' . $praId . '/status', ['delivery_status' => 'delivered'])
            ->assertForbidden();
        $this->actingAs($mgr, 'pos')->post('/pos/deliveries/' . $praId . '/mark-prepaid')
            ->assertForbidden();

        $row = DB::table('pos_transactions')->find($praId);
        $this->assertSame('assigned', $row->delivery_status, 'PRA bill must be untouched by local-scoped staff');
        $this->assertSame('cash', $row->payment_method);
    }

    /** PRA-scoped manager cannot mutate a local bill. */
    public function test_pra_scoped_manager_blocked_on_local_bill_mutations(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        $localId = $this->makeLocalBill($cid, $rid, ['invoice_number' => 'L-LOCAL-MUT']);
        $mgr = $this->makeUser($cid, 'pos_manager', 'pra');

        $this->actingAs($mgr, 'pos')->post('/pos/deliveries/' . $localId . '/status', ['delivery_status' => 'delivered'])
            ->assertForbidden();

        $this->assertSame('assigned', DB::table('pos_transactions')->find($localId)->delivery_status);
    }

    /** Settle: cross-stream cash bill_ids drop out — nothing settled. */
    public function test_local_scoped_manager_cannot_settle_pra_cash(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        $praId = $this->makeBill($cid, $rid, ['invoice_number' => 'INV-PRA-SETTLE']);
        $mgr = $this->makeUser($cid, 'pos_manager', 'local');

        $this->actingAs($mgr, 'pos')
            ->from('/pos/deliveries')
            ->post('/pos/riders/' . $rid . '/settle', ['bill_ids' => [$praId]])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull(DB::table('pos_transactions')->find($praId)->rider_settlement_id, 'PRA cash must stay unsettled');
    }

    /** Settle-all by a local-scoped manager settles ONLY local cash. */
    public function test_settle_all_scoped_to_own_stream(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        $localId = $this->makeLocalBill($cid, $rid, ['invoice_number' => 'L-LOCAL-SA']);
        $praId   = $this->makeBill($cid, $rid, ['invoice_number' => 'INV-PRA-SA']);
        $mgr = $this->makeUser($cid, 'pos_manager', 'local');

        $this->actingAs($mgr, 'pos')
            ->from('/pos/deliveries')
            ->post('/pos/riders/' . $rid . '/settle', ['settle_all' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull(DB::table('pos_transactions')->find($localId)->rider_settlement_id, 'local cash must settle');
        $this->assertNull(DB::table('pos_transactions')->find($praId)->rider_settlement_id, 'PRA cash must remain open for both-scope staff');
    }

    /** Bulk status by a local-scoped manager touches ONLY local bills. */
    public function test_bulk_status_scoped_to_own_stream(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        $localId = $this->makeLocalBill($cid, $rid, ['invoice_number' => 'L-LOCAL-BULK']);
        $praId   = $this->makeBill($cid, $rid, ['invoice_number' => 'INV-PRA-BULK']);
        $mgr = $this->makeUser($cid, 'pos_manager', 'local');

        $this->actingAs($mgr, 'pos')
            ->from('/pos/deliveries')
            ->post('/pos/deliveries/rider/' . $rid . '/bulk-status', ['delivery_status' => 'delivered'])
            ->assertRedirect();

        $this->assertSame('delivered', DB::table('pos_transactions')->find($localId)->delivery_status);
        $this->assertSame('assigned', DB::table('pos_transactions')->find($praId)->delivery_status, 'PRA bill must be untouched');
    }

    /** Admin ('both') can still settle mixed-stream cash — nothing stranded. */
    public function test_admin_settle_all_covers_both_streams(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        $localId = $this->makeLocalBill($cid, $rid, ['invoice_number' => 'L-LOCAL-ADM']);
        $praId   = $this->makeBill($cid, $rid, ['invoice_number' => 'INV-PRA-ADM']);
        $admin = $this->makeUser($cid, 'pos_admin');

        $this->actingAs($admin, 'pos')
            ->from('/pos/deliveries')
            ->post('/pos/riders/' . $rid . '/settle', ['settle_all' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull(DB::table('pos_transactions')->find($localId)->rider_settlement_id);
        $this->assertNotNull(DB::table('pos_transactions')->find($praId)->rider_settlement_id);
    }
}
