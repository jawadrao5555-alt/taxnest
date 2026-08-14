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
            $t->boolean('kot_align_center')->default(false);
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

    private function seedPair(bool $compact, bool $align): void
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

    // ── 1. resolve(): saved pair → pre-selected card ──────────────────────

    public function test_resolve_maps_every_pair_to_the_right_preset(): void
    {
        $this->assertSame('khula', PosKotThemes::resolve(['compact' => false, 'align' => false]));
        $this->assertSame('center', PosKotThemes::resolve(['compact' => false, 'align' => true]));
        $this->assertSame('compact', PosKotThemes::resolve(['compact' => true, 'align' => false]));
        // Centered compact ticket is still the Compact preset (dominant flag).
        $this->assertSame('compact', PosKotThemes::resolve(['compact' => true, 'align' => true]));
        // Untouched company = today's default.
        $this->assertSame('khula', PosKotThemes::resolve([]));
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

    // ── 4. Blade renders the preset cards ─────────────────────────────────

    public function test_receipt_settings_blade_renders_kot_preset_cards(): void
    {
        $blade = file_get_contents(resource_path('views/pos/receipt-settings.blade.php'));
        $this->assertStringContainsString('rp_kot_theme', $blade);
        $this->assertStringContainsString('PosKotThemes', $blade);
        // The old raw controls the cards replace must be GONE from this page.
        $this->assertStringNotContainsString('rp_kot_compact', $blade);
        $this->assertStringNotContainsString("name=\"rp_kot_align_center\"", $blade);
    }
}
