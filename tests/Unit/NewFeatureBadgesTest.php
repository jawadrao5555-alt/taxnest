<?php

namespace Tests\Unit;

use App\Services\NewFeatureBadges;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * "NEW" nishan naye settings par — owner ask, 26 Aug 2026.
 *
 * Yahan asli register ki TAZGI test nahi hoti (woh tareekh ke saath khud
 * purani ho jati hai aur test jhoota ho jata) — yahan qanoon test hote hain:
 * window ka hisab, panel ka farq, page/URL ka milan, aur yeh ke register ki
 * har entry ka route waqai mojood hai (aik typo settings page ko 500 na kar de).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Unit/NewFeatureBadgesTest.php
 */
class NewFeatureBadgesTest extends TestCase
{
    protected function tearDown(): void
    {
        NewFeatureBadges::clearFake();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_badge_lives_only_inside_its_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        NewFeatureBadges::fake([
            'fresh'   => ['since' => '2026-09-01', 'panel' => 'pos', 'pages' => ['pos.customize']],
            'aging'   => ['since' => '2026-08-20', 'panel' => 'pos', 'pages' => ['pos.customize']],
            'expired' => ['since' => '2026-07-01', 'panel' => 'pos', 'pages' => ['pos.customize']],
            'future'  => ['since' => '2026-09-10', 'panel' => 'pos', 'pages' => ['pos.customize']],
        ]);

        $this->assertTrue(NewFeatureBadges::isNew('fresh'), 'aaj ship hui cheez nayi honi chahiye');
        $this->assertTrue(NewFeatureBadges::isNew('aging'), '12 din purani abhi window ke andar hai');
        $this->assertFalse(NewFeatureBadges::isNew('expired'), '21 din baad nishan khud gayab');
        $this->assertFalse(NewFeatureBadges::isNew('future'), 'aane wali tareekh par nishan nahi');
        $this->assertFalse(NewFeatureBadges::isNew('koi_aisi_key_nahi'));
        $this->assertFalse(NewFeatureBadges::isNew(null));
    }

    public function test_window_closes_exactly_after_the_given_days(): void
    {
        NewFeatureBadges::fake([
            'short' => ['since' => '2026-09-01', 'days' => 3, 'panel' => 'pos', 'pages' => ['pos.customize']],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-03 23:59:00'));
        $this->assertTrue(NewFeatureBadges::isNew('short'));

        Carbon::setTestNow(Carbon::parse('2026-09-04 00:01:00'));
        $this->assertFalse(NewFeatureBadges::isNew('short'));
    }

    public function test_panel_decides_whose_nav_lights_up(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        NewFeatureBadges::fake([
            'sirf_fbr' => ['since' => '2026-09-01', 'panel' => 'fbrpos', 'pages' => ['fbrpos.customize']],
        ]);
        $this->assertTrue(NewFeatureBadges::panelHasNew('fbrpos'));
        $this->assertFalse(NewFeatureBadges::panelHasNew('pos'), 'FBR ki cheez PRA nav par nahi chamakni chahiye');

        NewFeatureBadges::fake([
            'dono' => ['since' => '2026-09-01', 'panel' => 'all', 'pages' => ['pos.customize']],
        ]);
        $this->assertTrue(NewFeatureBadges::panelHasNew('pos'));
        $this->assertTrue(NewFeatureBadges::panelHasNew('fbrpos'));

        NewFeatureBadges::fake([
            'purana' => ['since' => '2026-07-01', 'panel' => 'pos', 'pages' => ['pos.customize']],
        ]);
        $this->assertFalse(NewFeatureBadges::panelHasNew('pos'), 'window band = nav bhi khamosh');
        $this->assertFalse(NewFeatureBadges::panelHasNew(null));
    }

    public function test_page_and_url_both_find_the_same_setting(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        NewFeatureBadges::fake([
            'kot' => ['since' => '2026-09-01', 'panel' => 'pos', 'pages' => ['pos.restaurant.kitchen-settings']],
        ]);

        $this->assertTrue(NewFeatureBadges::pageHasNew('pos.restaurant.kitchen-settings'));
        $this->assertFalse(NewFeatureBadges::pageHasNew('pos.receipt-settings'));
        $this->assertFalse(NewFeatureBadges::pageHasNew(null));

        // Customize hub ke card sirf URL rakhte hain (route() se bana absolute
        // https URL) — usi se page pehchana jaye, query/#anchor ke bawajood.
        $this->assertTrue(NewFeatureBadges::urlHasNew(route('pos.restaurant.kitchen-settings')));
        $this->assertTrue(NewFeatureBadges::urlHasNew(route('pos.restaurant.kitchen-settings') . '?x=1#kot'));
        $this->assertTrue(NewFeatureBadges::urlHasNew('/pos/restaurant/kitchen-settings/'));
        $this->assertFalse(NewFeatureBadges::urlHasNew(route('pos.receipt-settings')));
        $this->assertFalse(NewFeatureBadges::urlHasNew(null));
        $this->assertFalse(NewFeatureBadges::urlHasNew(''));
    }

    public function test_a_broken_entry_stays_silent_instead_of_crashing_the_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        NewFeatureBadges::fake([
            'no_date'   => ['panel' => 'pos', 'pages' => ['pos.customize']],
            'bad_date'  => ['since' => 'kal parson', 'panel' => 'pos', 'pages' => ['pos.customize']],
            'zero_days' => ['since' => '2026-09-01', 'days' => 0, 'panel' => 'pos', 'pages' => ['pos.customize']],
            'no_route'  => ['since' => '2026-09-01', 'panel' => 'pos', 'pages' => ['pos.aisa.koi.route.nahi']],
        ]);

        $this->assertFalse(NewFeatureBadges::isNew('no_date'));
        $this->assertFalse(NewFeatureBadges::isNew('bad_date'));
        $this->assertFalse(NewFeatureBadges::isNew('zero_days'));
        // Route mojood na ho to bhi sirf khamoshi — koi exception nahi.
        $this->assertFalse(NewFeatureBadges::urlHasNew('/pos/customize'));
        $this->assertTrue(NewFeatureBadges::isNew('no_route'), 'key par nishan phir bhi chalta hai');
    }

    public function test_a_page_needing_a_parameter_cannot_take_the_hub_down(): void
    {
        // Ghalti se aisa route register ho jaye jo parameter maangta hai, to
        // route() exception phenkta hai — Customize hub phir bhi khulna chahiye.
        Route::get('/tn-test/badge-probe/{id}', fn () => '')->name('tn.badge.probe');
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        NewFeatureBadges::fake([
            'param_wala' => ['since' => '2026-09-01', 'panel' => 'pos', 'pages' => ['tn.badge.probe']],
        ]);

        $this->assertFalse(NewFeatureBadges::urlHasNew('/pos/customize'));
        $this->assertFalse(NewFeatureBadges::urlHasNew('/tn-test/badge-probe/1'));
        $this->assertSame('', trim(Blade::render('<x-new-badge url="/pos/customize" panel="pos" />')));
    }

    public function test_shows_needs_at_least_one_hint(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        NewFeatureBadges::fake([
            'kuch' => ['since' => '2026-09-01', 'panel' => 'pos', 'pages' => ['pos.customize']],
        ]);

        $this->assertFalse(NewFeatureBadges::shows(), 'bina kisi hint ke nishan nahi');
        $this->assertTrue(NewFeatureBadges::shows('kuch'));
        $this->assertTrue(NewFeatureBadges::shows(null, 'pos.customize'));
        $this->assertTrue(NewFeatureBadges::shows(null, null, route('pos.customize')));
        $this->assertTrue(NewFeatureBadges::shows(null, null, null, 'pos'));
        $this->assertFalse(NewFeatureBadges::shows(null, null, null, 'fbrpos'));
    }

    public function test_component_prints_the_badge_only_when_the_feature_is_new(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        NewFeatureBadges::fake([
            'naya'   => ['since' => '2026-09-01', 'panel' => 'pos', 'pages' => ['pos.customize']],
            'purana' => ['since' => '2026-06-01', 'panel' => 'pos', 'pages' => ['pos.customize']],
        ]);

        $on = Blade::render('<x-new-badge feature="naya" />');
        $this->assertStringContainsString(__('pos.new_badge'), $on);

        $off = Blade::render('<x-new-badge feature="purana" />');
        $this->assertSame('', trim($off), 'window band ho to markup bhi na nikle');

        // Nav ka nuqta: sirf gol nishan, koi lafz nahi (title ke ilawa).
        $dot = Blade::render('<x-new-badge panel="pos" dot />');
        $this->assertStringContainsString('rounded-full', $dot);
        $this->assertStringNotContainsString('>' . __('pos.new_badge') . '<', $dot);
    }

    public function test_settings_navigation_is_scoped_to_its_destination_and_stock_check_marks_its_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00'));
        NewFeatureBadges::fake([
            'unrelated' => ['since' => '2026-09-03', 'panel' => 'pos', 'pages' => ['pos.receipt-settings']],
        ]);

        $this->assertSame(
            '',
            trim(Blade::render('<x-new-badge page="pos.customize" panel="pos" />')),
            'An unrelated POS update must not make Customize look new'
        );

        $posLayout = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));
        $fbrLayout = file_get_contents(resource_path('views/layouts/fbr-pos-app.blade.php'));
        $stockCheck = file_get_contents(resource_path('views/pos/inventory/stock-check/index.blade.php'));

        $this->assertSame(3, substr_count($posLayout, 'page="pos.customize" panel="pos"'));
        $this->assertStringContainsString('page="fbrpos.customize" panel="fbrpos"', $fbrLayout);
        $this->assertStringContainsString('<x-new-badge feature="stock_check"', $stockCheck);
    }

    public function test_every_registered_entry_points_at_a_real_page(): void
    {
        NewFeatureBadges::clearFake();

        foreach (NewFeatureBadges::registry() as $key => $entry) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                (string) ($entry['since'] ?? ''),
                "[$key] 'since' YYYY-MM-DD honi chahiye"
            );
            $this->assertContains(
                (string) ($entry['panel'] ?? ''),
                ['pos', 'fbrpos', 'all'],
                "[$key] panel pos|fbrpos|all mein se ho"
            );
            $pages = (array) ($entry['pages'] ?? []);
            $this->assertNotEmpty($pages, "[$key] kam az kam aik page chahiye");
            foreach ($pages as $routeName) {
                $this->assertTrue(
                    Route::has($routeName),
                    "[$key] route '$routeName' mojood nahi — hub card aur nav ka nishan chup rahega"
                );
            }
        }
    }
}
