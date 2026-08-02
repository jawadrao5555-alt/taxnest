<?php

namespace Tests\Feature;

use App\Http\Middleware\SetPosLocale;
use App\Models\User;
use App\Support\PosLocale;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Three-language system (Task #235, owner 2 Aug 2026):
 *   'en' = English, 'rur' = Roman Urdu (default), 'ur' = real Urdu script.
 *
 * Invariants locked here:
 *   1. Per-user switching persists for ALL THREE locales (POS + FBR POS).
 *   2. Invalid values never persist; SetPosLocale falls back to 'rur'.
 *   3. Company default inheritance: users.language NULL → company default.
 *   4. MIGRATION TRAP: pre-split 'ur' preferences meant ROMAN Urdu — the
 *      split migration must remap users + company defaults 'ur' → 'rur'
 *      so nobody's UI flips to Urdu script on deploy.
 *   5. lang/en, lang/rur, lang/ur pos.php stay key-synced; rur keeps the
 *      Roman values; ur carries the script values.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PosThreeLanguageSystemTest.php
 */
class PosThreeLanguageSystemTest extends TestCase
{
    private int $companyId;
    private int $adminId;
    private int $cashierId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('default_language', 5)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language', 5)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

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

        $now = now();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Lang Shop', 'product_type' => 'pos',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->adminId = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'lang-admin@test.pk',
            'password' => Hash::make('Secret@12345'), 'company_id' => $this->companyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->cashierId = DB::table('users')->insertGetId([
            'name' => 'Cashier', 'email' => 'lang-cashier@test.pk',
            'password' => Hash::make('Secret@12345'), 'company_id' => $this->companyId,
            'role' => 'user', 'pos_role' => 'cashier',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Run SetPosLocale for a pos-guard user against a given path. */
    private function resolveLocale(?int $userId, string $path = '/pos/dashboard'): string
    {
        app()->setLocale('en'); // known starting point
        if ($userId) {
            auth()->guard('pos')->setUser(User::find($userId));
        }
        $request = Request::create($path, 'GET');
        (new SetPosLocale())->handle($request, fn ($r) => response('ok'));
        $locale = app()->getLocale();
        auth()->guard('pos')->forgetUser();

        return $locale;
    }

    // ── 1. per-user switching persists (all three) ────────────────────────

    public function test_pos_user_can_switch_between_all_three_languages(): void
    {
        foreach (['en', 'rur', 'ur'] as $lang) {
            $resp = $this->actingAs(User::find($this->cashierId), 'pos')
                ->post('/pos/set-language', ['language' => $lang]);
            $resp->assertRedirect();
            $this->assertSame($lang, User::find($this->cashierId)->language, "switch to {$lang} must persist");
        }
    }

    public function test_fbrpos_user_can_switch_between_all_three_languages(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['product_type' => 'fbrpos', 'fbr_pos_enabled' => true]);
        foreach (['ur', 'rur', 'en'] as $lang) {
            $resp = $this->actingAs(User::find($this->cashierId), 'fbrpos')
                ->post('/fbr-pos/set-language', ['language' => $lang]);
            $resp->assertRedirect();
            $this->assertSame($lang, User::find($this->cashierId)->language, "fbrpos switch to {$lang} must persist");
        }
    }

    public function test_invalid_language_value_is_never_persisted(): void
    {
        DB::table('users')->where('id', $this->cashierId)->update(['language' => 'rur']);
        foreach (['xx', 'urdu', '', 'UR'] as $bad) {
            $this->actingAs(User::find($this->cashierId), 'pos')
                ->post('/pos/set-language', ['language' => $bad]);
            $this->assertSame('rur', User::find($this->cashierId)->language, "invalid [{$bad}] must not persist");
        }
    }

    // ── 2. company default: picker + inheritance ──────────────────────────

    public function test_admin_can_set_all_three_company_default_languages(): void
    {
        foreach (['en', 'rur', 'ur'] as $lang) {
            $this->actingAs(User::find($this->adminId), 'pos')
                ->post('/pos/settings/default-language', ['default_language' => $lang]);
            $this->assertSame($lang, DB::table('companies')->where('id', $this->companyId)->value('default_language'));
        }
        // Invalid never sticks.
        $this->actingAs(User::find($this->adminId), 'pos')
            ->post('/pos/settings/default-language', ['default_language' => 'nope']);
        $this->assertSame('ur', DB::table('companies')->where('id', $this->companyId)->value('default_language'));
    }

    public function test_cashier_cannot_set_company_default_language(): void
    {
        $this->actingAs(User::find($this->cashierId), 'pos')
            ->post('/pos/settings/default-language', ['default_language' => 'en'])
            ->assertForbidden();
    }

    public function test_user_without_own_choice_inherits_company_default(): void
    {
        foreach (['en', 'rur', 'ur'] as $lang) {
            DB::table('companies')->where('id', $this->companyId)->update(['default_language' => $lang]);
            $this->assertSame($lang, $this->resolveLocale($this->cashierId));
        }
    }

    public function test_user_choice_beats_company_default(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['default_language' => 'en']);
        DB::table('users')->where('id', $this->cashierId)->update(['language' => 'ur']);
        $this->assertSame('ur', $this->resolveLocale($this->cashierId));
    }

    public function test_invalid_stored_values_fall_back_to_roman_urdu(): void
    {
        DB::table('users')->where('id', $this->cashierId)->update(['language' => 'xx']);
        DB::table('companies')->where('id', $this->companyId)->update(['default_language' => 'yy']);
        $this->assertSame(PosLocale::DEFAULT, $this->resolveLocale($this->cashierId));
        $this->assertSame('rur', PosLocale::DEFAULT);
    }

    public function test_non_pos_paths_are_untouched(): void
    {
        app()->setLocale('en');
        $request = Request::create('/di/dashboard', 'GET');
        (new SetPosLocale())->handle($request, fn ($r) => response('ok'));
        $this->assertSame('en', app()->getLocale());
    }

    // ── 3. migration trap: pre-split 'ur' users stay ROMAN ────────────────

    public function test_split_migration_remaps_legacy_ur_to_rur(): void
    {
        DB::table('users')->where('id', $this->cashierId)->update(['language' => 'ur']);   // pre-split = Roman
        DB::table('users')->where('id', $this->adminId)->update(['language' => 'en']);     // untouched
        DB::table('companies')->where('id', $this->companyId)->update(['default_language' => 'ur']);

        $migration = require base_path('database/migrations/2026_08_02_090000_split_urdu_script_locale.php');
        $migration->up();

        $this->assertSame('rur', DB::table('users')->where('id', $this->cashierId)->value('language'));
        $this->assertSame('en', DB::table('users')->where('id', $this->adminId)->value('language'));
        $this->assertSame('rur', DB::table('companies')->where('id', $this->companyId)->value('default_language'));

        // Post-migration, an explicit 'ur' means Urdu script and must survive re-runs untouched
        // ONLY via new user choice — the migration itself never runs twice (migrations table).
    }

    // ── 4. lang files: three-way key sync + right values in right file ────

    public function test_three_lang_files_are_key_synced(): void
    {
        $en = require base_path('lang/en/pos.php');
        $rur = require base_path('lang/rur/pos.php');
        $ur = require base_path('lang/ur/pos.php');

        $this->assertSame(array_keys($en), array_keys($rur), 'lang/en and lang/rur keys diverged');
        $this->assertSame(array_keys($en), array_keys($ur), 'lang/en and lang/ur keys diverged');
    }

    public function test_rur_keeps_roman_values_and_ur_keeps_script_values(): void
    {
        $rur = require base_path('lang/rur/pos.php');
        $ur = require base_path('lang/ur/pos.php');

        // Roman experience lives in rur (was lang/ur before the split).
        $this->assertSame('Save karein', $rur['save']);
        $this->assertSame('Talash karein...', $rur['search']);

        // ur = Urdu script: picker labels are script; no Roman leftovers on migrated keys.
        $this->assertSame('اردو', $ur['language_urdu_script']);
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $ur['language_saved']);
        // Script-file values must never be the old Roman strings.
        $this->assertNotSame('Save karein', $ur['save']);

        // All three files expose the three picker labels.
        foreach ([require base_path('lang/en/pos.php'), $rur, $ur] as $file) {
            foreach (['language_english', 'language_roman_urdu', 'language_urdu_script'] as $key) {
                $this->assertArrayHasKey($key, $file);
            }
        }
    }
}
