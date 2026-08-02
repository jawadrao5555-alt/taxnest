<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Android APK "new app available" update banner (Task #219).
 *
 * The Android WebView shell (pos-app/) appends "TaxNestPOSApp/<versionName>"
 * to its user agent. The POS layout compares that version against the
 * SystemSetting 'pos_app_latest_version' and shows a dismissible banner
 * (linking to /download) ONLY when the app's version is older. Invariants:
 *
 *   1. Old app UA (incl. legacy hardcoded "TaxNestPOSApp/1.0") → banner shows.
 *   2. Up-to-date (or newer) app UA → no banner.
 *   3. Ordinary browsers (no app UA suffix) NEVER see the banner.
 *   4. Empty/missing setting → banner off even for old app UAs.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * real HTTP render through layouts/pos-app via /pos/my-profile
 * (mirrors WhatsNewAudienceTargetingTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PosApkUpdateBannerTest.php
 */
class PosApkUpdateBannerTest extends TestCase
{
    // Locale-independent markers: the POS locale defaults to Urdu, so we assert
    // on the Alpine storage keys — each only emitted by its banner markup.
    private const BANNER_MARKER = 'tnApkBannerV';
    private const NUDGE_MARKER = 'tnApkNudgeDismissed';

    private const UA_BROWSER = 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/125.0 Mobile Safari/537.36';
    private const UA_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1';
    private const UA_DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0 Safari/537.36';

    private int $companyId;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('ntn')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->string('pos_theme')->nullable();
            $table->string('pos_dashboard_style')->nullable();
            $table->string('confidential_pin')->nullable();
            $table->string('default_language')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->string('language')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
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
        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('login_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->text('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_active')->default(true);
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        $now = now();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'PRA Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->adminId = DB::table('users')->insertGetId([
            'name' => 'PRA Admin', 'email' => 'apkadmin@apk.test',
            'password' => Hash::make('Secret@12345'), 'company_id' => $this->companyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function renderProfile(string $userAgent)
    {
        return $this->actingAs(User::find($this->adminId), 'pos')
            ->withHeaders(['User-Agent' => $userAgent])
            ->get('/pos/my-profile');
    }

    public function test_old_app_version_sees_banner(): void
    {
        SystemSetting::set('pos_app_latest_version', '1.0.1');

        // Legacy hardcoded UA suffix (pre-fix installs) must be caught too.
        $resp = $this->renderProfile(self::UA_BROWSER . ' TaxNestPOSApp/1.0');

        $resp->assertStatus(200);
        $resp->assertSee(self::BANNER_MARKER);
        $resp->assertSee('/download');
    }

    public function test_up_to_date_app_sees_no_banner(): void
    {
        SystemSetting::set('pos_app_latest_version', '1.0.1');

        $resp = $this->renderProfile(self::UA_BROWSER . ' TaxNestPOSApp/1.0.1');
        $resp->assertStatus(200);
        $resp->assertDontSee(self::BANNER_MARKER);

        // Newer than the setting (e.g. beta install) — also no banner.
        $resp2 = $this->renderProfile(self::UA_BROWSER . ' TaxNestPOSApp/1.1.0');
        $resp2->assertStatus(200);
        $resp2->assertDontSee(self::BANNER_MARKER);
    }

    public function test_plain_browser_never_sees_banner(): void
    {
        SystemSetting::set('pos_app_latest_version', '9.9.9');

        $resp = $this->renderProfile(self::UA_BROWSER);
        $resp->assertStatus(200);
        $resp->assertDontSee(self::BANNER_MARKER);
    }

    public function test_missing_or_empty_setting_disables_banner(): void
    {
        // No setting row at all.
        $resp = $this->renderProfile(self::UA_BROWSER . ' TaxNestPOSApp/1.0');
        $resp->assertStatus(200);
        $resp->assertDontSee(self::BANNER_MARKER);

        // Explicitly empty value.
        SystemSetting::set('pos_app_latest_version', '');
        $resp2 = $this->renderProfile(self::UA_BROWSER . ' TaxNestPOSApp/1.0');
        $resp2->assertStatus(200);
        $resp2->assertDontSee(self::BANNER_MARKER);
    }

    // ------------------------------------------------------------------
    // "Download Android App" soft nudge (Task #228): ordinary ANDROID
    // browsers get a one-time-dismissible nudge; the shell never does
    // (already in the app); iOS / desktop browsers see NOTHING at all.
    // ------------------------------------------------------------------

    public function test_android_browser_sees_download_nudge(): void
    {
        $resp = $this->renderProfile(self::UA_BROWSER);
        $resp->assertStatus(200);
        $resp->assertSee(self::NUDGE_MARKER);
        $resp->assertSee('/download');
    }

    public function test_shell_never_sees_download_nudge(): void
    {
        SystemSetting::set('pos_app_latest_version', '9.9.9');
        $resp = $this->renderProfile(self::UA_BROWSER . ' TaxNestPOSApp/1.0');
        $resp->assertStatus(200);
        $resp->assertDontSee(self::NUDGE_MARKER);
        // (It sees the update banner instead — covered above.)
        $resp->assertSee(self::BANNER_MARKER);
    }

    public function test_ios_and_desktop_browsers_see_nothing(): void
    {
        SystemSetting::set('pos_app_latest_version', '9.9.9');

        foreach ([self::UA_IOS, self::UA_DESKTOP] as $ua) {
            $resp = $this->renderProfile($ua);
            $resp->assertStatus(200);
            $resp->assertDontSee(self::BANNER_MARKER);
            $resp->assertDontSee(self::NUDGE_MARKER);
        }
    }
}
