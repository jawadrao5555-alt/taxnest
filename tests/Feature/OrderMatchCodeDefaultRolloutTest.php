<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORDER MATCHING — CODE DEFAULT ROLLOUT GUARD — Task 652, corrected by
 * Task 644 review / Task 662 (Aug 2026).
 *
 * Owner decision (Aug 2026): Pizza Master's Code style is the standard
 * DEFAULT. The original rollout flipped ALL non-'code' rows; live already ran
 * that version (then Frost & Brew was reverted to 'token' by the 13 Aug
 * migration), so live state is correct. But a rollout migration must NEVER
 * rewrite an explicit per-company choice — in a fresh migration sequence the
 * (earlier-dated) Frost & Brew token migration runs FIRST and the old
 * flip-all rollout clobbered it back to 'code'. This locks the corrected
 * promises:
 *   • only genuinely UNSET (NULL) rows are flipped to 'code';
 *   • explicit 'token' and 'off' selections are preserved verbatim;
 *   • the column DEFAULT becomes 'code', so a brand-new companies row that
 *     doesn't specify a style starts on 'code' with no manual step;
 *   • full ordering case: Frost & Brew (id 26) keeps 'token' when the token
 *     migration is followed by the rollout, as happens in a fresh sequence.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/OrderMatchCodeDefaultRolloutTest.php --testdox
 */
class OrderMatchCodeDefaultRolloutTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_08_23_000000_order_match_code_default_rollout.php';
    private const FROST_MIGRATION = 'database/migrations/2026_08_13_000000_frost_and_brew_order_match_token.php';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // Minimal companies table shaped like the original 06 Aug migration:
        // order_match_style default 'off', nullable so the unset case exists.
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

    public function test_only_unset_rows_flip_and_explicit_choices_survive(): void
    {
        DB::table('companies')->insert([
            ['name' => 'Off Shop',   'order_match_style' => 'off'],
            ['name' => 'Token Shop', 'order_match_style' => 'token'],
            ['name' => 'Code Shop',  'order_match_style' => 'code'],
            ['name' => 'Unset Shop', 'order_match_style' => null],
        ]);

        $this->runMigration(self::MIGRATION);

        $styles = DB::table('companies')->orderBy('id')->pluck('order_match_style')->all();
        // Explicit off/token/code untouched; only the NULL row flips.
        $this->assertSame(['off', 'token', 'code', 'code'], $styles);

        // NEW company created after the migration, without specifying a style,
        // must land on 'code' purely from the column default.
        DB::table('companies')->insert(['name' => 'Brand New Shop']);
        $this->assertSame(
            'code',
            DB::table('companies')->where('name', 'Brand New Shop')->value('order_match_style')
        );
    }

    public function test_frost_and_brew_token_survives_fresh_sequence_ordering(): void
    {
        // Fresh-sequence ordering: the 13 Aug Frost & Brew migration runs
        // BEFORE the 23 Aug rollout. Its 'token' choice must survive.
        DB::table('companies')->insert([
            'id' => 26,
            'name' => 'Frost and Brew',
            'order_match_style' => 'code',
        ]);

        $this->runMigration(self::FROST_MIGRATION); // sets id 26 → token
        $this->runMigration(self::MIGRATION);       // rollout must NOT clobber it

        $this->assertSame(
            'token',
            DB::table('companies')->where('id', 26)->value('order_match_style')
        );
    }

    public function test_migration_is_idempotent_and_never_rewrites_choices(): void
    {
        DB::table('companies')->insert([
            ['name' => 'Twice Token Shop', 'order_match_style' => 'token'],
            ['name' => 'Twice Unset Shop', 'order_match_style' => null],
        ]);

        $this->runMigration(self::MIGRATION);
        $this->runMigration(self::MIGRATION); // re-run must not throw or change outcome

        $this->assertSame(
            'token',
            DB::table('companies')->where('name', 'Twice Token Shop')->value('order_match_style')
        );
        $this->assertSame(
            'code',
            DB::table('companies')->where('name', 'Twice Unset Shop')->value('order_match_style')
        );
    }
}
