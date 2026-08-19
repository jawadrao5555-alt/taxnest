<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AppUpdate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Admin App Updates history — "Live on POS" / "Expired" indicators and
 * type badges (Task 1286 UI, locked by Task 1296).
 *
 * The owner must be able to tell AT A GLANCE which announcements POS users
 * can still see (7-day live window) from /admin/app-updates:
 *
 *   1. A freshly published row shows the green "● Live on POS" chip.
 *   2. An 8-day-old published row shows "Expired (7 din guzar gaye)" —
 *      but is STILL LISTED (admin history is never hidden/filtered).
 *   3. An unpublished (hidden) row shows NEITHER live nor expired label.
 *   4. A legacy row with NULL type renders "Behtari / Masla Hal" without
 *      errors (accessor normalizes null → improvement, no 500).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create in
 * setUp (see WhatsNewAudienceTargetingTest / AdminPagesSmokeTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/AdminAppUpdatesLiveIndicatorTest.php
 */
class AdminAppUpdatesLiveIndicatorTest extends TestCase
{
    // Unique marker titles — safe to locate in full-page HTML.
    private const T_FRESH = 'AULIVE-FRESH-91xa1';
    private const T_EXPIRED = 'AULIVE-EXPIRED-91xa2';
    private const T_HIDDEN = 'AULIVE-HIDDEN-91xa3';
    private const T_LEGACY = 'AULIVE-LEGACY-NULLTYPE-91xa4';

    private const LABEL_LIVE = 'Live on POS';
    private const LABEL_EXPIRED = 'Expired (7 din guzar gaye)';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // The table under test — mirrors the real migration incl. Task 1286
        // nullable type column (legacy rows are NULL).
        Schema::create('app_updates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('points');
            $table->string('image_path')->nullable();
            $table->string('audience')->default('pos');
            $table->string('type', 20)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('app_update_seens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_update_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique(['app_update_id', 'user_id']);
        });

        DB::table('admin_users')->insert([
            'name' => 'AU Admin',
            'email' => 'au-admin@taxnest.test',
            'password' => Hash::make('Smoke@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    private function seedRows(): void
    {
        // Fresh published — inside the 7-day live window.
        AppUpdate::create([
            'title' => self::T_FRESH, 'points' => ['Point one'],
            'audience' => 'pos', 'is_published' => true, 'type' => 'feature',
        ]);

        // 8-day-old published — expired from POS but must stay in history.
        $expired = AppUpdate::create([
            'title' => self::T_EXPIRED, 'points' => ['Point one'],
            'audience' => 'pos', 'is_published' => true, 'type' => 'improvement',
        ]);
        DB::table('app_updates')->where('id', $expired->id)
            ->update(['created_at' => now()->subDays(8)]);

        // Unpublished (hidden) — neither live nor expired label applies.
        AppUpdate::create([
            'title' => self::T_HIDDEN, 'points' => ['Point one'],
            'audience' => 'pos', 'is_published' => false, 'type' => 'feature',
        ]);

        // Legacy pre-Task-1286 row — type intentionally NULL via raw insert.
        DB::table('app_updates')->insert([
            'title' => self::T_LEGACY, 'points' => json_encode(['Point one']),
            'audience' => 'pos', 'is_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Extract the single <tr>…</tr> block containing the given marker title. */
    private function rowHtml(string $html, string $title): string
    {
        $pos = strpos($html, $title);
        $this->assertNotFalse($pos, "Row '{$title}' must be present in the admin history");
        $start = strrpos(substr($html, 0, $pos), '<tr');
        $end = strpos($html, '</tr>', $pos);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        return substr($html, $start, $end - $start);
    }

    public function test_admin_history_shows_live_expired_and_type_indicators_per_row(): void
    {
        $this->seedRows();

        $resp = $this->actingAsAdmin()->get('/admin/app-updates');
        $resp->assertStatus(200);
        $html = $resp->getContent();

        // 1. Fresh published row → Live on POS, no Expired label.
        $fresh = $this->rowHtml($html, self::T_FRESH);
        $this->assertStringContainsString(self::LABEL_LIVE, $fresh, 'Fresh published row must show Live on POS');
        $this->assertStringNotContainsString(self::LABEL_EXPIRED, $fresh);
        $this->assertStringContainsString('Naya Feature', $fresh);

        // 2. 8-day-old published row → still listed, Expired label, no Live.
        $expired = $this->rowHtml($html, self::T_EXPIRED);
        $this->assertStringContainsString(self::LABEL_EXPIRED, $expired, '8-day-old published row must show Expired');
        $this->assertStringNotContainsString(self::LABEL_LIVE, $expired, 'Expired row must not claim Live on POS');
        $this->assertStringContainsString('Behtari / Masla Hal', $expired);

        // 3. Unpublished row → neither live nor expired label.
        $hidden = $this->rowHtml($html, self::T_HIDDEN);
        $this->assertStringContainsString('Hidden', $hidden);
        $this->assertStringNotContainsString(self::LABEL_LIVE, $hidden, 'Unpublished row must not show Live on POS');
        $this->assertStringNotContainsString(self::LABEL_EXPIRED, $hidden, 'Unpublished row must not show Expired');

        // 4. Legacy NULL-type row → renders (no 500 already asserted) with
        //    the improvement badge, never a blank/missing type.
        $legacy = $this->rowHtml($html, self::T_LEGACY);
        $this->assertStringContainsString('Behtari / Masla Hal', $legacy, 'NULL type must normalize to Behtari / Masla Hal');
        $this->assertStringNotContainsString('Naya Feature', $legacy);
        $this->assertStringContainsString(self::LABEL_LIVE, $legacy, 'Fresh legacy published row is still inside the live window');
    }

    public function test_seven_day_old_row_is_still_live_boundary(): void
    {
        // Just inside the window (created_at >= now-7d) → still Live.
        $upd = AppUpdate::create([
            'title' => 'AULIVE-BOUNDARY-91xa5', 'points' => ['Point one'],
            'audience' => 'pos', 'is_published' => true,
        ]);
        DB::table('app_updates')->where('id', $upd->id)
            ->update(['created_at' => now()->subDays(7)->addMinutes(5)]);

        $resp = $this->actingAsAdmin()->get('/admin/app-updates');
        $resp->assertStatus(200);

        $row = $this->rowHtml($resp->getContent(), 'AULIVE-BOUNDARY-91xa5');
        $this->assertStringContainsString(self::LABEL_LIVE, $row, 'Row just inside the 7-day window must still show Live on POS');
        $this->assertStringNotContainsString(self::LABEL_EXPIRED, $row);
    }

    public function test_guest_is_redirected_away_from_admin_history(): void
    {
        $this->get('/admin/app-updates')->assertRedirect();
    }
}
