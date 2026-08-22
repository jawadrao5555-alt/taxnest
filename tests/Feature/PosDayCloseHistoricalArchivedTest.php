<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HISTORICAL VIEW — archived rows on a CLOSED day (PRA Day Close fix #1).
 *
 * When dayCloseReport() renders an already-closed report, the day-close wash
 * may have ARCHIVED the very rows the frozen figures came from. The page must
 * behave exactly like dayCloseReportPdf: bypass the hide_archived global scope
 * so the historical transaction set survives, AND prefer the report's FROZEN
 * totals for the headline summary so a past day never looks empty just because
 * its local rows were archived/deleted by the wash.
 *
 * An OPEN/current day (no report yet) must keep the normal hide_archived scope
 * — this fix must never broaden ordinary open-day access.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (PosDayCloseStreamSplitTest approach).
 */
class PosDayCloseHistoricalArchivedTest extends TestCase
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
            $table->timestamp('rider_settled_at')->nullable();
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

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number')->nullable();
            $table->integer('deleted_final_count')->default(0);
            $table->integer('deleted_provisional_count')->default(0);
            $table->text('local_summary')->nullable();
            $table->text('rider_summary')->nullable();
            $table->text('stream_summary')->nullable();
            $table->text('returns_detail')->nullable();
            $table->integer('total_invoices')->default(0);
            $table->integer('pra_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('offline_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
            $table->decimal('other_amount', 14, 2)->default(0);
            $table->integer('returns_count')->default(0);
            $table->decimal('returns_amount', 14, 2)->default(0);
            $table->string('first_invoice_number')->nullable();
            $table->string('last_invoice_number')->nullable();
            $table->timestamp('first_invoice_time')->nullable();
            $table->timestamp('last_invoice_time')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('hash')->nullable();
            $table->decimal('opening_float', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
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

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('restaurant_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('business_date');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Historical Archived Co',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_dayclose_provisional_action' => 'save',
            'pos_dayclose_final_local_action' => 'save',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function makePosUser(int $companyId): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'POS Admin',
            'email' => 'admin' . $companyId . '@taxnest.test',
            'company_id' => $companyId,
            'role' => 'user',
            'pos_role' => 'pos_admin',
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
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $number,
            'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    // ── 1. closed-day page recovers archived PRA rows (mirrors the PDF) ───────

    public function test_closed_day_page_includes_archived_pra_rows(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makePosUser($companyId);

        // Two PRA sales. Close the day, then simulate the wash having archived
        // one of them (real washes archive/delete rows the page must still show).
        $keep = $this->makeTxn($companyId, 'P-0001', [
            'subtotal' => 1000, 'tax_amount' => 170, 'total_amount' => 1170,
            'created_by' => $user->id,
        ]);
        $archived = $this->makeTxn($companyId, 'P-0002', [
            'subtotal' => 500, 'tax_amount' => 85, 'total_amount' => 585,
            'created_by' => $user->id,
        ]);

        (new PosController())->performDayClose($companyId, now()->toDateString(), $user->id);

        // Archive one row AFTER the close, the way a later backlog wash would.
        DB::table('pos_transactions')->where('id', $archived)
            ->update(['is_archived' => true, 'archived_at' => now()]);

        // Sanity: default scope now hides it — the historical page must not.
        $this->assertSame(1, PosTransaction::where('company_id', $companyId)->count(),
            'hide_archived scope hides the washed row from a normal query');

        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
        $request = Request::create('/pos/day-close', 'GET', ['date' => now()->toDateString()]);

        $data = (new PosController())->dayCloseReport($request)->getData();

        // The transaction set the page renders includes BOTH bills again.
        $ids = collect($data['transactions'])->pluck('id')->all();
        $this->assertContains($keep, $ids);
        $this->assertContains($archived, $ids, 'historical view must include the wash-archived PRA row');
        $this->assertCount(2, $ids);
    }

    // ── 2. summary prefers FROZEN report totals (never looks empty) ───────────

    public function test_closed_day_summary_uses_frozen_report_totals(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makePosUser($companyId);

        $this->makeTxn($companyId, 'P-0001', [
            'subtotal' => 1000, 'tax_amount' => 170, 'total_amount' => 1170,
            'created_by' => $user->id,
        ]);

        (new PosController())->performDayClose($companyId, now()->toDateString(), $user->id);
        $report = DB::table('pos_day_close_reports')->where('company_id', $companyId)->first();

        // Extreme case: EVERY surviving row is archived — a live rebuild alone
        // would show zeroes, but the frozen totals must keep the summary honest.
        DB::table('pos_transactions')->where('company_id', $companyId)
            ->update(['is_archived' => true, 'archived_at' => now()]);

        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
        $request = Request::create('/pos/day-close', 'GET', ['date' => now()->toDateString()]);

        $data = (new PosController())->dayCloseReport($request)->getData();
        $stats = $data['stats'];

        $this->assertSame((float) $report->total_amount, (float) $stats->total_amount,
            'closed-day summary must use the frozen total, not a live recompute');
        $this->assertSame((float) $report->gross_sales, (float) $stats->gross_sales);
        $this->assertSame((float) $report->total_tax, (float) $stats->total_tax);
        $this->assertSame((int) $report->total_invoices, (int) $stats->total_invoices);
        $this->assertGreaterThan(0, (float) $stats->total_amount, 'summary must not appear empty on a closed day');
    }

    // ── 3. OPEN day keeps hide_archived (fix must not broaden access) ─────────

    public function test_open_day_still_hides_archived_rows(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makePosUser($companyId);

        $live = $this->makeTxn($companyId, 'P-0001', [
            'subtotal' => 1000, 'tax_amount' => 170, 'total_amount' => 1170,
            'created_by' => $user->id,
        ]);
        $archived = $this->makeTxn($companyId, 'P-0002', [
            'subtotal' => 500, 'tax_amount' => 85, 'total_amount' => 585,
            'created_by' => $user->id,
            'is_archived' => true, 'archived_at' => now(),
        ]);

        // No report row exists — this is the OPEN/current business day.
        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
        $request = Request::create('/pos/day-close', 'GET', ['date' => now()->toDateString()]);

        $data = (new PosController())->dayCloseReport($request)->getData();
        $this->assertNull($data['existingReport'], 'guard: this scenario must be an open day');

        $ids = collect($data['transactions'])->pluck('id')->all();
        $this->assertContains($live, $ids);
        $this->assertNotContains($archived, $ids,
            'open day must keep the hide_archived scope — the fix must not broaden open-day access');
    }

    // ── 4. PENDING LOCAL CONFIRMATION — blade + lang source lock (fix #2) ─────

    /**
     * When pending local/provisional bills exist, the close button must open a
     * MANDATORY explicit confirmation (dcConfirmPendingLocal) wired with the
     * standing policy, the all-box/per-bill overrides, and the count/total/
     * action labels. The server stays authoritative — this is the pre-submit
     * confirmation. A refactor that drops the wiring silently loses the guard.
     */
    public function test_close_form_wires_the_pending_local_confirmation(): void
    {
        $blade = file_get_contents(resource_path('views/pos/day-close.blade.php'));

        // The mandatory confirmation handler + its trigger on the submit button.
        $this->assertStringContainsString('function dcConfirmPendingLocal', $blade,
            'the explicit pending-local confirmation builder must exist');
        $this->assertStringContainsString('return dcConfirmPendingLocal(this)', $blade,
            'the close button must call the confirmation when pending local bills exist');

        // The effective-action inputs: standing policy + all-box + per-bill.
        $this->assertStringContainsString('data-standing-prov', $blade);
        $this->assertStringContainsString('data-standing-final', $blade);
        $this->assertStringContainsString("select[name=\"wash_override\"]", $blade,
            'confirmation must read the current all-box override');
        $this->assertStringContainsString('data-dc-bill-action', $blade,
            'confirmation must read current per-bill overrides');
        $this->assertStringContainsString('data-kind', $blade);
        $this->assertStringContainsString('data-amount', $blade);

        // It must surface the exact count, total amount and action labels.
        $this->assertStringContainsString('data-fallback-count', $blade);
        $this->assertStringContainsString('data-fallback-amount', $blade);
        $this->assertStringContainsString('data-fallback-prov-count', $blade);
        $this->assertStringContainsString('data-fallback-final-count', $blade);
        $this->assertStringContainsString('add(override || standingProv, provCount, provAmount)', $blade,
            'cashier fallback must aggregate the provisional bucket exactly once');
        $this->assertStringNotContainsString('Math.round(n).toLocaleString()', $blade,
            'fractional pending totals must never be rounded to a different rupee amount');
        $this->assertStringContainsString('minimumFractionDigits: 2', $blade);
        $this->assertStringContainsString('maximumFractionDigits: 2', $blade);
        $this->assertStringContainsString('dc_confirm_pending_heading', $blade);
        $this->assertStringContainsString('dc_confirm_pending_total', $blade);
        $this->assertStringContainsString('dc_confirm_pending_actions', $blade);
        $this->assertStringContainsString('dc_confirm_pending_proceed', $blade);
    }

    /** Every new confirmation lang key must exist in en / rur / ur (parity). */
    public function test_confirmation_lang_keys_exist_in_all_three_files(): void
    {
        $keys = [
            'dc_confirm_pending_heading',
            'dc_confirm_pending_total',
            'dc_confirm_pending_actions',
            'dc_confirm_pending_proceed',
            'dc_confirm_action_finalize',
            'dc_confirm_action_save',
            'dc_confirm_action_delete',
            'dc_confirm_action_carry',
        ];

        foreach (['en', 'rur', 'ur'] as $locale) {
            $lang = require base_path("lang/{$locale}/pos.php");
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $lang, "lang/{$locale}/pos.php missing {$key}");
                $this->assertNotSame('', trim((string) $lang[$key]), "lang/{$locale}/pos.php {$key} is empty");
            }
        }
    }
}
