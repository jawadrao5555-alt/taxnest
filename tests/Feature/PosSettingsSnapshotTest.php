<?php

namespace Tests\Feature;

use App\Services\PosSettingsSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Owner rule (Sep 2026): a feature update must change ONLY that feature. No
 * shop's settings, per-branch values, staff permissions, feature toggles or
 * saved preferences may be reset by shipping something else.
 *
 * These tests lock the guard that proves it:
 *   • every settings column is covered by default (deny-list, not allow-list),
 *     so a setting added next month is protected without anyone registering it;
 *   • columns that move during normal trading are excluded, or a real
 *     regression would drown in expected noise;
 *   • a reset setting is FATAL, while a new shop / new column / declared change
 *     is not — otherwise the guard cries wolf and stops being run;
 *   • a JSON preferences blob whose keys were merely reordered, and an integer
 *     that live PDO hands back as a string, are NOT changes.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosSettingsSnapshotTest.php --testdox
 */
class PosSettingsSnapshotTest extends TestCase
{
    private PosSettingsSnapshot $snap;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            // Settings surface
            $t->string('pos_theme')->nullable();
            $t->string('pos_dashboard_style')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('pos_receipt_show_tax')->default(true);
            $t->text('feature_flags')->nullable();
            $t->text('invoice_display_prefs')->nullable();
            $t->decimal('pos_tax_rate', 8, 2)->default(0);
            // Volatile surface — must be excluded
            $t->unsignedBigInteger('pos_invoice_counter')->default(0);
            $t->string('fbr_pos_token')->nullable();
            $t->timestamp('agent_last_seen')->nullable();
            $t->unsignedInteger('ai_credits')->default(0);
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->string('address')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name');
            $t->string('password');
            $t->string('role')->nullable();
            $t->text('pos_custom_access')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Pizza Shop', 'pos_theme' => 'emerald', 'pos_dashboard_style' => 'saaf', 'inventory_enabled' => 1, 'pos_receipt_show_tax' => 0, 'feature_flags' => '{"deals":true,"riders":false}', 'invoice_display_prefs' => '{"pos":{"logo":true}}', 'pos_tax_rate' => 16, 'pos_invoice_counter' => 120, 'fbr_pos_token' => 'aaa', 'ai_credits' => 5],
            ['id' => 2, 'name' => 'Kiryana', 'pos_theme' => 'purple', 'pos_dashboard_style' => 'default', 'inventory_enabled' => 0, 'pos_receipt_show_tax' => 1, 'feature_flags' => '{"deals":false}', 'invoice_display_prefs' => null, 'pos_tax_rate' => 0, 'pos_invoice_counter' => 3, 'fbr_pos_token' => null, 'ai_credits' => 0],
        ]);
        DB::table('branches')->insert([
            ['id' => 10, 'company_id' => 1, 'name' => 'Head Office', 'address' => 'Lahore', 'is_head_office' => 1, 'is_active' => 1],
            ['id' => 11, 'company_id' => 1, 'name' => 'Branch 2', 'address' => 'Model Town', 'is_head_office' => 0, 'is_active' => 1],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'company_id' => 1, 'name' => 'Owner', 'password' => 'x', 'role' => 'pos_admin', 'pos_custom_access' => '{"reprint":true}', 'is_active' => 1],
            ['id' => 2, 'company_id' => 1, 'name' => 'Cashier', 'password' => 'x', 'role' => 'pos_cashier', 'pos_custom_access' => '{"reprint":false}', 'is_active' => 1],
        ]);

        $this->snap = new PosSettingsSnapshot();
    }

    /** Deny-list, not allow-list: settings in, trading noise out. */
    public function test_setting_columns_include_settings_and_exclude_volatile_ones(): void
    {
        $cols = $this->snap->settingColumns('companies');

        foreach (['pos_theme', 'pos_dashboard_style', 'inventory_enabled', 'pos_receipt_show_tax', 'feature_flags', 'invoice_display_prefs', 'pos_tax_rate', 'name'] as $keep) {
            $this->assertContains($keep, $cols, "settings column {$keep} must be snapshotted");
        }
        foreach (['id', 'created_at', 'updated_at', 'pos_invoice_counter', 'fbr_pos_token', 'agent_last_seen', 'ai_credits'] as $drop) {
            $this->assertNotContains($drop, $cols, "volatile column {$drop} must NOT be snapshotted");
        }
    }

    public function test_snapshot_of_an_unchanged_database_reports_nothing(): void
    {
        $before = $this->snap->capture();
        $after = $this->snap->capture();

        $diff = $this->snap->diff($before, $after);

        $this->assertSame([], $diff['changed']);
        $this->assertSame([], $diff['added_rows']);
        $this->assertSame([], $diff['removed_rows']);
        $this->assertSame([], $diff['new_columns']);
    }

    /** The regression this whole guard exists for. */
    public function test_a_reset_company_setting_is_reported_with_its_company(): void
    {
        $before = $this->snap->capture();
        DB::table('companies')->where('id', 1)->update(['pos_receipt_show_tax' => 1, 'pos_theme' => 'purple']);
        $after = $this->snap->capture();

        $diff = $this->snap->diff($before, $after);

        $this->assertCount(2, $diff['changed']);
        $columns = array_column($diff['changed'], 'column');
        sort($columns);
        $this->assertSame(['pos_receipt_show_tax', 'pos_theme'], $columns);
        foreach ($diff['changed'] as $c) {
            $this->assertSame('companies', $c['table']);
            $this->assertSame('1', $c['company_id']);
        }
    }

    public function test_a_wiped_json_preference_blob_is_reported(): void
    {
        $before = $this->snap->capture();
        DB::table('companies')->where('id', 1)->update(['invoice_display_prefs' => null]);
        $after = $this->snap->capture();

        $diff = $this->snap->diff($before, $after);

        $this->assertCount(1, $diff['changed']);
        $this->assertSame('invoice_display_prefs', $diff['changed'][0]['column']);
        $this->assertNull($diff['changed'][0]['after']);
    }

    /** A settings re-save legitimately reorders JSON keys — that is not a reset. */
    public function test_reordering_json_keys_is_not_a_change(): void
    {
        $before = $this->snap->capture();
        DB::table('companies')->where('id', 1)->update(['feature_flags' => '{"riders":false,"deals":true}']);
        $after = $this->snap->capture();

        $this->assertSame([], $this->snap->diff($before, $after)['changed']);
    }

    /**
     * Live PDO hands back non-cast integer columns as STRINGS while dev hands
     * back ints. If the snapshot stored the raw driver value, every boolean
     * toggle on the shard would read as "changed" the first time a baseline
     * taken on one host was compared on the other. Everything is normalized to
     * a string at capture time so both hosts agree.
     */
    public function test_values_are_normalized_so_driver_representation_cannot_look_like_a_change(): void
    {
        $row = $this->snap->capture()['tables']['companies']['1'];

        $this->assertSame('1', $row['inventory_enabled'], 'booleans/ints must normalize to a string');
        $this->assertSame('0', $row['pos_receipt_show_tax']);
        // 16.00 and 16 are the same tax rate, whichever way the driver renders it.
        $this->assertSame('16', $row['pos_tax_rate']);
        // NULL stays NULL, never the string "null" — otherwise a wiped
        // preferences blob would compare equal to a shop that never set one.
        $unset = $this->snap->capture()['tables']['companies']['2'];
        $this->assertNull($unset['invoice_display_prefs']);
        $this->assertArrayHasKey('invoice_display_prefs', $unset);
    }

    public function test_a_new_shop_signing_up_is_not_a_regression(): void
    {
        $before = $this->snap->capture();
        DB::table('companies')->insert(['id' => 3, 'name' => 'New Shop', 'pos_theme' => 'blue']);
        $after = $this->snap->capture();

        $diff = $this->snap->diff($before, $after);

        $this->assertSame([], $diff['changed']);
        $this->assertSame(1, $diff['added_rows']['companies']);
    }

    /** The feature being shipped adds its own column — that is the deploy, not a reset. */
    public function test_a_new_settings_column_is_reported_but_not_fatal(): void
    {
        $before = $this->snap->capture();
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('pos_new_toggle')->default(false);
        });
        $after = $this->snap->capture();

        $diff = $this->snap->diff($before, $after);

        $this->assertSame([], $diff['changed']);
        $this->assertContains('pos_new_toggle', $diff['new_columns']['companies']);
    }

    public function test_a_deleted_shop_is_reported_as_a_removed_row_not_a_change(): void
    {
        $before = $this->snap->capture();
        DB::table('companies')->where('id', 2)->delete();
        $after = $this->snap->capture();

        $diff = $this->snap->diff($before, $after);

        $this->assertSame([], $diff['changed']);
        $this->assertSame(1, $diff['removed_rows']['companies']);
    }

    /** Staff permissions are settings too. */
    public function test_a_reset_staff_permission_is_caught(): void
    {
        $before = $this->snap->capture();
        DB::table('users')->where('id', 2)->update(['pos_custom_access' => null]);
        $after = $this->snap->capture();

        $diff = $this->snap->diff($before, $after);

        $this->assertCount(1, $diff['changed']);
        $this->assertSame('users', $diff['changed'][0]['table']);
        $this->assertSame('pos_custom_access', $diff['changed'][0]['column']);
        $this->assertSame('1', $diff['changed'][0]['company_id'], 'the diff must name the SHOP, not just the user row');
    }

    /** A change the deploy meant to make is declared, and stops being fatal. */
    public function test_an_allowed_column_moves_out_of_the_fatal_list(): void
    {
        $before = $this->snap->capture();
        DB::table('companies')->where('id', 1)->update(['pos_theme' => 'rose']);
        $after = $this->snap->capture();

        $diff = $this->snap->diff($before, $after, ['pos_theme']);

        $this->assertSame([], $diff['changed']);
        $this->assertCount(1, $diff['allowed']);
        $this->assertSame('pos_theme', $diff['allowed'][0]['column']);
    }

    /** Allow-listing one column must not blind the guard to the others. */
    public function test_allow_list_does_not_hide_an_undeclared_change(): void
    {
        $before = $this->snap->capture();
        DB::table('companies')->where('id', 1)->update(['pos_theme' => 'rose', 'inventory_enabled' => 0]);
        $after = $this->snap->capture();

        $diff = $this->snap->diff($before, $after, ['pos_theme']);

        $this->assertCount(1, $diff['changed']);
        $this->assertSame('inventory_enabled', $diff['changed'][0]['column']);
    }

    public function test_company_filter_snapshots_only_that_shop(): void
    {
        $snapshot = $this->snap->capture(1);

        // PHP casts numeric-string array keys back to int — compare as strings.
        $this->assertSame(['1'], array_map('strval', array_keys($snapshot['tables']['companies'])));
        $this->assertSame(['1', '2'], array_map('strval', array_keys($snapshot['tables']['users'])));
    }

    /** A table that does not exist yet on this host must never be fatal. */
    public function test_a_missing_table_is_skipped_silently(): void
    {
        // branch_user is not created in this test schema.
        $this->assertArrayNotHasKey('branch_user', $this->snap->capture()['tables']);
    }

    /** Per-branch values are settings too — the guard must actually cover them. */
    public function test_branch_settings_are_snapshotted_and_a_reset_is_caught(): void
    {
        $before = $this->snap->capture();
        DB::table('branches')->where('id', 10)->update(['is_active' => 0]);
        $after = $this->snap->capture();

        $diff = $this->snap->diff($before, $after);

        $this->assertCount(1, $diff['changed']);
        $this->assertSame('branches', $diff['changed'][0]['table']);
        $this->assertSame('is_active', $diff['changed'][0]['column']);
        $this->assertSame('1', $diff['changed'][0]['company_id']);
    }

    /**
     * The users table is column-SCOPED: permissions in, identity out. A
     * baseline file that carried every staff name and email would be both a
     * privacy problem and the reason the snapshot runs out of memory on a big
     * host (which silently disarms the whole guard).
     */
    public function test_user_snapshot_covers_permissions_but_not_identity(): void
    {
        $cols = $this->snap->settingColumns('users');

        $this->assertContains('pos_custom_access', $cols);
        $this->assertContains('role', $cols);
        $this->assertContains('is_active', $cols);
        $this->assertNotContains('name', $cols, 'staff names must stay out of the baseline file');
        $this->assertNotContains('password', $cols);
    }

    /** Dropping a settings column destroys every shop's value — always fatal. */
    public function test_a_dropped_settings_column_is_fatal_even_with_an_allow_list(): void
    {
        $path = sys_get_temp_dir() . '/tn-settings-' . uniqid() . '.json';
        $this->artisan('pos:settings-snapshot', ['--out' => $path])->assertExitCode(0);

        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('pos_theme');
        });

        $this->artisan('pos:settings-snapshot', ['--compare' => $path])->assertExitCode(1);
        $this->artisan('pos:settings-snapshot', ['--compare' => $path, '--allow' => 'pos_theme'])
            ->assertExitCode(1);

        @unlink($path);
    }

    public function test_command_passes_on_a_clean_deploy_and_fails_on_a_reset(): void
    {
        $path = sys_get_temp_dir() . '/tn-settings-' . uniqid() . '.json';

        $this->artisan('pos:settings-snapshot', ['--out' => $path])->assertExitCode(0);
        $this->assertFileExists($path);

        $this->artisan('pos:settings-snapshot', ['--compare' => $path])->assertExitCode(0);

        DB::table('companies')->where('id', 1)->update(['pos_dashboard_style' => 'default']);
        $this->artisan('pos:settings-snapshot', ['--compare' => $path])->assertExitCode(1);
        $this->artisan('pos:settings-snapshot', ['--compare' => $path, '--allow' => 'pos_dashboard_style'])->assertExitCode(0);

        @unlink($path);
    }

    public function test_command_fails_clearly_when_the_baseline_is_missing(): void
    {
        $this->artisan('pos:settings-snapshot', ['--compare' => sys_get_temp_dir() . '/nope-' . uniqid() . '.json'])
            ->assertExitCode(1);
    }
}
