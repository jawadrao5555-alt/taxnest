<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PosReceiptThemes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 712 — Receipt Themes (named bundles over pos_style bold/logo).
 *
 * Locks:
 *   • resolve(): every stored bold/logo pair maps to the RIGHT card —
 *     bold OFF = 'saada' regardless of logo (plain opt-out shops may run a
 *     center logo; that combo is still Saada).
 *   • apply() no-op rule: re-saving the theme the company already resolves to
 *     NEVER rewrites the stored pair (owner rule: plain opt-out companies'
 *     exact combo must survive a settings re-save); only an ACTIVE switch
 *     writes the theme's canonical bundle.
 *   • PRA + FBR receipt-settings POSTs accept rp_receipt_theme, write the
 *     pair through PosReceiptThemes, keep legacy rp_style_bold/rp_logo_style
 *     working, and leave the pair untouched when NEITHER is posted.
 *   • FBR save still preserves the style keys it does not own.
 *
 * Pattern: APP_ENV=testing + SQLite :memory: + minimal Schema::create
 * (same as PosReceiptStyleGuardTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosReceiptThemeTest.php --testdox
 */
class PosReceiptThemeTest extends TestCase
{
    private int $posCompanyId;
    private int $fbrCompanyId;
    private int $posAdminId;
    private int $fbrAdminId;

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
            $t->boolean('receipt_align_center')->nullable();
            $t->integer('receipt_left_margin_mm')->nullable();
            $t->boolean('kot_align_center')->default(false);
            $t->integer('kot_left_margin_mm')->default(0);
            $t->boolean('agent_enabled')->default(false);
            $t->timestamp('agent_last_seen')->nullable();
            $t->boolean('delivery_receipt_print_on_assign')->default(false);
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
            $t->string('language')->nullable();
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
            'name' => 'Theme PRA Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Theme FBR Shop', 'product_type' => 'fbrpos',
            'fbr_pos_enabled' => true,
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->posAdminId = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'th-admin@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->posCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrAdminId = DB::table('users')->insertGetId([
            'name' => 'FBR Admin', 'email' => 'th-fbradmin@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->fbrCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function seedStyle(int $companyId, array $style): void
    {
        Company::where('id', $companyId)->update([
            'invoice_display_prefs' => json_encode(['pos_style' => $style]),
        ]);
    }

    private function rawStyle(int $companyId): array
    {
        $prefs = json_decode(Company::findOrFail($companyId)->getRawOriginal('invoice_display_prefs'), true);

        return $prefs['pos_style'] ?? [];
    }

    // ── 1. resolve(): saved pair → pre-selected card ──────────────────────

    public function test_resolve_maps_every_pair_to_the_right_theme(): void
    {
        $this->assertSame('bold_center', PosReceiptThemes::resolve(['bold' => true, 'logo' => 'center']));
        $this->assertSame('bold_side', PosReceiptThemes::resolve(['bold' => true, 'logo' => 'side']));
        $this->assertSame('saada', PosReceiptThemes::resolve(['bold' => false, 'logo' => 'side']));
        // Plain opt-out shop running a CENTER logo is still the Saada theme.
        $this->assertSame('saada', PosReceiptThemes::resolve(['bold' => false, 'logo' => 'center']));
        // Untouched company (no saved style) = today's universal default.
        $this->assertSame('bold_center', PosReceiptThemes::resolve([]));
    }

    public function test_theme_catalogue_shape(): void
    {
        $this->assertSame(['bold_center', 'bold_side', 'saada'], PosReceiptThemes::keys());
        $this->assertTrue(PosReceiptThemes::isValid('saada'));
        $this->assertFalse(PosReceiptThemes::isValid('neon'));
        $this->assertFalse(PosReceiptThemes::isValid(null));
        foreach (PosReceiptThemes::clientMap() as $def) {
            $this->assertIsBool($def['bold']);
            $this->assertContains($def['logo'], ['side', 'center']);
        }
    }

    // ── 2. apply(): no-op on re-save, canonical bundle on an active switch ──

    public function test_apply_is_noop_when_resaving_the_resolved_theme(): void
    {
        // Plain opt-out shop with a center logo: picking "Saada" (its own card)
        // must NOT rewrite logo to the canonical 'side'.
        $current = ['bold' => false, 'logo' => 'center'];
        $this->assertSame(
            ['bold' => false, 'logo' => 'center'],
            PosReceiptThemes::apply('saada', $current)
        );
    }

    public function test_apply_writes_canonical_bundle_on_active_switch(): void
    {
        $this->assertSame(
            ['bold' => true, 'logo' => 'center'],
            PosReceiptThemes::apply('bold_center', ['bold' => false, 'logo' => 'center'])
        );
        $this->assertSame(
            ['bold' => true, 'logo' => 'side'],
            PosReceiptThemes::apply('bold_side', ['bold' => true, 'logo' => 'center'])
        );
        $this->assertSame(
            ['bold' => false, 'logo' => 'side'],
            PosReceiptThemes::apply('saada', ['bold' => true, 'logo' => 'center'])
        );
    }

    // ── 3. PRA receipt-settings POST ──────────────────────────────────────

    public function test_pra_post_theme_switch_writes_canonical_pair(): void
    {
        $this->seedStyle($this->posCompanyId, ['bold' => false, 'logo' => 'center']);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_receipt_theme' => 'bold_side'])
            ->assertRedirect();

        $style = $this->rawStyle($this->posCompanyId);
        $this->assertTrue($style['bold']);
        $this->assertSame('side', $style['logo']);
    }

    public function test_pra_post_resaving_own_theme_keeps_stored_pair(): void
    {
        // The owner rule: a plain opt-out shop (bold OFF + center logo) that
        // re-saves the page with its own "Saada" card selected keeps its exact
        // combo — the canonical 'side' is NOT forced onto it.
        $this->seedStyle($this->posCompanyId, ['bold' => false, 'logo' => 'center']);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_receipt_theme' => 'saada'])
            ->assertRedirect();

        $style = $this->rawStyle($this->posCompanyId);
        $this->assertFalse($style['bold'], 'saada re-save must not flip bold');
        $this->assertSame('center', $style['logo'], 'saada re-save must not force the canonical side logo');
    }

    public function test_pra_post_without_theme_or_legacy_fields_keeps_current_pair(): void
    {
        $this->seedStyle($this->posCompanyId, ['bold' => false, 'logo' => 'side']);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_order_match' => 'token'])
            ->assertRedirect();

        $style = $this->rawStyle($this->posCompanyId);
        $this->assertFalse($style['bold'], 'a style-less POST must not clobber bold');
        $this->assertSame('side', $style['logo'], 'a style-less POST must not clobber logo');
    }

    public function test_pra_post_rejects_unknown_theme(): void
    {
        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_receipt_theme' => 'neon'])
            ->assertSessionHasErrors('rp_receipt_theme');
    }

    public function test_pra_delivery_receipt_default_is_saved_only_from_a_fresh_form(): void
    {
        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', [
                'rp_delivery_receipt_present' => '1',
                'rp_delivery_receipt_on_assign' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue((bool) Company::findOrFail($this->posCompanyId)->delivery_receipt_print_on_assign);

        // An older receipt-settings page without the marker must preserve the
        // shop's decision rather than changing it because no checkbox arrived.
        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/receipt-settings', ['rp_order_match' => 'off'])
            ->assertRedirect();

        $this->assertTrue((bool) Company::findOrFail($this->posCompanyId)->delivery_receipt_print_on_assign);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/receipt-settings', ['rp_delivery_receipt_present' => '1'])
            ->assertRedirect();

        $this->assertFalse((bool) Company::findOrFail($this->posCompanyId)->delivery_receipt_print_on_assign);
    }

    public function test_pra_real_browser_delivery_off_payload_saves_other_receipt_fields(): void
    {
        Company::whereKey($this->posCompanyId)->update(['delivery_receipt_print_on_assign' => true]);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', [
                'rp_delivery_receipt_present' => '1',
                'rp_delivery_receipt_on_assign' => '0',
                'rp_present' => '1',
                'rp_show_ntn' => '1',
                'rp_show_tax' => '1',
                'rp_footer_text' => 'Browser OFF saved',
                'rp_receipt_theme' => 'bold_side',
                'rp_printer_size' => '58mm',
                'rp_order_match' => 'code',
            ])
            ->assertRedirect('/pos/receipt-settings')
            ->assertSessionHasNoErrors();

        $company = Company::findOrFail($this->posCompanyId);
        $prefs = json_decode($company->getRawOriginal('invoice_display_prefs'), true);
        $this->assertFalse((bool) $company->delivery_receipt_print_on_assign);
        $this->assertTrue($prefs['pos']['show_ntn']);
        $this->assertSame('Browser OFF saved', $prefs['pos']['footer_text']);
        $this->assertSame('side', $prefs['pos_style']['logo']);
        $this->assertSame('58mm', $company->receipt_printer_size);
        $this->assertSame('code', $company->order_match_style);
    }

    public function test_pra_delivery_toggle_rejects_malformed_value_with_localized_message(): void
    {
        foreach ([
            'en' => 'Choose a valid delivery receipt default.',
            'rur' => 'Delivery receipt ka durust default choose karein.',
            'ur' => 'ڈیلیوری رسید کے لیے درست ڈیفالٹ منتخب کریں۔',
        ] as $locale => $message) {
            User::whereKey($this->posAdminId)->update(['language' => $locale]);

            $this->actingAs(User::find($this->posAdminId), 'pos')
                ->from('/pos/receipt-settings')
                ->post('/pos/receipt-settings', [
                    'rp_delivery_receipt_present' => '1',
                    'rp_delivery_receipt_on_assign' => 'invalid',
                ])
                ->assertSessionHasErrors([
                    'rp_delivery_receipt_on_assign' => $message,
                ]);
        }
    }

    public function test_pra_delivery_toggle_strictly_rejects_non_browser_values_without_changing_state(): void
    {
        Company::whereKey($this->posCompanyId)->update([
            'delivery_receipt_print_on_assign' => true,
            'receipt_printer_size' => '80mm',
        ]);

        foreach ([null, '', true, false, 0.0, 1.0, [], ['0']] as $malformed) {
            $this->actingAs(User::find($this->posAdminId), 'pos')
                ->from('/pos/receipt-settings')
                ->post('/pos/receipt-settings', [
                    'rp_delivery_receipt_present' => '1',
                    'rp_delivery_receipt_on_assign' => $malformed,
                    'rp_printer_size' => '58mm',
                ])
                ->assertSessionHasErrors('rp_delivery_receipt_on_assign');

            $company = Company::findOrFail($this->posCompanyId);
            $this->assertTrue((bool) $company->delivery_receipt_print_on_assign);
            $this->assertSame('80mm', $company->receipt_printer_size);
        }
    }

    public function test_pra_receipt_alignment_and_margin_accept_only_safe_contract(): void
    {
        foreach ([
            ['rp_align_center' => '0', 'rp_left_margin_mm' => '0'],
            ['rp_align_center' => '1', 'rp_left_margin_mm' => '30'],
        ] as $payload) {
            $this->actingAs(User::find($this->posAdminId), 'pos')
                ->post('/pos/receipt-settings', $payload)
                ->assertSessionHasNoErrors();
        }

        $company = Company::findOrFail($this->posCompanyId);
        $this->assertTrue((bool) $company->receipt_align_center);
        $this->assertSame(30, (int) $company->receipt_left_margin_mm);

        foreach ([
            ['rp_align_center' => 'center'],
            ['rp_align_center' => '2'],
            ['rp_left_margin_mm' => '-1'],
            ['rp_left_margin_mm' => '31'],
            ['rp_left_margin_mm' => '1.5'],
            ['rp_left_margin_mm' => 'left'],
        ] as $payload) {
            $response = $this->actingAs(User::find($this->posAdminId), 'pos')
                ->from('/pos/receipt-settings')
                ->post('/pos/receipt-settings', $payload);
            $response->assertSessionHasErrors(array_key_first($payload));
        }
    }

    public function test_pra_footer_over_limit_is_still_rejected(): void
    {
        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->from('/pos/receipt-settings')
            ->post('/pos/receipt-settings', ['rp_footer_text' => str_repeat('x', 151)])
            ->assertSessionHasErrors('rp_footer_text');
    }

    // ── 4. FBR receipt-settings POST ──────────────────────────────────────

    public function test_fbr_post_theme_switch_writes_pair_and_preserves_untouched_keys(): void
    {
        $this->seedStyle($this->fbrCompanyId, [
            'bold' => true, 'logo' => 'center',
            'pdf_paper' => 'a4', 'show_logo' => false,
            'logo_finals_only' => true, 'show_menu_qr' => false,
        ]);

        $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->from('/fbr-pos/receipt-settings')
            ->post('/fbr-pos/receipt-settings', ['rp_receipt_theme' => 'saada', 'rp_order_match' => 'off'])
            ->assertRedirect();

        $style = $this->rawStyle($this->fbrCompanyId);
        $this->assertFalse($style['bold']);
        $this->assertSame('side', $style['logo']);
        // Keys this page does not own must survive (the rebuild trap).
        $this->assertSame('a4', $style['pdf_paper']);
        $this->assertFalse($style['show_logo']);
        $this->assertTrue($style['logo_finals_only']);
        $this->assertFalse($style['show_menu_qr']);
    }

    public function test_fbr_post_legacy_fields_still_work_without_theme(): void
    {
        $this->seedStyle($this->fbrCompanyId, ['bold' => false, 'logo' => 'side']);

        $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->from('/fbr-pos/receipt-settings')
            ->post('/fbr-pos/receipt-settings', ['rp_style_bold' => '1', 'rp_logo_style' => 'center'])
            ->assertRedirect();

        $style = $this->rawStyle($this->fbrCompanyId);
        $this->assertTrue($style['bold']);
        $this->assertSame('center', $style['logo']);
    }

    public function test_fbr_post_without_style_fields_keeps_current_pair(): void
    {
        $this->seedStyle($this->fbrCompanyId, ['bold' => false, 'logo' => 'center']);

        $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->from('/fbr-pos/receipt-settings')
            ->post('/fbr-pos/receipt-settings', ['rp_order_match' => 'off'])
            ->assertRedirect();

        $style = $this->rawStyle($this->fbrCompanyId);
        $this->assertFalse($style['bold']);
        $this->assertSame('center', $style['logo']);
    }

    public function test_fbr_delivery_receipt_default_is_saved_per_shop(): void
    {
        $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->from('/fbr-pos/receipt-settings')
            ->post('/fbr-pos/receipt-settings', [
                'rp_delivery_receipt_present' => '1',
                'rp_delivery_receipt_on_assign' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue((bool) Company::findOrFail($this->fbrCompanyId)->delivery_receipt_print_on_assign);

        $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->post('/fbr-pos/receipt-settings', ['rp_order_match' => 'off'])
            ->assertRedirect();

        $this->assertTrue((bool) Company::findOrFail($this->fbrCompanyId)->delivery_receipt_print_on_assign);
    }

    public function test_fbr_real_browser_delivery_off_and_missing_toggle_payloads_save_safely(): void
    {
        $this->seedStyle($this->fbrCompanyId, [
            'bold' => true,
            'logo' => 'center',
            'pdf_paper' => 'a4',
            'show_menu_qr' => false,
        ]);
        Company::whereKey($this->fbrCompanyId)->update(['delivery_receipt_print_on_assign' => true]);

        $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->from('/fbr-pos/receipt-settings')
            ->post('/fbr-pos/receipt-settings', [
                'rp_delivery_receipt_present' => '1',
                'rp_delivery_receipt_on_assign' => '0',
                'rp_fbr_display_present' => '1',
                'rp_show_address' => '1',
                'rp_footer_text' => 'FBR browser OFF saved',
                'rp_receipt_theme' => 'bold_side',
                'rp_printer_size' => '58mm',
                'rp_order_match' => 'token',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $company = Company::findOrFail($this->fbrCompanyId);
        $prefs = json_decode($company->getRawOriginal('invoice_display_prefs'), true);
        $this->assertFalse((bool) $company->delivery_receipt_print_on_assign);
        $this->assertTrue($prefs['fbrpos']['show_address']);
        $this->assertSame('FBR browser OFF saved', $prefs['fbrpos']['footer_text']);
        $this->assertSame('side', $prefs['pos_style']['logo']);
        $this->assertSame('a4', $prefs['pos_style']['pdf_paper']);
        $this->assertFalse($prefs['pos_style']['show_menu_qr']);
        $this->assertSame('thermal58', $company->print_paper_size);
        $this->assertSame('token', $company->order_match_style);

        Company::whereKey($this->fbrCompanyId)->update(['delivery_receipt_print_on_assign' => true]);
        $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->post('/fbr-pos/receipt-settings', ['rp_delivery_receipt_present' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertFalse((bool) Company::findOrFail($this->fbrCompanyId)->delivery_receipt_print_on_assign);
    }

    public function test_fbr_delivery_toggle_rejects_malformed_value_and_footer_limit_is_unchanged(): void
    {
        $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->from('/fbr-pos/receipt-settings')
            ->post('/fbr-pos/receipt-settings', [
                'rp_delivery_receipt_present' => '1',
                'rp_delivery_receipt_on_assign' => 'invalid',
            ])
            ->assertSessionHasErrors([
                'rp_delivery_receipt_on_assign' => 'Delivery receipt ka durust default choose karein.',
            ]);

        $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->from('/fbr-pos/receipt-settings')
            ->post('/fbr-pos/receipt-settings', ['rp_footer_text' => str_repeat('x', 151)])
            ->assertSessionHasErrors('rp_footer_text');
    }

    public function test_fbr_delivery_toggle_strictly_rejects_non_browser_values_without_changing_state(): void
    {
        Company::whereKey($this->fbrCompanyId)->update([
            'delivery_receipt_print_on_assign' => true,
            'print_paper_size' => 'thermal',
        ]);

        foreach ([null, '', true, false, 0.0, 1.0, [], ['1']] as $malformed) {
            $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
                ->from('/fbr-pos/receipt-settings')
                ->post('/fbr-pos/receipt-settings', [
                    'rp_delivery_receipt_present' => '1',
                    'rp_delivery_receipt_on_assign' => $malformed,
                    'rp_printer_size' => '58mm',
                ])
                ->assertSessionHasErrors('rp_delivery_receipt_on_assign');

            $company = Company::findOrFail($this->fbrCompanyId);
            $this->assertTrue((bool) $company->delivery_receipt_print_on_assign);
            $this->assertSame('thermal', $company->print_paper_size);
        }
    }

    // ── 5. Blade renders the theme picker on BOTH screens ─────────────────

    public function test_both_receipt_settings_blades_render_theme_picker_and_preview(): void
    {
        $pra = file_get_contents(resource_path('views/pos/receipt-settings.blade.php'));
        $fbr = file_get_contents(resource_path('views/fbr-pos/receipt-settings.blade.php'));
        foreach ([$pra, $fbr] as $blade) {
            $this->assertStringContainsString('receipt-theme-cards', $blade);
            $this->assertStringContainsString('receipt-theme-preview', $blade);
            $this->assertStringContainsString('rcptThemePicker', $blade);
        }
        $cards = file_get_contents(resource_path('views/pos/partials/receipt-theme-cards.blade.php'));
        $this->assertStringContainsString('rp_receipt_theme', $cards);
    }
}
