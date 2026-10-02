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
            $table->text('target_categories')->nullable();
            $table->string('audience_family')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->string('deployment_key', 40)->nullable();
            $table->string('notification_key', 64)->nullable()->index();
            $table->string('manual_publish_key', 64)->nullable()->unique();
            $table->uuid('announcement_revision')->nullable();
            $table->timestamp('archived_at')->nullable();
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

    public function test_specific_guest_house_update_is_saved_and_empty_selection_is_rejected(): void
    {
        $payload = [
            'title' => 'Guest house reception update', 'points_text' => 'Room board is ready',
            'audience' => 'pos', 'audience_family' => 'accommodation',
            'audience_scope' => 'cats', 'target_categories' => ['hotel'],
            'is_published' => '1',
        ];

        $this->actingAsAdmin()->post('/admin/app-updates', $payload)->assertRedirect();
        $update = AppUpdate::where('title', $payload['title'])->firstOrFail();
        $this->assertSame(['hotel'], $update->target_categories);
        $this->assertSame('accommodation', $update->audience_family);

        $this->actingAsAdmin()->from('/admin/app-updates')->post('/admin/app-updates',
            array_merge($payload, ['title' => 'Must not broadcast', 'target_categories' => []]))
            ->assertRedirect('/admin/app-updates')->assertSessionHasErrors('target_categories');
        $this->assertDatabaseMissing('app_updates', ['title' => 'Must not broadcast']);

        $this->actingAsAdmin()->from('/admin/app-updates')->post('/admin/app-updates',
            array_merge($payload, ['title' => 'Wrong panel', 'target_categories' => ['grocery']]))
            ->assertSessionHasErrors('target_categories');
        $this->assertDatabaseMissing('app_updates', ['title' => 'Wrong panel']);
    }

    public function test_universal_notice_requires_explicit_scope(): void
    {
        $payload = [
            'title' => 'Universal announcement', 'points_text' => 'General notice',
            'audience' => 'all', 'audience_family' => 'all',
        ];
        $this->actingAsAdmin()->from('/admin/app-updates')->post('/admin/app-updates', $payload)
            ->assertSessionHasErrors('audience_scope');
        $this->assertDatabaseMissing('app_updates', ['title' => $payload['title']]);

        $this->actingAsAdmin()->post('/admin/app-updates', $payload + ['audience_scope' => 'all'])
            ->assertRedirect();
        $this->assertNull(AppUpdate::where('title', $payload['title'])->firstOrFail()->target_categories);
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

    public function test_repeated_admin_submission_creates_one_announcement(): void
    {
        $payload = [
            'title' => 'Stable reception update', 'points_text' => 'Room board is easier',
            'audience' => 'pos', 'audience_family' => 'accommodation',
            'audience_scope' => 'cats', 'target_categories' => ['hotel'],
            'is_published' => '1',
        ];
        $this->actingAsAdmin()->post('/admin/app-updates', $payload)->assertRedirect();
        $original = AppUpdate::where('title', $payload['title'])->firstOrFail();
        \App\Models\AppUpdateSeen::create(['app_update_id' => $original->id, 'user_id' => 123]);
        $this->post('/admin/app-updates', $payload)->assertRedirect();
        $this->assertSame(1, AppUpdate::where('title', $payload['title'])->count());
        $this->assertDatabaseHas('app_update_seens', ['app_update_id' => $original->id, 'user_id' => 123]);
    }

    public function test_new_deployment_receipt_does_not_duplicate_delivery_or_reset_seen_state(): void
    {
        $data = ['title' => 'Stable reception update', 'points' => ['Room board is easier'],
            'audience' => 'pos', 'is_published' => true, 'is_featured' => true];
        $first = AppUpdate::create($data + ['deployment_key' => str_repeat('a', 40)]);
        $second = AppUpdate::create($data + ['deployment_key' => str_repeat('b', 40)]);
        \App\Models\AppUpdateSeen::create(['app_update_id' => $second->id, 'user_id' => 123]);

        $this->assertSame([$first->id], AppUpdate::customerCanonical()->published()->liveWindow()->pluck('id')->all());
        $this->assertContains($first->id, AppUpdate::seenIdsForUser(123));
        $this->assertFalse($first->isCustomerDuplicate());
        $this->assertTrue($second->isCustomerDuplicate());
        $this->assertSame(2, AppUpdate::whereNotNull('deployment_key')->count(),
            'both exact-SHA release receipts must survive');
        $this->assertSame(1, \App\Models\AppUpdateSeen::count(),
            'deduplication must not rewrite acknowledgement history');

        DB::table('app_updates')->where('id', $first->id)->update(['created_at' => now()->subDays(8)]);
        $this->assertSame(0, AppUpdate::customerCanonical()->published()->liveWindow()->count(),
            'a repeated deploy must not restart the customer announcement window');
        $changed = AppUpdate::create(array_replace($data, ['points' => ['Checkout is easier']]));
        $this->assertSame([$changed->id], AppUpdate::customerCanonical()->published()->liveWindow()->pluck('id')->all());
    }

    public function test_explicit_reannouncement_creates_a_revision_without_erasing_history(): void
    {
        $original = AppUpdate::create([
            'title' => 'Reception reminder', 'points' => ['Room board is easier'],
            'audience' => 'pos', 'is_published' => true,
        ]);
        DB::table('app_updates')->where('id', $original->id)->update(['created_at' => now()->subDays(8)]);
        $created = $original->fresh()->created_at->toDateTimeString();
        \App\Models\AppUpdateSeen::create(['app_update_id' => $original->id, 'user_id' => 123]);
        $this->actingAsAdmin()->post('/admin/app-updates/'.$original->id.'/reannounce')->assertRedirect();
        $revision = AppUpdate::where('id', '!=', $original->id)->firstOrFail();
        $this->assertNotEmpty($revision->announcement_revision);
        $this->assertNotSame($original->notification_key, $revision->notification_key);
        $this->assertSame($created, $original->fresh()->created_at->toDateTimeString());
        $this->assertDatabaseHas('app_update_seens', ['app_update_id' => $original->id, 'user_id' => 123]);
        $this->assertNotContains($revision->id, AppUpdate::seenIdsForUser(123));
        $this->assertSame([$revision->id], AppUpdate::customerCanonical()->published()->liveWindow()->pluck('id')->all());
    }

    public function test_archive_stops_the_whole_duplicate_group_and_keeps_receipts_and_seen_history(): void
    {
        $data = ['title' => 'Archive reminder', 'points' => ['Room board is easier'],
            'audience' => 'pos', 'is_published' => true];
        $first = AppUpdate::create($data + ['deployment_key' => str_repeat('a', 40)]);
        $second = AppUpdate::create($data + ['deployment_key' => str_repeat('b', 40)]);
        \App\Models\AppUpdateSeen::create(['app_update_id' => $first->id, 'user_id' => 123]);
        $this->actingAsAdmin()->delete('/admin/app-updates/'.$second->id.'/delete')->assertRedirect();
        $this->assertSame(2, AppUpdate::whereNotNull('deployment_key')->published()->count());
        $this->assertSame(0, AppUpdate::customerCanonical()->published()->liveWindow()->count());
        $this->assertDatabaseHas('app_update_seens', ['app_update_id' => $first->id, 'user_id' => 123]);
        AppUpdate::create($data + ['deployment_key' => str_repeat('c', 40)]);
        $this->assertSame(0, AppUpdate::customerCanonical()->published()->liveWindow()->count(),
            'later repeated deployment must not resurrect archived content');
        $this->post('/admin/app-updates/'.$first->id.'/reannounce')->assertRedirect();
        $this->assertSame(1, AppUpdate::customerCanonical()->published()->liveWindow()->count(),
            'an explicit new revision may announce archived content again');
    }
}
