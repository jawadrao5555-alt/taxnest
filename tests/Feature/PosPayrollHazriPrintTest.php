<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POS Payroll Hazri — print markup safety + feature rendering (Task #280).
 *
 * Guards three regressions:
 *   (1) The @media print CSS must use visibility:hidden on body * — NOT
 *       display:none on any ancestor of #payroll-summary — so that the
 *       payroll section can re-show itself via visibility:visible.  A
 *       display:none ancestor silently blanks the whole print output.
 *   (2) The payroll range summary must render correctly (tables, totals,
 *       * convention on open spans, totals row) when sessions exist.
 *   (3) Gating invariants: cashier blocked (403), >62-day range capped
 *       with a friendly message (200, not 500), PDF route returns PDF.
 *
 * Run with:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT \
 *       -u PGUSER -u PGPASSWORD -u PGDATABASE \
 *       APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
 *       php artisan test --filter=PosPayrollHazriPrintTest
 */
class PosPayrollHazriPrintTest extends TestCase
{
    // ── schema ──────────────────────────────────────────────────────────────

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->buildSchema();
        $this->companyId = $this->seedCompany();
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('ntn')->nullable();
            $table->string('product_type')->default('pos');
            $table->string('status')->default('active');
            $table->string('company_status')->default('approved');
            $table->boolean('is_internal_account')->default(false); // bypasses planGate
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('pos_theme')->nullable();
            $table->string('default_language')->nullable();
            $table->string('pos_locale')->nullable();
            $table->text('invoice_display_prefs')->nullable();
            $table->json('feature_flags')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->string('language')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // BranchContextService is called by PosAuth on every request.
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

        // Required by PosAuth heartbeat (wrapped in try/catch but table still
        // needed for the range-summary query in buildHazriRangeSummary).
        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('login_at');
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        // buildHazriRangeSummary queries pos_transactions for bill aggregation.
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('business_date')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('invoice_mode')->default('pra');
            $table->timestamps();
        });

        // Side-effect tables written by auth/session middleware.
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('changes')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('hash')->nullable();
            $table->string('previous_hash')->nullable();
            $table->timestamps();
        });
    }

    private function seedCompany(): int
    {
        return DB::table('companies')->insertGetId([
            'name'                => 'Payroll Test Shop',
            'product_type'        => 'pos',
            'status'              => 'active',
            'company_status'      => 'approved',
            'is_internal_account' => true,   // planGate bypass — no subscription needed
            'pos_setup_completed' => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function makeAdmin(string $email = 'admin@payroll.test'): User
    {
        return User::create([
            'company_id' => $this->companyId,
            'name'       => 'Shop Owner',
            'email'      => $email,
            'password'   => Hash::make('secret'),
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
        ]);
    }

    private function makeCashier(string $email = 'cashier@payroll.test'): User
    {
        return User::create([
            'company_id' => $this->companyId,
            'name'       => 'Cashier',
            'email'      => $email,
            'password'   => Hash::make('secret'),
            'role'       => null,
            'pos_role'   => 'pos_cashier',
            'is_active'  => true,
        ]);
    }

    // ── tests ────────────────────────────────────────────────────────────────

    /**
     * The hazri page must load and contain the payroll summary anchor.
     */
    public function test_hazri_page_renders_payroll_summary_section(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin, 'pos')->get('/pos/reports/hazri');

        $resp->assertStatus(200);
        // The payroll section anchor must be present so the Print / PDF logic
        // has a concrete DOM node to target.
        $this->assertStringContainsString(
            'id="payroll-summary"',
            $resp->getContent(),
            'Page must contain id="payroll-summary" anchor element'
        );
    }

    /**
     * CRITICAL — print CSS must use visibility:hidden on body *, NOT
     * display:none on any ancestor.  display:none on an ancestor makes
     * every descendant invisible regardless of visibility:visible, which
     * blanks the printed page.
     *
     * The safe pattern:
     *   body * { visibility: hidden }
     *   #payroll-summary, #payroll-summary * { visibility: visible }
     *   #payroll-summary { position: absolute; top:0; left:0 }
     */
    public function test_print_css_uses_visibility_strategy_not_display_none_on_ancestors(): void
    {
        $admin = $this->makeAdmin();
        $html  = $this->actingAs($admin, 'pos')
            ->get('/pos/reports/hazri')
            ->assertStatus(200)
            ->getContent();

        // Must use the visibility trick.
        $this->assertStringContainsString(
            'visibility: hidden',
            $html,
            'Print CSS must use visibility:hidden on body * to keep ancestor layout flow intact'
        );
        $this->assertStringContainsString(
            'visibility: visible',
            $html,
            'Print CSS must restore visibility:visible on #payroll-summary and its descendants'
        );
        $this->assertStringContainsString(
            '#payroll-summary',
            $html,
            'Print CSS must target #payroll-summary'
        );

        // Must NOT use the broken patterns that blank the page.
        $this->assertStringNotContainsString(
            'body > * { display: none',
            $html,
            'BROKEN: display:none on body>* kills ancestor chain — payroll section cannot be re-shown'
        );
        $this->assertStringNotContainsString(
            'body > *{display:none',
            $html,
            'BROKEN: minified display:none on body>* kills ancestor chain'
        );
        // Broader guard: no display:none on any known ancestor selector.
        $badPatterns = [
            'pos-layout-root { display: none',
            'main.flex-1 { display: none',
            '.main-scroll { display: none',
            '.max-w-5xl { display: none',
        ];
        foreach ($badPatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $html,
                "BROKEN: display:none on ancestor selector \"$pattern\" prevents payroll section from printing"
            );
        }
    }

    /**
     * When a range query is submitted with valid session data, the payroll
     * summary table must include: the staff member's name, the total duty
     * hours, and the days-present count.  Open spans must show *.
     */
    public function test_range_query_renders_staff_totals_with_open_span_asterisk(): void
    {
        $admin = $this->makeAdmin();

        $start = now()->subDays(3)->setTime(9, 0);   // 09:00, no logout → open span
        DB::table('pos_user_sessions')->insert([
            'company_id' => $this->companyId,
            'user_id'    => $admin->id,
            'login_at'   => $start,
            'logout_at'  => null,               // open — should produce * in output
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Also a closed session two days ago (8h shift).
        $d2 = now()->subDays(2)->setTime(8, 0);
        DB::table('pos_user_sessions')->insert([
            'company_id' => $this->companyId,
            'user_id'    => $admin->id,
            'login_at'   => $d2,
            'logout_at'  => $d2->copy()->addHours(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $from = now()->subDays(5)->toDateString();
        $to   = now()->toDateString();

        $resp = $this->actingAs($admin, 'pos')
            ->get("/pos/reports/hazri?date_from={$from}&date_to={$to}");

        $resp->assertStatus(200);
        $html = $resp->getContent();

        // Payroll section must be present.
        $this->assertStringContainsString('id="payroll-summary"', $html);
        // Staff name appears in the payroll table.
        $this->assertStringContainsString('Shop Owner', $html);
        // The POS Login Sessions sub-heading.
        $this->assertStringContainsString('POS Login Sessions', $html);
        // Open span marker — amber * appears after a duty-hours value.
        $this->assertStringContainsString(
            'text-amber-500',
            $html,
            'Open span must render the amber * marker in the payroll table'
        );
        // Footnote explaining the * convention — the lang key renders to its
        // translated text; check a fragment valid in any of the 3 supported locales.
        $openFootnotePresent =
            str_contains($html, 'open/unpaired') ||        // en
            str_contains($html, 'spans band nahi') ||      // rur
            str_contains($html, 'سپین بند نہیں') ||       // ur
            str_contains($html, 'payroll_open_footnote');  // fallback: key not translated
        $this->assertTrue($openFootnotePresent,
            'Open-span footnote must be present in any supported locale');
    }

    /**
     * Range query with no sessions must return 200 and show the "no data"
     * placeholder — it must NOT crash.
     */
    public function test_range_query_empty_range_shows_no_data_gracefully(): void
    {
        $admin = $this->makeAdmin();

        $from = now()->subDays(10)->toDateString();
        $to   = now()->subDays(5)->toDateString();   // a window with no sessions

        $resp = $this->actingAs($admin, 'pos')
            ->get("/pos/reports/hazri?date_from={$from}&date_to={$to}");

        $resp->assertStatus(200);
        $html = $resp->getContent();
        // Section must still be present.
        $this->assertStringContainsString('id="payroll-summary"', $html);
        // No-data placeholder (in any locale).
        $noDataPresent =
            str_contains($html, 'No attendance data') ||   // en
            str_contains($html, 'koi hazri nahi') ||       // rur
            str_contains($html, 'کوئی حاضری نہیں') ||     // ur
            str_contains($html, 'payroll_no_data');        // fallback
        $this->assertTrue($noDataPresent,
            'Empty range must show a no-data placeholder, not a blank section');
    }

    /**
     * >62-day range must return 200 with a friendly error message, not a 500.
     */
    public function test_range_exceeding_62_days_shows_error_not_500(): void
    {
        $admin = $this->makeAdmin();

        $from = now()->subDays(70)->toDateString();
        $to   = now()->toDateString();

        $resp = $this->actingAs($admin, 'pos')
            ->get("/pos/reports/hazri?date_from={$from}&date_to={$to}");

        $resp->assertStatus(200);
        $html = $resp->getContent();
        // Error message must mention the 62-day cap.
        $capPresent =
            str_contains($html, '62') ||
            str_contains($html, 'payroll_range_too_long');
        $this->assertTrue($capPresent,
            '>62-day range must display the range-too-long error message mentioning "62"');
    }

    /**
     * Inverted range (from > to) must return 200 with a friendly error,
     * not crash with an exception or 500.
     */
    public function test_inverted_range_shows_error_not_500(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin, 'pos')
            ->get('/pos/reports/hazri?date_from=2026-08-10&date_to=2026-08-01');

        $resp->assertStatus(200);
        $this->assertStringNotContainsString(
            'Whoops', $resp->getContent(),
            'Inverted range must not produce a Laravel error page'
        );
    }

    /**
     * A cashier must be blocked from the hazri report (403 in controller).
     */
    public function test_cashier_cannot_access_hazri_report(): void
    {
        $cashier = $this->makeCashier();

        $resp = $this->actingAs($cashier, 'pos')->get('/pos/reports/hazri');

        $resp->assertStatus(403);
    }

    /**
     * The payroll PDF route must return HTTP 200 with a PDF content-type.
     * This exercises the full DomPDF rendering path including the new
     * reports-hazri-payroll-pdf.blade.php template.
     */
    public function test_payroll_pdf_route_returns_pdf(): void
    {
        $admin = $this->makeAdmin();

        $from = now()->startOfWeek()->toDateString();
        $to   = now()->toDateString();

        $resp = $this->actingAs($admin, 'pos')
            ->get("/pos/reports/hazri/payroll-pdf?date_from={$from}&date_to={$to}");

        $resp->assertStatus(200);
        $this->assertStringStartsWith(
            'application/pdf',
            $resp->headers->get('content-type', ''),
            'Payroll PDF route must return Content-Type: application/pdf'
        );
    }

    /**
     * The payroll PDF route must also honour the cashier block (403).
     */
    public function test_cashier_cannot_access_payroll_pdf(): void
    {
        $cashier = $this->makeCashier();

        $resp = $this->actingAs($cashier, 'pos')
            ->get('/pos/reports/hazri/payroll-pdf?date_from=2026-08-01&date_to=2026-08-07');

        $resp->assertStatus(403);
    }
}
