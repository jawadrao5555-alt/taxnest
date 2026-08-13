<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORDER MATCHING — CODE DEFAULT ROLLOUT GUARD — Task 652.
 *
 * Owner decision (Aug 2026): Pizza Master's Code style is the standard for
 * every company. This locks the rollout migration's two promises:
 *   • existing companies (off / token / NULL) are flipped to 'code' once;
 *   • a company already on 'code' stays untouched (no-op);
 *   • the column DEFAULT becomes 'code', so a brand-new companies row that
 *     doesn't specify a style starts on 'code' with no manual step.
 *
 * The per-company Receipt Settings dropdown stays — the migration runs once,
 * so a later manual choice of token/off is never re-overridden.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/OrderMatchCodeDefaultRolloutTest.php --testdox
 */
class OrderMatchCodeDefaultRolloutTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_08_23_000000_order_match_code_default_rollout.php';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // Minimal companies table shaped like the original 06 Aug migration:
        // order_match_style default 'off'.
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('order_match_style', 10)->default('off');
        });
    }

    private function runRolloutMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    public function test_existing_companies_flip_to_code_and_new_rows_default_to_code(): void
    {
        DB::table('companies')->insert([
            ['name' => 'Off Shop',   'order_match_style' => 'off'],
            ['name' => 'Token Shop', 'order_match_style' => 'token'],
            ['name' => 'Code Shop',  'order_match_style' => 'code'],
        ]);

        $this->runRolloutMigration();

        $styles = DB::table('companies')->orderBy('id')->pluck('order_match_style')->all();
        $this->assertSame(['code', 'code', 'code'], $styles);

        // NEW company created after the migration, without specifying a style,
        // must land on 'code' purely from the column default.
        DB::table('companies')->insert(['name' => 'Brand New Shop']);
        $this->assertSame(
            'code',
            DB::table('companies')->where('name', 'Brand New Shop')->value('order_match_style')
        );
    }

    // Task 662: a company whose owner manually picked a style in Receipt
    // Settings (order_match_style_locked = true) must survive a bulk rollout.
    public function test_locked_companies_keep_their_manual_choice(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('order_match_style_locked')->default(false);
        });

        DB::table('companies')->insert([
            ['name' => 'Frost and Brew', 'order_match_style' => 'token', 'order_match_style_locked' => true],
            ['name' => 'Unset Shop',     'order_match_style' => 'off',   'order_match_style_locked' => false],
        ]);

        $this->runRolloutMigration();

        $this->assertSame(
            'token',
            DB::table('companies')->where('name', 'Frost and Brew')->value('order_match_style')
        );
        $this->assertSame(
            'code',
            DB::table('companies')->where('name', 'Unset Shop')->value('order_match_style')
        );
    }

    public function test_migration_is_idempotent(): void
    {
        DB::table('companies')->insert(['name' => 'Twice Shop', 'order_match_style' => 'token']);

        $this->runRolloutMigration();
        $this->runRolloutMigration(); // re-run must not throw or change outcome

        $this->assertSame(
            'code',
            DB::table('companies')->where('name', 'Twice Shop')->value('order_match_style')
        );
    }
}
