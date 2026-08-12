<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\User;
use App\Services\PosBusinessDay;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 524 — purane (pichhle business days ke) UNASSIGNED delivery bills alag
 * "Purani deliveries" section mein, badge/counts se bahar (owner Option C:
 * chhupao NAHI, alag karo).
 *
 * Locked in this suite:
 *  1. Popup (PosController::apiProvisionalBills): har final delivery bill par
 *     is_stale_unassigned flag — TRUE sirf jab bill unassigned (rider NULL +
 *     status NULL) ho AUR uska business day current business day se pehle ka
 *     ho. Assigned/dispatched bills par flag kabhi TRUE nahi (unka behavior
 *     unchanged). 7-din se purane unassigned popup mein aate hi nahi (Task 513
 *     window as-is).
 *  2. Board (PosRiderController::deliveries): pending tab count purani
 *     unassigned ko NAHI ginta; woh $oldUnassigned (collapsed section) mein
 *     jate hain; fresh unassigned + assigned (har tareekh) main list mein.
 *
 * Pattern: sqlite :memory: + minimal schema (PosDeliveriesStreamScopeTest ka
 * superset — business_date + pos_day_close_reports included so the
 * business-day resolution is active), board via HTTP, popup via direct
 * controller call with the currentCompanyId binding.
 */
class PosOldUnassignedDeliveriesTest extends TestCase
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

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
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
            'name'                => 'Old Unassigned Test Co',
            'product_type'        => 'pos',
            'status'              => 'approved',
            'company_status'      => 'approved',
            'is_internal_account' => true,
            'feature_flags'       => json_encode(['tables' => true, 'kitchen' => true, 'kot' => true, 'delivery' => true, 'customer_profile' => true]),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        DB::table('pricing_plans')->insert([
            'id' => 1, 'name' => 'Pro', 'product_type' => 'pos', 'price' => 0,
            'riders_enabled' => true, 'restaurant_enabled' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $companyId, 'pricing_plan_id' => 1, 'status' => 'active',
            'is_active' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => $companyId);

        return $companyId;
    }

    private function makeRider(int $companyId): int
    {
        return (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $companyId, 'name' => 'Asgar', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Final PRA delivery bill. */
    private function makeFinal(int $companyId, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id'         => $companyId,
            'invoice_number'     => 'INV-' . uniqid(),
            'business_date'      => now()->toDateString(),
            'status'             => 'completed',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'completed',
            'pra_invoice_number' => 'PRA-' . uniqid(),
            'payment_method'     => 'cash',
            'order_type'         => 'delivery',
            'total_amount'       => 500.00,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $attrs));
    }

    private function popupFinals(): \Illuminate\Support\Collection
    {
        $data = (new PosController())->apiProvisionalBills(new Request())->getData(true);
        $this->assertTrue($data['success']);
        return collect($data['final_deliveries']);
    }

    private function makeAdmin(int $companyId): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'admin' . rand(10000, 99999) . '@oldunassigned.test',
            'password' => Hash::make('Secret@12'), 'company_id' => $companyId,
            'role' => 'user', 'pos_role' => 'pos_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return User::find($id);
    }

    // ── 1. Popup: is_stale_unassigned flag ──────────────────────────────────

    public function test_popup_flags_old_unassigned_but_not_fresh_or_assigned(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        $bizToday = PosBusinessDay::current($cid);
        $oldDay = \Carbon\Carbon::parse($bizToday)->subDays(3)->toDateString();

        // Fresh unassigned (aaj ka business day) — main list, no flag.
        $fresh = $this->makeFinal($cid, ['business_date' => $bizToday, 'rider_id' => null, 'delivery_status' => null]);
        // Purana unassigned (3 din pehle, 7-din window ke andar) — flagged.
        $stale = $this->makeFinal($cid, [
            'business_date' => $oldDay, 'rider_id' => null, 'delivery_status' => null,
            'created_at' => now()->subDays(3),
        ]);
        // Purana ASSIGNED — asal pending, flag kabhi nahi (behavior unchanged).
        $oldAssigned = $this->makeFinal($cid, [
            'business_date' => $oldDay, 'rider_id' => $rid, 'delivery_status' => 'assigned',
            'created_at' => now()->subDays(3),
        ]);
        // 7 din se purana unassigned — popup mein aata hi nahi (window as-is).
        $ancient = $this->makeFinal($cid, [
            'business_date' => \Carbon\Carbon::parse($bizToday)->subDays(10)->toDateString(),
            'rider_id' => null, 'delivery_status' => null,
            'created_at' => now()->subDays(10),
        ]);

        $finals = $this->popupFinals();
        $ids = $finals->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$fresh, $stale, $oldAssigned], $ids);
        $this->assertNotContains($ancient, $ids);

        $this->assertFalse($finals->firstWhere('id', $fresh)['is_stale_unassigned'], 'fresh unassigned must NOT be stale');
        $this->assertTrue($finals->firstWhere('id', $stale)['is_stale_unassigned'], 'old unassigned must be stale');
        $this->assertFalse($finals->firstWhere('id', $oldAssigned)['is_stale_unassigned'], 'assigned bill must never be stale');

        // Badge parity: response business_today must equal the same business day
        // the flag was computed against (client's isToday filter uses it).
        $data = (new PosController())->apiProvisionalBills(new Request())->getData(true);
        $this->assertSame($bizToday, $data['business_today']);
    }

    /** business_date NULL (pre-migration row) → created_at date decides. */
    public function test_popup_flag_falls_back_to_created_at_when_business_date_null(): void
    {
        $cid = $this->buildSchema();
        $bizToday = PosBusinessDay::current($cid);

        $staleNullBd = $this->makeFinal($cid, [
            'business_date' => null, 'rider_id' => null, 'delivery_status' => null,
            'created_at' => now()->subDays(3),
        ]);
        // created_at today, business_date null → fresh (never demand-hidden).
        $freshNullBd = $this->makeFinal($cid, [
            'business_date' => null, 'rider_id' => null, 'delivery_status' => null,
        ]);

        $finals = $this->popupFinals();
        $this->assertTrue($finals->firstWhere('id', $staleNullBd)['is_stale_unassigned']);
        $this->assertFalse($finals->firstWhere('id', $freshNullBd)['is_stale_unassigned']);
        // bizToday referenced so the fallback rule is anchored to the same day.
        $this->assertNotNull($bizToday);
    }

    // ── 2. Board: pending split + counts ────────────────────────────────────

    public function test_board_pending_splits_old_unassigned_out_of_count_and_list(): void
    {
        $cid = $this->buildSchema();
        $rid = $this->makeRider($cid);
        $bizToday = PosBusinessDay::current($cid);
        $oldDay = \Carbon\Carbon::parse($bizToday)->subDays(3)->toDateString();

        $freshInv = 'INV-FRESH-11';
        $staleInv = 'INV-STALE-22';
        $oldAssignedInv = 'INV-OLDASSIGN-33';
        $ancientInv = 'INV-ANCIENT-44';

        $this->makeFinal($cid, ['invoice_number' => $freshInv, 'business_date' => $bizToday, 'rider_id' => null, 'delivery_status' => null]);
        $staleId = $this->makeFinal($cid, [
            'invoice_number' => $staleInv, 'business_date' => $oldDay,
            'rider_id' => null, 'delivery_status' => null, 'created_at' => now()->subDays(3),
        ]);
        $this->makeFinal($cid, [
            'invoice_number' => $oldAssignedInv, 'business_date' => $oldDay,
            'rider_id' => $rid, 'delivery_status' => 'assigned', 'rider_assigned_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
        ]);
        // 7 din se purana unassigned — kahin nahi (na list, na section).
        $this->makeFinal($cid, [
            'invoice_number' => $ancientInv, 'rider_id' => null, 'delivery_status' => null,
            'business_date' => \Carbon\Carbon::parse($bizToday)->subDays(10)->toDateString(),
            'created_at' => now()->subDays(10),
        ]);

        $admin = $this->makeAdmin($cid);
        $res = $this->actingAs($admin, 'pos')->get('/pos/deliveries')->assertOk();

        // Tab count: fresh unassigned + old assigned = 2 (stale excluded).
        $this->assertSame(2, $res->viewData('tabCounts')['pending']);

        // Main pending list: fresh + old-assigned only.
        $mainInvs = collect($res->viewData('bills'))->pluck('invoice_number')->all();
        $this->assertEqualsCanonicalizing([$freshInv, $oldAssignedInv], $mainInvs);

        // Collapsed section: sirf purana unassigned.
        $old = collect($res->viewData('oldUnassigned'));
        $this->assertSame([$staleId], $old->pluck('id')->all());

        // Page renders section header + the stale bill stays reachable.
        $res->assertSee(__('pos.old_del_section'));
        $res->assertSee($staleInv);
        $res->assertDontSee($ancientInv);
    }

    public function test_board_without_old_unassigned_shows_no_section(): void
    {
        $cid = $this->buildSchema();
        $bizToday = PosBusinessDay::current($cid);
        $this->makeFinal($cid, ['business_date' => $bizToday, 'rider_id' => null, 'delivery_status' => null]);

        $admin = $this->makeAdmin($cid);
        $res = $this->actingAs($admin, 'pos')->get('/pos/deliveries')->assertOk();

        $this->assertSame(1, $res->viewData('tabCounts')['pending']);
        $this->assertCount(0, $res->viewData('oldUnassigned'));
        $res->assertDontSee(__('pos.old_del_section'));
    }
}
