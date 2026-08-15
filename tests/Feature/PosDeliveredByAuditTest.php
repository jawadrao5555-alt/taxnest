<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosRiderController;
use App\Http\Controllers\PosRiderController;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * Task 786 — Deliveries board: who closed an unassigned delivery bill and when.
 *
 * Locked here:
 *  1. PRA: marking a riderless (rider_id=NULL) delivery bill as delivered
 *     stamps delivered_by = auth('pos')->id() and delivered_at.
 *  2. FBR: the same via the FBR controller uses auth('fbrpos')->id()
 *     NOT auth('pos')->id() — guard isolation (the bug caught in review).
 *  3. PRA board deliveries(): passes a deliveredByUsers map with the
 *     closer's name for no-rider delivered bills on the Delivered tab.
 *  4. FBR board deliveries(): same for FBR.
 *
 * Pattern: sqlite :memory: + minimal Schema::create + direct controller
 * invocation with manual auth-guard binding (mirrors FbrPosOldUnassignedDeliveriesTest).
 * Direct calls avoid HTTP middleware / layout rendering while still exercising
 * the full controller + model + DB layer.
 */
class PosDeliveredByAuditTest extends TestCase
{
    private int $companyId;

    // ── Schema + seed ─────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        User::flushScopeColumnCache();
        Schema::dropAllTables();
        $this->buildSchema();
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
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
            $table->boolean('fbr_pos_enabled')->default(false);
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
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            // Task 786: who closed a riderless delivery bill.
            $table->unsignedBigInteger('delivered_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_rider_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id');
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->integer('bill_count')->default(0);
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

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        // FBR tables

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            // Task 786: who closed a riderless delivery bill.
            $table->unsignedBigInteger('delivered_by')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        PosFeatureService::flushGateCaches();

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name'                => 'Audit Test Shop',
            'product_type'        => 'pos',
            'status'              => 'approved',
            'company_status'      => 'approved',
            'is_internal_account' => true,
            'feature_flags'       => json_encode(['delivery' => true, 'customer_profile' => true]),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name'           => 'Pro',
            'product_type'   => 'pos',
            'price'          => 0,
            'riders_enabled' => true,
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('subscriptions')->insert([
            'company_id'      => $this->companyId,
            'pricing_plan_id' => $planId,
            'status'          => 'active',
            'is_active'       => true,
            'active'          => true,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $posRole, string $name = 'Test User'): User
    {
        $id = DB::table('users')->insertGetId([
            'name'       => $name,
            'email'      => str_replace(' ', '.', strtolower($name)) . rand(1000, 9999) . '@test.local',
            'password'   => Hash::make('pass'),
            'company_id' => $this->companyId,
            'role'       => 'user',
            'pos_role'   => $posRole,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return User::find($id);
    }

    /** Bind PRA auth guard + currentCompanyId (direct-controller pattern). */
    private function praAuth(User $user): void
    {
        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $this->companyId);
    }

    /** Bind FBR auth guard + currentCompanyId (direct-controller pattern). */
    private function fbrAuth(User $user): void
    {
        Auth::guard('fbrpos')->setUser($user);
        app()->instance('currentCompanyId', $this->companyId);
    }

    private function makePraBill(array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id'         => $this->companyId,
            'invoice_number'     => 'PRA-' . rand(10000, 99999),
            'business_date'      => now()->toDateString(),
            'status'             => 'completed',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'completed',
            'pra_invoice_number' => 'PRAINV-' . rand(10000, 99999),
            'is_archived'        => false,
            'order_type'         => 'delivery',
            'rider_id'           => null,
            'delivery_status'    => null,
            'rider_settlement_id' => null,
            'total_amount'       => 500,
            'payment_method'     => 'cash',
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $attrs));
    }

    private function makeFbrBill(array $attrs = []): int
    {
        return (int) DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id'      => $this->companyId,
            'invoice_number'  => 'FBR-' . rand(10000, 99999),
            'status'          => 'completed',
            'invoice_mode'    => 'fbr',
            'order_type'      => 'delivery',
            'rider_id'        => null,
            'delivery_status' => null,
            'rider_settlement_id' => null,
            'total_amount'    => 600,
            'payment_method'  => 'cash',
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $attrs));
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * PRA: marking a riderless bill delivered stamps delivered_by = auth('pos') user id.
     */
    public function test_pra_riderless_delivered_stamps_delivered_by_and_at(): void
    {
        $admin  = $this->makeUser('pos_admin', 'Raza Admin');
        $billId = $this->makePraBill();

        $this->praAuth($admin);

        $req = Request::create('/pos/deliveries/' . $billId . '/status', 'POST', [
            'delivery_status' => 'delivered',
        ]);
        (new PosRiderController())->updateStatus($req, $billId);

        $row = DB::table('pos_transactions')->find($billId);
        $this->assertSame('delivered', $row->delivery_status,
            'delivery_status must be set to delivered');
        $this->assertNotNull($row->delivered_at,
            'delivered_at must be stamped');
        $this->assertSame((int) $admin->id, (int) $row->delivered_by,
            'delivered_by must be the authenticated POS (pos guard) user id');
    }

    /**
     * FBR: marking a riderless bill delivered stamps delivered_by = auth('fbrpos') user id,
     * NOT auth('pos')->id() — guard isolation invariant.
     */
    public function test_fbr_riderless_delivered_stamps_delivered_by_via_fbrpos_guard(): void
    {
        $admin  = $this->makeUser('pos_admin', 'Noor FBR Admin');
        $billId = $this->makeFbrBill();

        // Bind ONLY the fbrpos guard — pos guard is deliberately empty to
        // confirm auth('pos')->id() would return null and the old bug would
        // have stored null instead of the real user id.
        $this->fbrAuth($admin);

        $req = Request::create('/fbr-pos/deliveries/' . $billId . '/status', 'POST', [
            'delivery_status' => 'delivered',
        ]);
        (new FbrPosRiderController())->updateStatus($req, $billId);

        $row = DB::table('fbr_pos_transactions')->find($billId);
        $this->assertSame('delivered', $row->delivery_status,
            'FBR delivery_status must be set to delivered');
        $this->assertNotNull($row->delivered_at,
            'FBR delivered_at must be stamped');
        $this->assertSame((int) $admin->id, (int) $row->delivered_by,
            'FBR delivered_by must be auth(fbrpos) user id, not null');

        // Confirm that if we had used auth('pos') it would have been null —
        // making the guard isolation fix observable in the test.
        $this->assertNull(Auth::guard('pos')->id(),
            'pos guard must be empty in an FBR-only session (auth isolation)');
    }

    /**
     * PRA board: deliveries() passes a deliveredByUsers map that includes
     * the closer's name for a riderless delivered bill.
     */
    public function test_pra_board_passes_closer_name_in_view_data(): void
    {
        $closer = $this->makeUser('pos_admin', 'Kamran Closer');
        // Use PosBusinessDay::current so business_date matches the controller's
        // own filter even when tests run before 06:00 UTC (the default cutoff).
        $bizDate = \App\Services\PosBusinessDay::current($this->companyId);
        $this->makePraBill([
            'business_date'   => $bizDate,
            'delivery_status' => 'delivered',
            'delivered_at'    => now(),
            'delivered_by'    => $closer->id,
        ]);

        $this->praAuth($closer);

        $req  = Request::create('/pos/deliveries', 'GET', ['tab' => 'delivered']);
        $view = (new PosRiderController())->deliveries($req);

        // Controller returns a View when all gates pass.
        $this->assertInstanceOf(\Illuminate\View\View::class, $view,
            'deliveries() must return a View (not a redirect) for a valid admin');

        $data = $view->getData();
        $this->assertArrayHasKey('deliveredByUsers', $data,
            'view data must include deliveredByUsers map');
        $this->assertArrayHasKey((int) $closer->id, $data['deliveredByUsers'],
            'deliveredByUsers must contain an entry for the closer\'s user id');
        $this->assertSame('Kamran Closer', $data['deliveredByUsers'][(int) $closer->id],
            'deliveredByUsers must map the user id to their name');
    }

    /**
     * FBR board: deliveries() passes a deliveredByUsers map that includes
     * the closer's name for a riderless delivered bill.
     */
    public function test_fbr_board_passes_closer_name_in_view_data(): void
    {
        $closer = $this->makeUser('pos_admin', 'Tariq FBR Closer');
        $this->makeFbrBill([
            'delivery_status' => 'delivered',
            'delivered_at'    => now(),
            'delivered_by'    => $closer->id,
        ]);

        $this->fbrAuth($closer);

        $req  = Request::create('/fbr-pos/deliveries', 'GET', ['tab' => 'delivered']);
        $view = (new FbrPosRiderController())->deliveries($req);

        $this->assertInstanceOf(\Illuminate\View\View::class, $view,
            'FBR deliveries() must return a View (not a redirect) for a valid admin');

        $data = $view->getData();
        $this->assertArrayHasKey('deliveredByUsers', $data,
            'FBR view data must include deliveredByUsers map');
        $this->assertArrayHasKey((int) $closer->id, $data['deliveredByUsers'],
            'FBR deliveredByUsers must contain an entry for the closer\'s user id');
        $this->assertSame('Tariq FBR Closer', $data['deliveredByUsers'][(int) $closer->id],
            'FBR deliveredByUsers must map the user id to their name');
    }
}
