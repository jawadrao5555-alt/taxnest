<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR settings pages must not silently switch a shop's options off (Task 1393).
 *
 * Same failure the PRA Receipt Settings page hit (Task 1377): the handler rebuilt
 * an options block wholesale from checkbox presence, with no proof the request had
 * actually carried that block. Unchecked checkboxes send nothing, so an OUTDATED
 * copy of the form (served from the service-worker runtime cache) and a form with
 * everything unticked look identical on the wire — and the outdated one wiped
 * every toggle it did not know about.
 *
 * Each page now carries a hidden per-panel marker, with a fallback that treats any
 * of that block's own fields as proof it was submitted so scripted and legacy
 * POSTs keep working. These tests lock both halves of that rule per page:
 *   - a POST missing a block leaves the stored block untouched, and
 *   - a POST that DOES carry the block can still turn everything off.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/FbrPosSettingsStaleFormGuardTest.php --testdox
 */
class FbrPosSettingsStaleFormGuardTest extends TestCase
{
    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->admin] = $this->seedShop();
    }

    // ── /fbr-pos/business-profile — the receipt-display set ─────────────────

    /** A POST that carries no rd_* field at all must NOT wipe the stored set. */
    public function test_stale_business_profile_post_does_not_wipe_the_receipt_display_set(): void
    {
        $this->company->invoice_display_prefs = [
            'fbrpos' => [
                'show_address' => true, 'show_ntn' => true, 'show_mobile' => true,
                'show_cashier' => true, 'show_footer' => true,
            ],
        ];
        $this->company->receipt_align_center   = true;
        $this->company->receipt_left_margin_mm = 7;
        $this->company->save();

        // Pre-receipt-block form: name + paper size only, not one rd_* field.
        $this->actingAs($this->admin, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name'             => 'Test FBR Shop',
                'print_paper_size' => 'thermal',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $rd = $this->company->invoice_display_prefs['fbrpos'] ?? [];
        foreach (['show_address', 'show_ntn', 'show_mobile', 'show_cashier', 'show_footer'] as $key) {
            $this->assertTrue((bool) ($rd[$key] ?? false),
                "$key must survive a POST that never carried the receipt-display block");
        }
        $this->assertTrue((bool) $this->company->receipt_align_center,
            'Print position must not be reset by a form that never carried it');
        $this->assertSame(7, (int) $this->company->receipt_left_margin_mm,
            'Left margin must not be reset by a form that never carried it');
    }

    /** The freshly rendered form (rd_present) can still turn every line off. */
    public function test_fresh_business_profile_form_can_still_turn_the_display_lines_off(): void
    {
        $this->company->invoice_display_prefs = [
            'fbrpos' => ['show_address' => true, 'show_ntn' => true, 'show_footer' => true],
        ];
        $this->company->save();

        $this->actingAs($this->admin, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name'             => 'Test FBR Shop',
                'print_paper_size' => 'thermal',
                'rd_present'       => '1',
                // every rd_show_* absent = unticked
            ])
            ->assertRedirect();

        $this->company->refresh();
        $rd = $this->company->invoice_display_prefs['fbrpos'] ?? [];
        foreach (['show_address', 'show_ntn', 'show_mobile', 'show_cashier', 'show_footer'] as $key) {
            $this->assertFalse((bool) ($rd[$key] ?? true),
                "Unticking $key on a freshly rendered form must persist");
        }
    }

    /** Legacy fallback: a POST carrying only one rd_* field still owns the block. */
    public function test_legacy_business_profile_post_without_marker_still_saves_the_block(): void
    {
        $this->actingAs($this->admin, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name'             => 'Test FBR Shop',
                'print_paper_size' => 'thermal',
                'rd_show_ntn'      => '1',
                // no rd_present — a scripted / pre-marker POST
            ])
            ->assertRedirect();

        $this->company->refresh();
        $rd = $this->company->invoice_display_prefs['fbrpos'] ?? [];
        $this->assertTrue((bool) ($rd['show_ntn'] ?? false),
            'A POST that carries the block must still write it without the marker');
        $this->assertFalse((bool) ($rd['show_address'] ?? true),
            'Its unticked siblings must still store false');
    }

    // ── /fbr-pos/printer-settings — silent print + counter KOT ──────────────

    /** A POST carrying neither tick-box must leave both stored values alone. */
    public function test_stale_printer_settings_post_does_not_switch_the_tickboxes_off(): void
    {
        $this->company->pos_printer_settings = [
            'available_printers'   => [['name' => 'EPSON-80']],
            'receipt_printer'      => 'EPSON-80',
            'kot_printer'          => 'EPSON-80',
            'counter_kot_printer'  => 'EPSON-80',
            'counter_kot_enabled'  => true,
            'silent_print_enabled' => true,
        ];
        $this->company->save();

        // Outdated copy: no tick-boxes, no printer selects.
        $this->actingAs($this->admin, 'fbrpos')
            ->post('/fbr-pos/printer-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $s = $this->company->printerSettings();
        $this->assertTrue((bool) $s['silent_print_enabled'],
            'Silent printing must survive a POST that never carried its tick-box');
        $this->assertTrue((bool) $s['counter_kot_enabled'],
            'Counter KOT must survive a POST that never carried its tick-box');
        $this->assertSame('EPSON-80', $s['receipt_printer'],
            'A form that never carried the printer picks must not unset them');
        $this->assertSame('EPSON-80', $s['kot_printer']);
    }

    /** The freshly rendered form (ps_present) can still turn silent printing off. */
    public function test_fresh_printer_settings_form_can_still_turn_silent_print_off(): void
    {
        $this->company->pos_printer_settings = [
            'available_printers'   => [['name' => 'EPSON-80']],
            'receipt_printer'      => 'EPSON-80',
            'silent_print_enabled' => true,
            'counter_kot_enabled'  => true,
        ];
        $this->company->save();

        $this->actingAs($this->admin, 'fbrpos')
            ->post('/fbr-pos/printer-settings', [
                'ps_present'      => '1',
                'receipt_printer' => 'EPSON-80',
                // silent_print_enabled / counter_kot_enabled absent = unticked
            ])
            ->assertRedirect();

        $this->company->refresh();
        $s = $this->company->printerSettings();
        $this->assertFalse((bool) $s['silent_print_enabled'],
            'Unticking silent printing on a freshly rendered form must persist');
        $this->assertFalse((bool) $s['counter_kot_enabled'],
            'Unticking counter KOT on a freshly rendered form must persist');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function seedShop(): array
    {
        $company = Company::create([
            'name'                => 'FBR Stale Form Guard Shop',
            'product_type'        => 'fbrpos',
            'status'              => 'active',
            'company_status'      => 'active',
            'is_internal_account' => false,
            'fbr_pos_enabled'     => true,
        ]);

        $user = User::create([
            'name'       => 'FBR Admin',
            'email'      => 'admin@fbrstaleform.test',
            'password'   => bcrypt('secret'),
            'company_id' => $company->id,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
        ]);

        return [$company, $user];
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('fbrpos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->text('invoice_display_prefs')->nullable();
            $t->text('pos_printer_settings')->nullable();
            $t->string('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('ntn')->nullable();
            $t->string('cnic')->nullable();
            $t->string('print_paper_size')->nullable();
            $t->string('receipt_footer_note')->nullable();
            $t->string('logo_path')->nullable();
            $t->boolean('pos_receipt_show_tax')->default(true);
            $t->boolean('kot_align_center')->default(false);
            $t->integer('kot_left_margin_mm')->default(0);
            $t->boolean('receipt_align_center')->default(false);
            $t->integer('receipt_left_margin_mm')->default(0);
            $t->string('order_match_style')->nullable();
            $t->boolean('order_match_style_locked')->default(false);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('language')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
    }
}
