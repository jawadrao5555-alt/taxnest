<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\PosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pending Bills tile — visibility + count invariants (Task 153).
 *
 * The tile (resources/views/pos/partials/pending-bills-tile.blade.php) is a
 * confidential admin/manager-only surface shared by the PRA (Task 109) and
 * FBR (Task 112) dashboards. This test locks the rules a future refactor
 * could silently break:
 *
 *   1. FBR dashboard counts ONLY today's provisional bills — the triple
 *      filter completed + invoice_mode='local' + fbr_status='local'.
 *      FINAL (fbr-mode) bills, pending bills, non-completed bills and
 *      yesterday's bills must NEVER be counted.
 *   2. Cashiers never see the tile — not even when the count is 0 — and the
 *      non-restaurant rule hides it for admins too when nothing is pending.
 *   3. The FBR tile links to fbrpos.transactions?tab=local; the PRA default
 *      links to pos.local.index.
 *   4. PRA dashboard applies the same triple filter (pra_status='local') on
 *      the current BUSINESS day, and the same admin-only flag.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controllers invoked directly with the currentCompanyId container binding,
 * mirroring FbrPosPendingDeliveriesPanelTest. Controller view data is
 * combined with a real render of the shared tile partial so both the query
 * and the Blade guard are exercised.
 */
class PosPendingBillsTileTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('pos_dashboard_style')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('invoice_number');
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            // Task 503: the real schema has business_date (trading-day bucket,
            // set by the model's creating hook). Without it, whereBizDate()
            // falls back to whereDate(created_at), which diverges from the
            // trading day during the 00:00–cutoff PKT window → midnight flake.
            $table->string('business_date')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });

        // Task 479: dashboard() now runs stranded-day detection against
        // fbr_day_close_reports — the table must exist for the FBR tests.
        Schema::create('fbr_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('read')->default(false);
            $table->timestamps();
        });

        // PosBusinessDay consults day-close reports pre-cutoff.
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('report_date');
            $table->timestamps();
        });

        // BranchContextService — empty tables → no branch filter applied.
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Tile Shop',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        Auth::guard('fbrpos')->logout();
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    protected function actAs(string $guard, string $posRole): User
    {
        $id = DB::table('users')->insert([
            'company_id' => $this->companyId,
            'name' => 'U-' . $posRole,
            'role' => 'user',
            'pos_role' => $posRole,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::orderByDesc('id')->first();
        Auth::guard($guard)->setUser($user);

        return $user;
    }

    protected function fbrBill(array $attrs = []): int
    {
        // DB::table bypasses the FbrPosTransaction creating hook, so set
        // business_date explicitly the same way the hook does (Task 503).
        return DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'F-' . uniqid(),
            'status' => 'completed',
            'invoice_mode' => 'local',
            'fbr_status' => 'local',
            'business_date' => \App\Services\PosBusinessDay::currentFbr($this->companyId),
            'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function praBill(array $attrs = []): int
    {
        return DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'P-' . uniqid(),
            'status' => 'completed',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'business_date' => \App\Services\PosBusinessDay::current($this->companyId),
            'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** Render the shared tile partial exactly like the FBR dashboard includes it. */
    protected function renderFbrTile(array $viewData): string
    {
        return view('pos.partials.pending-bills-tile', array_merge($viewData, [
            'isRestaurant' => false,
            'pendingBillsUrl' => route('fbrpos.transactions', ['tab' => 'local']),
        ]))->render();
    }

    /** Render the shared tile partial exactly like the PRA dashboard includes it. */
    protected function renderPraTile(array $viewData): string
    {
        return view('pos.partials.pending-bills-tile', $viewData)->render();
    }

    // ── FBR dashboard ────────────────────────────────────────────────────────

    public function test_fbr_admin_sees_tile_with_only_todays_provisionals_counted(): void
    {
        $this->actAs('fbrpos', 'pos_admin');

        // Counted: two of today's provisional (local/local completed) bills.
        $this->fbrBill();
        $this->fbrBill();
        // NEVER counted: FINAL (reporting-off invariant: fbr mode + NULL status).
        $this->fbrBill(['invoice_mode' => 'fbr', 'fbr_status' => null]);
        // NEVER counted: FBR-mode pending bill.
        $this->fbrBill(['invoice_mode' => 'fbr', 'fbr_status' => 'pending']);
        // NEVER counted: non-completed local bill.
        $this->fbrBill(['status' => 'draft']);
        // NEVER counted: previous trading day's provisional.
        $prevBiz = \Carbon\Carbon::parse(\App\Services\PosBusinessDay::currentFbr($this->companyId))
            ->subDay()->toDateString();
        $this->fbrBill(['business_date' => $prevBiz, 'created_at' => now()->subDay()]);

        $data = (new FbrPosController())->dashboard()->getData();

        $this->assertSame(2, $data['pendingProvisional']);
        $this->assertTrue($data['isAdmin']);

        $html = $this->renderFbrTile($data);
        $this->assertNotSame('', trim($html), 'Tile must render for admin with pending provisionals');
        $this->assertStringContainsString('>2</span>', $html);
        // Provisional link must go to the FBR local-bills tab, never the PRA portal.
        $this->assertStringContainsString(route('fbrpos.transactions', ['tab' => 'local']), $html);
        $this->assertStringNotContainsString(route('pos.local.index'), $html);
    }

    public function test_fbr_manager_counts_as_admin_but_cashier_never_sees_tile(): void
    {
        $this->fbrBill(); // one pending provisional exists

        $this->actAs('fbrpos', 'pos_manager');
        $this->assertTrue((new FbrPosController())->dashboard()->getData()['isAdmin']);

        $this->actAs('fbrpos', 'pos_cashier');
        $data = (new FbrPosController())->dashboard()->getData();
        $this->assertFalse($data['isAdmin']);
        // Even with a non-zero confidential count, the tile output is empty.
        $this->assertSame(1, $data['pendingProvisional']);
        $this->assertSame('', trim($this->renderFbrTile($data)));
    }

    public function test_fbr_pending_count_correct_just_after_midnight(): void
    {
        // Task 503 regression: 00:30 PKT is pre-cutoff, so the open trading
        // day is YESTERDAY. Bills created "now" must still be counted as
        // today's pending provisionals (business_date bucket, not calendar
        // DATE(created_at)).
        \Carbon\Carbon::setTestNow(
            \Carbon\Carbon::today(config('app.timezone'))->addMinutes(30)
        );

        $this->actAs('fbrpos', 'pos_admin');
        $this->fbrBill();
        $this->fbrBill();
        // Previous trading day's provisional — never counted.
        $prevBiz = \Carbon\Carbon::parse(\App\Services\PosBusinessDay::currentFbr($this->companyId))
            ->subDay()->toDateString();
        $this->fbrBill(['business_date' => $prevBiz, 'created_at' => now()->subDay()]);

        $data = (new FbrPosController())->dashboard()->getData();
        $this->assertSame(2, $data['pendingProvisional']);
    }

    public function test_fbr_dashboard_blade_passes_local_tab_url_and_non_restaurant(): void
    {
        // Lock the include contract: the FBR dashboard wrapper must keep
        // passing its own local-bills URL and isRestaurant=false.
        $blade = file_get_contents(resource_path('views/fbr-pos/dashboard.blade.php'));
        $this->assertStringContainsString("pos.partials.pending-bills-tile", $blade);
        $this->assertStringContainsString("'isRestaurant' => false", $blade);
        $this->assertStringContainsString("route('fbrpos.transactions', ['tab' => 'local'])", $blade);
    }

    // ── PRA retail dashboard ─────────────────────────────────────────────────

    public function test_pra_admin_sees_tile_with_triple_filtered_count_and_local_portal_link(): void
    {
        $this->actAs('pos', 'pos_admin');

        // Counted: today's provisional.
        $this->praBill();
        // NEVER counted: PRA-final bill.
        $this->praBill(['invoice_mode' => 'pra', 'pra_status' => 'submitted']);
        // NEVER counted: reporting-off FINAL (pra mode + NULL status).
        $this->praBill(['invoice_mode' => 'pra', 'pra_status' => null]);
        // NEVER counted: draft local bill.
        $this->praBill(['status' => 'draft']);
        // NEVER counted: previous business day's provisional.
        $this->praBill(['business_date' => now()->subDays(2)->toDateString()]);

        $data = (new PosController())->dashboard(new Request())->getData();

        $this->assertSame(1, $data['pendingProvisional']);
        $this->assertTrue($data['isAdmin']);
        $this->assertFalse($data['isRestaurant']);

        $html = $this->renderPraTile($data);
        $this->assertNotSame('', trim($html));
        $this->assertStringContainsString('>1</span>', $html);
        // Default provisional link = the isolated Local Bills portal.
        $this->assertStringContainsString(route('pos.local.index'), $html);
    }

    public function test_pra_cashier_never_sees_tile_even_with_pending_bills(): void
    {
        $this->praBill();

        $this->actAs('pos', 'pos_cashier');
        $data = (new PosController())->dashboard(new Request())->getData();

        $this->assertFalse($data['isAdmin']);
        $this->assertSame('', trim($this->renderPraTile($data)));
    }

    public function test_non_restaurant_admin_with_zero_count_sees_no_tile(): void
    {
        // Non-restaurant rule: retail shops without provisionals get no
        // permanent clutter — the tile is hidden at count 0.
        $this->actAs('pos', 'pos_admin');
        $data = (new PosController())->dashboard(new Request())->getData();

        $this->assertSame(0, $data['pendingProvisional']);
        $this->assertSame('', trim($this->renderPraTile($data)));
    }

    public function test_restaurant_flag_shows_tile_at_zero_but_never_for_cashier(): void
    {
        // The restaurant dashboard passes isRestaurant=true — tile stays
        // visible at 0 for admins (open-tables card), still never for cashiers.
        $base = ['pendingProvisional' => 0, 'openOrdersCount' => 0];

        $adminHtml = view('pos.partials.pending-bills-tile', $base + [
            'isAdmin' => true, 'isRestaurant' => true,
        ])->render();
        $this->assertNotSame('', trim($adminHtml));

        $cashierHtml = view('pos.partials.pending-bills-tile', $base + [
            'isAdmin' => false, 'isRestaurant' => true,
        ])->render();
        $this->assertSame('', trim($cashierHtml));
    }
}
