<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORDER MATCHING — LOCK vs ROLLOUT CHRONOLOGY — Task 662.
 *
 * Applies the real migrations in the exact chronological (filename) order a
 * fresh / rebuilt database runs them. Aligned with the Task 644 review-fix
 * generation of the rollout (only genuinely UNSET/NULL rows flip to 'code';
 * the mis-timestamped 08_13 Frost revert is neutralized and re-applied as
 * 08_23_100000 AFTER the rollout):
 *   1. 08_13 Frost & Brew token restore (neutralized no-op)
 *   2. 08_23_000000 'code' default rollout (flips only NULL rows)
 *   3. 08_23_100000 Frost & Brew re-restore → 'token' (post-rollout)
 *   4. 08_24_000000 add order_match_style_locked
 *   5. 08_24_000001 lock backfill (locks Frost & Brew's token choice)
 *
 * End state must be: Frost & Brew (id 26) on 'token' AND locked, every
 * unset company on 'code'. Also proves a locked row survives a re-run of
 * the bulk rollout (the whole point of Task 662).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/OrderMatchLockChronologyTest.php --testdox
 */
class OrderMatchLockChronologyTest extends TestCase
{
    private const MIGRATIONS_IN_ORDER = [
        'database/migrations/2026_08_13_000000_frost_and_brew_order_match_token.php',
        'database/migrations/2026_08_23_000000_order_match_code_default_rollout.php',
        'database/migrations/2026_08_23_100000_frost_and_brew_order_match_token_after_rollout.php',
        'database/migrations/2026_08_24_000000_add_order_match_style_locked.php',
        'database/migrations/2026_08_24_000001_lock_manually_set_order_match_styles.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // Companies table shaped like the original 06 Aug migration
        // (pre-rollout: order_match_style default 'off', NULLABLE so the
        // genuinely-unset case exists; no locked column — the 08_24
        // migration under test adds it).
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('order_match_style', 10)->nullable()->default('off');
        });
    }

    private function runMigration(string $path): void
    {
        $migration = require base_path($path);
        $migration->up();
    }

    public function test_fresh_database_chronology_ends_with_frost_and_brew_token_and_locked(): void
    {
        // id 26 must really be 26 — pad the table up to it. All rows are
        // genuinely UNSET (NULL): the corrected rollout flips only these.
        for ($i = 1; $i <= 25; $i++) {
            DB::table('companies')->insert(['id' => $i, 'name' => "Shop {$i}", 'order_match_style' => null]);
        }
        DB::table('companies')->insert(['id' => 26, 'name' => 'Frost and Brew', 'order_match_style' => null]);

        foreach (self::MIGRATIONS_IN_ORDER as $path) {
            $this->runMigration($path);
        }

        $frost = DB::table('companies')->find(26);
        $this->assertSame('token', $frost->order_match_style, 'Frost & Brew must end on token after the full chronology');
        $this->assertEquals(1, $frost->order_match_style_locked, 'Frost & Brew must end locked');

        // Everyone else landed on 'code' and unlocked.
        $others = DB::table('companies')->where('id', '!=', 26)->get();
        foreach ($others as $row) {
            $this->assertSame('code', $row->order_match_style, "company {$row->id} must be on code");
            $this->assertEquals(0, $row->order_match_style_locked, "company {$row->id} must stay unlocked");
        }
    }

    public function test_locked_row_survives_a_rerun_of_the_bulk_rollout(): void
    {
        DB::table('companies')->insert(['id' => 26, 'name' => 'Frost and Brew', 'order_match_style' => null]);

        foreach (self::MIGRATIONS_IN_ORDER as $path) {
            $this->runMigration($path);
        }

        // A future bulk rollout re-running the Aug-23 UPDATE must now skip
        // the locked row instead of flipping it back to 'code'.
        $this->runMigration(self::MIGRATIONS_IN_ORDER[1]);

        $frost = DB::table('companies')->find(26);
        $this->assertSame('token', $frost->order_match_style, 'locked choice must survive a rollout re-run');
        $this->assertEquals(1, $frost->order_match_style_locked);
    }
}
