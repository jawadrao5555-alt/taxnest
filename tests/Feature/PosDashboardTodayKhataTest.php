<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DASHBOARD "AAJ KA KHAATA" LOCK (Task 666).
 *
 * Stream-wise TODAY sale/tax summary on the PRA POS dashboard:
 *   - Canonical stream split via PosTransaction::applyStreamTab — exempt
 *     bills (pra_status='exempt_internal') belong to NO stream and are
 *     aggregated ONCE in their own bucket (both-scope view never
 *     double-counts them).
 *   - Money figures are SIGNED (returns net out, Task 570 convention);
 *     bill counts stay SALES-only.
 *   - Cash/card TAX split uses the full card-alias bucket
 *     (PosPaymentBuckets) — a legacy 'card' row must land in card, not other.
 *   - Visibility: local-scope → local only; pra-scope → pra only;
 *     both-scope admin/manager → both; both-scope plain cashier → PRA only
 *     (the Local tab itself is isPosAdmin-gated).
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (mirrors PosDashboardReturnNettingTest).
 */
class PosDashboardTodayKhataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('pos_dashboard_style')->nullable();
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
            $table->string('pos_billing_scope')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('business_date');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('read')->default(false);
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Khata Co',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function makeUser(int $companyId, string $posRole = 'pos_admin', ?string $scope = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'U' . uniqid(),
            'email' => uniqid('u') . '@taxnest.test',
            'company_id' => $companyId,
            'role' => 'user',
            'pos_role' => $posRole,
            'pos_billing_scope' => $scope,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function makeTxn(int $companyId, string $number, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'business_date' => \App\Services\PosBusinessDay::current($companyId),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $number,
            'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /**
     * The canonical khata day:
     *   PRA A — cash 1170, tax 170, SUBMITTED.
     *   PRA B — debit_card 585, tax 85, pending, mixed exempt_amount 100.
     *   PRA R — SUBMITTED cash credit note 117, tax 17 (nets A).
     *   LOC 1 — provisional (invoice_mode=local, pra_status=local), cash 500, tax 50, exempt 20.
     *   LOC 2 — reporting-OFF final (mode=pra, NULL status, no fiscal), legacy 'card' 200, tax 30.
     *   EXM   — exempt_internal bill, cash 300, tax 0.
     *
     * Expected buckets:
     *   pra   : bills 2, sale 1638, tax 238, cash_tax 153, card_tax 85, exempt_items 100, reported 1053.
     *   local : bills 2, sale 700, tax 80, cash_tax 50, card_tax 30, exempt_items 20.
     *   exempt: bills 1, sale 300.
     */
    private function seedKhataDay(int $companyId): void
    {
        $a = $this->makeTxn($companyId, 'P-0001', [
            'total_amount' => 1170, 'tax_amount' => 170, 'payment_method' => 'cash',
        ]);
        $this->makeTxn($companyId, 'P-0002', [
            'pra_status' => 'pending', 'pra_invoice_number' => null,
            'total_amount' => 585, 'tax_amount' => 85, 'exempt_amount' => 100,
            'payment_method' => 'debit_card',
        ]);
        $this->makeTxn($companyId, 'RET-0001', [
            'transaction_type' => 'return', 'parent_transaction_id' => $a,
            'total_amount' => 117, 'tax_amount' => 17, 'payment_method' => 'cash',
        ]);
        $this->makeTxn($companyId, 'L-0001', [
            'invoice_mode' => 'local', 'pra_status' => 'local', 'pra_invoice_number' => null,
            'total_amount' => 500, 'tax_amount' => 50, 'exempt_amount' => 20,
            'payment_method' => 'cash',
        ]);
        $this->makeTxn($companyId, 'P-0003', [
            'invoice_mode' => 'pra', 'pra_status' => null, 'pra_invoice_number' => null,
            'total_amount' => 200, 'tax_amount' => 30,
            'payment_method' => 'card', // legacy alias — must land in the card bucket
        ]);
        $this->makeTxn($companyId, 'EXM-0001', [
            'pra_status' => 'exempt_internal', 'pra_invoice_number' => null,
            'total_amount' => 300, 'tax_amount' => 0, 'payment_method' => 'cash',
        ]);
    }

    private function dashboardKhata(int $companyId, User $user): array
    {
        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);

        $view = (new PosController())->dashboard(Request::create('/pos/dashboard', 'GET'));
        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $view, 'dashboard must not redirect to setup');

        $khata = $view->getData()['todayKhata'] ?? null;
        $this->assertIsArray($khata, 'todayKhata must be passed to the dashboard view');

        return $khata;
    }

    // ── 1. both-scope admin: both streams + exempt bucket, no double count ──

    public function test_both_scope_admin_sees_both_streams_with_correct_netted_figures(): void
    {
        $companyId = $this->makeCompany();
        $this->seedKhataDay($companyId);

        $khata = $this->dashboardKhata($companyId, $this->makeUser($companyId, 'pos_admin'));

        $pra = $khata['pra'];
        $this->assertSame(2, $pra['bills'], 'credit note must not count as a bill');
        $this->assertSame(1638.0, $pra['sale'], '1170 + 585 − 117 return');
        $this->assertSame(238.0, $pra['tax'], '170 + 85 − 17');
        $this->assertSame(153.0, $pra['cash_tax'], '170 − 17 return');
        $this->assertSame(85.0, $pra['card_tax']);
        $this->assertSame(100.0, $pra['exempt_items'], 'mixed-bill exempt share (header exempt_amount)');
        $this->assertSame(1053.0, $pra['reported'], 'submitted only, return netted: 1170 − 117');

        $local = $khata['local'];
        $this->assertSame(2, $local['bills'], 'provisional + reporting-OFF final');
        $this->assertSame(700.0, $local['sale']);
        $this->assertSame(80.0, $local['tax']);
        $this->assertSame(50.0, $local['cash_tax']);
        $this->assertSame(30.0, $local['card_tax'], "legacy 'card' alias must land in the card bucket");
        $this->assertSame(20.0, $local['exempt_items']);

        // Exempt bills live ONLY in their own bucket — never inside a stream.
        $exempt = $khata['exempt'];
        $this->assertSame(1, $exempt['bills']);
        $this->assertSame(300.0, $exempt['sale']);
        $this->assertSame(2638.0, $pra['sale'] + $local['sale'] + $exempt['sale'], 'buckets partition the day — nothing double-counted');
    }

    // ── 2. single-scope staff see ONLY their stream ─────────────────────────

    public function test_pra_scope_cashier_gets_pra_bucket_only(): void
    {
        $companyId = $this->makeCompany();
        $this->seedKhataDay($companyId);

        $khata = $this->dashboardKhata($companyId, $this->makeUser($companyId, 'pos_cashier', 'pra'));

        $this->assertNotNull($khata['pra']);
        $this->assertNull($khata['local'], 'pra-scoped staff must never receive local figures');
        $this->assertSame(1638.0, $khata['pra']['sale']);
        $this->assertSame(1, $khata['exempt']['bills'], 'exempt bills are visible to every scope');
    }

    public function test_local_scope_cashier_gets_local_bucket_only(): void
    {
        $companyId = $this->makeCompany();
        $this->seedKhataDay($companyId);

        $khata = $this->dashboardKhata($companyId, $this->makeUser($companyId, 'pos_cashier', 'local'));

        $this->assertNull($khata['pra'], 'local-scoped staff must never receive PRA figures');
        $this->assertNotNull($khata['local']);
        $this->assertSame(700.0, $khata['local']['sale']);
        $this->assertSame(80.0, $khata['local']['tax']);
        $this->assertSame(1, $khata['exempt']['bills'], 'exempt bills are visible to every scope');
    }

    // ── 3. both-scope plain cashier: PRA only (Local tab is admin-gated) ────

    public function test_both_scope_plain_cashier_does_not_get_local_bucket(): void
    {
        $companyId = $this->makeCompany();
        $this->seedKhataDay($companyId);

        $khata = $this->dashboardKhata($companyId, $this->makeUser($companyId, 'pos_cashier'));

        $this->assertNotNull($khata['pra']);
        $this->assertNull($khata['local'], 'local figures are admin/manager-only for both-scope users');
    }

    /**
     * Task 996: khufia hidden-local mode — pos_manager with local-check OFF
     * must NOT see the Local ledger card (matches every other dashboard surface).
     */
    public function test_both_scope_manager_with_local_check_off_does_not_get_local_bucket(): void
    {
        $companyId = $this->makeCompany();
        $this->seedKhataDay($companyId);

        // local-check is OFF by default (no session key) — manager is in khufia mode
        session()->forget('pos_local_check');

        $khata = $this->dashboardKhata($companyId, $this->makeUser($companyId, 'pos_manager'));

        $this->assertNotNull($khata['pra']);
        $this->assertNull($khata['local'], 'manager in khufia mode (local-check OFF) must not see the local card');
    }

    /**
     * Task 996: when the pos_manager activates khufia local-check mode
     * (Ctrl+Alt+Shift+L → pos_local_check session flag), the Local card reappears.
     */
    public function test_both_scope_manager_with_local_check_on_gets_both_buckets(): void
    {
        $companyId = $this->makeCompany();
        $this->seedKhataDay($companyId);

        // Simulate khufia local-check mode ON
        session(['pos_local_check' => true]);

        $khata = $this->dashboardKhata($companyId, $this->makeUser($companyId, 'pos_manager'));

        $this->assertNotNull($khata['pra']);
        $this->assertNotNull($khata['local'], 'manager with local-check ON must see the local card');
        $this->assertSame(700.0, $khata['local']['sale']);

        session()->forget('pos_local_check');
    }

    /**
     * Task 996: a pos_manager whose billing scope is locked to 'local' is NOT
     * subject to the khufia hide rule — they see their local world regardless.
     */
    public function test_local_scoped_manager_always_sees_local_bucket(): void
    {
        $companyId = $this->makeCompany();
        $this->seedKhataDay($companyId);

        // local-check OFF — but scope forces local, so posHidesLocalStream() is false
        session()->forget('pos_local_check');

        $khata = $this->dashboardKhata($companyId, $this->makeUser($companyId, 'pos_manager', 'local'));

        $this->assertNull($khata['pra'], 'local-scoped manager has no PRA access');
        $this->assertNotNull($khata['local'], 'local-scoped manager always sees local regardless of local-check');
        $this->assertSame(700.0, $khata['local']['sale']);
    }

    // ── 4. rendered partial ──────────────────────────────────────────────────

    public function test_partial_renders_both_cards_and_single_exempt_row(): void
    {
        $companyId = $this->makeCompany();
        $this->seedKhataDay($companyId);

        $khata = $this->dashboardKhata($companyId, $this->makeUser($companyId, 'pos_admin'));
        $html = view('pos.partials.today-khata', ['todayKhata' => $khata])->render();

        $this->assertStringContainsString(e(__('pos.khata_title')), $html); // Blade-escaped apostrophe
        $this->assertStringContainsString(__('pos.khata_pra_stream'), $html);
        $this->assertStringContainsString(__('pos.khata_local_stream'), $html);
        $this->assertStringContainsString('Rs 1,638', $html);
        $this->assertStringContainsString('Rs 700', $html);
        $this->assertStringContainsString('Rs 1,053', $html, 'PRA reported line');
        $this->assertSame(1, substr_count($html, __('pos.khata_exempt_bills')), 'exempt bills row rendered exactly ONCE');
        $this->assertStringContainsString('Rs 300', $html);
    }

    public function test_partial_hides_exempt_row_when_day_has_no_exempt_bills(): void
    {
        $companyId = $this->makeCompany();
        $this->makeTxn($companyId, 'P-0001', ['total_amount' => 100, 'tax_amount' => 14]);

        $khata = $this->dashboardKhata($companyId, $this->makeUser($companyId, 'pos_admin'));
        $html = view('pos.partials.today-khata', ['todayKhata' => $khata])->render();

        $this->assertStringContainsString(__('pos.khata_pra_stream'), $html);
        $this->assertStringNotContainsString(__('pos.khata_exempt_bills'), $html);
    }
}
