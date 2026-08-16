<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PosKotThemes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 716 — KOT theme presets (named bundles over kot_compact + kot_align_center).
 * Task 718 — Pizza Master default: kot_align_center is NULLABLE; NULL = no
 * explicit choice = the CENTER preset (center-bold KOT). Explicit false =
 * deliberate left (khula/compact). Receipts must never move when the KOT
 * default or a KOT write changes (receipt_align_center freeze guards).
 *
 * Locks:
 *   • resolve(): every stored compact/align pair maps to the RIGHT card —
 *     compact ON = 'compact' regardless of alignment (a centered compact
 *     ticket is still the Compact preset).
 *   • apply() no-op rule: re-saving the preset the company already resolves
 *     to NEVER rewrites the stored pair; only an ACTIVE switch writes the
 *     preset's canonical bundle.
 *   • PRA receipt-settings POST accepts rp_kot_theme, writes both columns
 *     through PosKotThemes, keeps the legacy rp_kot_align_center /
 *     rp_kot_compact fields working WITHOUT a theme, ignores legacy
 *     kot_compact when a valid theme is present, and leaves the pair
 *     untouched when nothing relevant is posted.
 *
 * Pattern: APP_ENV=testing + SQLite :memory: + minimal Schema::create
 * (same as PosReceiptThemeTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosKotThemeTest.php --testdox
 */
class PosKotThemeTest extends TestCase
{
    private int $posCompanyId;
    private int $posAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('owner_name')->nullable();
            $t->string('product_type')->nullable();
            $t->string('status')->default('approved');
            $t->string('company_status')->default('approved');
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->string('ntn')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('mobile')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('business_activity')->nullable();
            $t->string('website')->nullable();
            $t->string('logo_path')->nullable();
            $t->text('invoice_display_prefs')->nullable();
            $t->text('pos_printer_settings')->nullable();
            $t->boolean('pos_receipt_show_tax')->default(true);
            $t->string('receipt_printer_size')->nullable();
            $t->string('print_paper_size')->nullable();
            $t->string('receipt_footer_note')->nullable();
            $t->string('order_match_style')->default('off');
            $t->boolean('kot_compact')->default(false);
            // Task 718: mirrors the post-migration prod schema — NULLABLE, NULL default.
            $t->boolean('kot_align_center')->nullable()->default(null);
            $t->boolean('receipt_align_center')->nullable()->default(null);
            $t->integer('receipt_left_margin_mm')->nullable()->default(null);
            // Columns updateKitchenSettings writes unguarded (freeze-guard test).
            $t->boolean('kds_enabled')->default(false);
            $t->boolean('kitchen_printer_enabled')->default(false);
            $t->boolean('print_on_hold')->default(false);
            $t->boolean('print_on_pay')->default(false);
            $t->integer('kot_left_margin_mm')->default(0);
            $t->boolean('agent_enabled')->default(false);
            $t->timestamp('agent_last_seen')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->text('pos_custom_access')->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });

        $now = now();
        $this->posCompanyId = DB::table('companies')->insertGetId([
            'name' => 'KOT Theme Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->posAdminId = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'kt-admin@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->posCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function seedPair(bool $compact, ?bool $align): void
    {
        Company::where('id', $this->posCompanyId)->update([
            'kot_compact' => $compact,
            'kot_align_center' => $align,
        ]);
    }

    private function pair(): array
    {
        $c = Company::findOrFail($this->posCompanyId);

        return [
            'compact' => (bool) $c->getRawOriginal('kot_compact'),
            'align' => (bool) $c->getRawOriginal('kot_align_center'),
        ];
    }

    /** Raw nullable align — NULL means "no explicit choice" (Task 718). */
    private function rawAlign(?int $companyId = null): ?bool
    {
        $v = DB::table('companies')->where('id', $companyId ?? $this->posCompanyId)->value('kot_align_center');

        return $v === null ? null : (bool) $v;
    }

    private function rawReceiptAlign(): ?bool
    {
        $v = DB::table('companies')->where('id', $this->posCompanyId)->value('receipt_align_center');

        return $v === null ? null : (bool) $v;
    }

    // ── 1. resolve(): saved pair → pre-selected card ──────────────────────

    public function test_resolve_maps_every_pair_to_the_right_preset(): void
    {
        // Explicit false = deliberate left (khula) — the Task 718 opt-out.
        $this->assertSame('khula', PosKotThemes::resolve(['compact' => false, 'align' => false]));
        $this->assertSame('center', PosKotThemes::resolve(['compact' => false, 'align' => true]));
        $this->assertSame('compact', PosKotThemes::resolve(['compact' => true, 'align' => false]));
        // Centered compact ticket is still the Compact preset (dominant flag).
        $this->assertSame('compact', PosKotThemes::resolve(['compact' => true, 'align' => true]));
        // Task 757: NULL / untouched company = LEFT (khula) — the settings UI
        // now matches actual print behaviour (v6-safe left-pinned NULL).
        $this->assertSame('khula', PosKotThemes::resolve(['compact' => false, 'align' => null]));
        $this->assertSame('khula', PosKotThemes::resolve([]));
        $this->assertSame(PosKotThemes::DEFAULT, PosKotThemes::resolve([]));
    }

    public function test_align_bool_null_means_left_default(): void
    {
        // Task 757: NULL = no explicit choice = LEFT (prints left-pinned).
        $this->assertFalse(PosKotThemes::alignBool(null));
        $this->assertTrue(PosKotThemes::alignBool(true));
        $this->assertFalse(PosKotThemes::alignBool(false));
        $this->assertFalse(PosKotThemes::alignBool(0));
        $this->assertTrue(PosKotThemes::alignBool('1'));
    }

    public function test_preset_catalogue_shape(): void
    {
        $this->assertSame(['khula', 'center', 'compact'], PosKotThemes::keys());
        $this->assertTrue(PosKotThemes::isValid('center'));
        $this->assertFalse(PosKotThemes::isValid('neon'));
        $this->assertFalse(PosKotThemes::isValid(null));
    }

    // ── 2. apply(): no-op on re-save, canonical bundle on an active switch ──

    public function test_apply_is_noop_when_resaving_the_resolved_preset(): void
    {
        // A compact shop that ALSO centered its ticket (set from kitchen
        // settings): re-picking its own "Compact" card must NOT reset align.
        $current = ['compact' => true, 'align' => true];
        $this->assertSame(
            ['compact' => true, 'align' => true],
            PosKotThemes::apply('compact', $current)
        );
    }

    public function test_apply_on_untouched_company_null_align(): void
    {
        // NULL align resolves to 'center' — re-saving the pre-selected Center
        // card is a NO-OP (explicit true = same render), while picking Khula is
        // an ACTIVE switch that writes the deliberate-left opt-out.
        $this->assertSame(
            ['compact' => false, 'align' => true],
            PosKotThemes::apply('center', ['compact' => false, 'align' => null])
        );
        $this->assertSame(
            ['compact' => false, 'align' => false],
            PosKotThemes::apply('khula', ['compact' => false, 'align' => null])
        );
    }

    public function test_apply_writes_canonical_bundle_on_active_switch(): void
    {
        $this->assertSame(
            ['compact' => false, 'align' => true],
            PosKotThemes::apply('center', ['compact' => false, 'align' => false])
        );
        $this->assertSame(
            ['compact' => true, 'align' => false],
            PosKotThemes::apply('compact', ['compact' => false, 'align' => true])
        );
        $this->assertSame(
            ['compact' => false, 'align' => false],
            PosKotThemes::apply('khula', ['compact' => true, 'align' => true])
        );
    }

    // ── 3. PRA receipt-settings POST ──────────────────────────────────────

    public function test_post_theme_switch_writes_canonical_pair(): void
    {
        $this->seedPair(false, false);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_kot_theme' => 'compact'])
            ->assertRedirect();

        $this->assertSame(['compact' => true, 'align' => false], $this->pair());
    }

    public function test_post_resaving_own_preset_keeps_stored_pair(): void
    {
        // Compact + centered combo must survive a re-save of its own card.
        $this->seedPair(true, true);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_kot_theme' => 'compact'])
            ->assertRedirect();

        $this->assertSame(['compact' => true, 'align' => true], $this->pair());
    }

    public function test_post_theme_beats_legacy_kot_compact_field(): void
    {
        // Old cached form posting BOTH: the named theme must win.
        $this->seedPair(false, false);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_kot_theme' => 'center', 'rp_kot_compact' => '1'])
            ->assertRedirect();

        $this->assertSame(['compact' => false, 'align' => true], $this->pair());
    }

    public function test_post_legacy_fields_still_work_without_theme(): void
    {
        $this->seedPair(false, false);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_kot_align_center' => '1', 'rp_kot_compact' => '1'])
            ->assertRedirect();

        $this->assertSame(['compact' => true, 'align' => true], $this->pair());
    }

    public function test_post_without_kot_fields_keeps_current_pair(): void
    {
        $this->seedPair(true, true);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_order_match' => 'off'])
            ->assertRedirect();

        $this->assertSame(['compact' => true, 'align' => true], $this->pair());
    }

    public function test_post_rejects_unknown_preset(): void
    {
        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_kot_theme' => 'neon'])
            ->assertSessionHasErrors('rp_kot_theme');
    }

    // ── 3b. Task 718: default flip + receipt freeze guards ────────────────

    public function test_post_khula_on_untouched_company_writes_explicit_left_optout(): void
    {
        // Untouched company (NULL align) renders center by default; picking the
        // Khula card must be an ACTIVE switch that STICKS (explicit false).
        $this->seedPair(false, null);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_kot_theme' => 'khula'])
            ->assertRedirect();

        $this->assertFalse($this->rawAlign());
    }

    public function test_post_kot_theme_freezes_null_receipt_align(): void
    {
        // Theme-only POST (no rp_align_center — partial/cached form): writing
        // kot_align_center while receipt_align_center is NULL must freeze the
        // receipt at its CURRENT effective position (NULL kot → left) so the
        // 80/58mm + proof-bill fallback never starts centering receipts.
        $this->seedPair(false, null);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_kot_theme' => 'center'])
            ->assertRedirect();

        $this->assertTrue($this->rawAlign());           // KOT explicitly centered
        $this->assertFalse($this->rawReceiptAlign());   // receipts frozen LEFT
    }

    public function test_post_kot_theme_respects_existing_receipt_align(): void
    {
        // A shop that already centered its RECEIPTS keeps that choice untouched.
        $this->seedPair(false, null);
        DB::table('companies')->where('id', $this->posCompanyId)->update(['receipt_align_center' => true]);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_kot_theme' => 'center'])
            ->assertRedirect();

        $this->assertTrue($this->rawReceiptAlign());
    }

    public function test_kitchen_settings_save_freezes_null_receipt_align(): void
    {
        // Kitchen-settings always writes kot_align_center explicitly (center is
        // now pre-selected) — the receipt fallback must be frozen first.
        $this->seedPair(false, null);
        app()->instance('currentCompanyId', $this->posCompanyId);

        $request = \Illuminate\Http\Request::create('/pos/restaurant/kitchen-settings', 'POST', [
            'kot_align_center' => '1',
            'kot_compact' => '0',
            'kot_show_customer' => '1',
            'kot_show_orderby' => '1',
            'kot_show_barcode' => '1',
            'kot_show_footer' => '1',
        ]);
        app(\App\Http\Controllers\RestaurantPosController::class)->updateKitchenSettings($request);

        $this->assertTrue($this->rawAlign());
        $this->assertFalse($this->rawReceiptAlign());   // receipts stay LEFT
    }

    public function test_migration_backfills_receipts_and_nulls_untouched_left(): void
    {
        // Rebuild companies as the PRE-718 prod shape: NOT NULL default(false).
        Schema::drop('companies');
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('kot_compact')->default(false);
            $t->boolean('kot_align_center')->default(false);
            $t->integer('kot_left_margin_mm')->default(0);
            $t->boolean('receipt_align_center')->nullable()->default(null);
            $t->timestamps();
        });
        $now = now();
        $mk = fn (array $a) => DB::table('companies')->insertGetId($a + [
            'name' => 'M', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $untouched = $mk(['kot_align_center' => 0]);
        $compactShop = $mk(['kot_align_center' => 0, 'kot_compact' => 1]);
        $marginShop = $mk(['kot_align_center' => 0, 'kot_left_margin_mm' => 5]);
        $centerShop = $mk(['kot_align_center' => 1]);
        $receiptSet = $mk(['kot_align_center' => 0, 'receipt_align_center' => 1]);

        $migration = require database_path('migrations/2026_08_14_150000_kot_align_default_center_pizza_master.php');
        $migration->up();

        $align = fn (int $id) => DB::table('companies')->where('id', $id)->value('kot_align_center');
        $rcpt = fn (int $id) => DB::table('companies')->where('id', $id)->value('receipt_align_center');

        // Untouched shop: KOT flips to the center default (NULL), receipt frozen LEFT.
        $this->assertNull($align($untouched));
        $this->assertEquals(0, $rcpt($untouched));
        // Deliberate left layouts (compact / margin) keep their explicit left.
        $this->assertEquals(0, $align($compactShop));
        $this->assertEquals(0, $align($marginShop));
        // Centered shop unchanged; its receipt fallback frozen as CENTER.
        $this->assertEquals(1, $align($centerShop));
        $this->assertEquals(1, $rcpt($centerShop));
        // An explicit receipt choice is never overwritten by the backfill.
        $this->assertEquals(1, $rcpt($receiptSet));

        // Idempotency: a deliberate post-718 LEFT save survives a re-run.
        DB::table('companies')->where('id', $untouched)->update(['kot_align_center' => 0]);
        $migration->up();
        $this->assertEquals(0, $align($untouched));
    }

    // ── 4. Blade renders the preset cards ─────────────────────────────────

    public function test_receipt_settings_blade_renders_kot_preset_cards(): void
    {
        $blade = file_get_contents(resource_path('views/pos/receipt-settings.blade.php'));
        $this->assertStringContainsString('rp_kot_theme', $blade);
        $this->assertStringContainsString('PosKotThemes', $blade);
        // The old raw controls the cards replace must be GONE from this page.
        $this->assertStringNotContainsString('rp_kot_compact', $blade);
        $this->assertStringNotContainsString("name=\"rp_kot_align_center\"", $blade);
        // Task 718: resolve() must get the RAW nullable align (NULL → center
        // card pre-selected) — a `?? false` here would pre-select Khula while
        // the ticket actually prints centered.
        $this->assertStringContainsString("'align' => \$company->kot_align_center])", $blade);
    }

    // ── 5. Task 718: read-path defaults stay split (KOT center, receipts left) ──

    public function test_kot_and_receipt_blades_keep_their_own_null_defaults(): void
    {
        // PRA kitchen ticket: NULL → LEFT (v6-safe, Task 756 regression fix).
        // The old `?? true` emitted margin:auto for NULL companies, which on
        // A4-default Windows queues shifted the 72mm body off the thermal head
        // → blank KOT. Only explicit true (owner opt-in) may centre.
        $kot = file_get_contents(resource_path('views/pos/restaurant/kitchen-ticket.blade.php'));
        $this->assertStringContainsString('$company->kot_align_center ?? false', $kot);
        // The dangerous margin:auto rule must be gated on the explicit-true
        // flag, NEVER unconditionally or for the NULL/default path.
        $this->assertStringNotContainsString('$company->kot_align_center ?? true', $kot,
            'kitchen-ticket must not use ?? true — NULL must default to left (v6-safe), not centering CSS');

        // PRA receipts + proof bill: explicit receipt column first, then the
        // kot fallback with a LEFT tail — NULL kot must never center receipts.
        foreach (['pos/receipts/receipt_80mm', 'pos/receipts/receipt_58mm', 'pos/restaurant/proof-bill'] as $view) {
            $src = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString(
                '$company->receipt_align_center ?? $company->kot_align_center ?? false',
                $src,
                "{$view} lost its receipt-first fallback chain"
            );
        }

        // FBR receipt + Z-report (Task 828): now read receipt_align_center, fully
        // decoupled from kot_align_center — NULL must stay LEFT (`?? false`).
        foreach (['fbr-pos/receipt', 'fbr-pos/day-close-thermal'] as $view) {
            $src = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString('$company->receipt_align_center ?? false', $src, "{$view} must use dedicated receipt column with left default");
            $this->assertStringNotContainsString('$company->kot_align_center ?? false', $src, "{$view} must not fall back to kot column for receipt position");
        }
    }
}
