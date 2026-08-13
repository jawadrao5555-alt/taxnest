<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 585: legacy CNIC cleanup migration + duplicate report command.
 *
 *  - dashed/spaced stored CNICs → plain digits (soft-deleted rows too)
 *  - separator-only junk → NULL
 *  - already-clean rows untouched; running twice is a no-op (idempotent)
 *  - duplicates are NEVER auto-nulled — reported by `cnic:duplicates`,
 *    which digit-compares so dashed vs plain forms collide.
 */
class CnicLegacyCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('cnic')->nullable();
            $table->string('product_type')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    private function seedCompany(string $name, ?string $cnic, string $product = 'pos', bool $deleted = false): int
    {
        return DB::table('companies')->insertGetId([
            'name' => $name, 'cnic' => $cnic, 'product_type' => $product,
            'deleted_at' => $deleted ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function runMigration(): void
    {
        $migration = require base_path('database/migrations/2026_08_14_060000_normalize_legacy_cnic_digits.php');
        ob_start();
        $migration->up();
        ob_end_clean();
    }

    public function test_dashed_and_spaced_cnics_normalized_to_plain_digits(): void
    {
        $dashed = $this->seedCompany('Dashed Co', '35202-1234567-1');
        $spaced = $this->seedCompany('Spaced Co', '36202 9876543 2');
        $clean = $this->seedCompany('Clean Co', '3410112345675');
        $junk = $this->seedCompany('Junk Co', '- -');
        $null = $this->seedCompany('Null Co', null);
        $deletedDashed = $this->seedCompany('Deleted Co', '31201-0000000-9', 'fbrpos', true);

        $this->runMigration();

        $this->assertSame('3520212345671', DB::table('companies')->where('id', $dashed)->value('cnic'));
        $this->assertSame('3620298765432', DB::table('companies')->where('id', $spaced)->value('cnic'));
        $this->assertSame('3410112345675', DB::table('companies')->where('id', $clean)->value('cnic'));
        $this->assertNull(DB::table('companies')->where('id', $junk)->value('cnic'), 'separator-only junk must become NULL');
        $this->assertNull(DB::table('companies')->where('id', $null)->value('cnic'));
        $this->assertSame('3120100000009', DB::table('companies')->where('id', $deletedDashed)->value('cnic'), 'soft-deleted rows normalized too');

        // Idempotent: second run changes nothing.
        $before = DB::table('companies')->orderBy('id')->pluck('cnic')->all();
        $this->runMigration();
        $this->assertSame($before, DB::table('companies')->orderBy('id')->pluck('cnic')->all());
    }

    public function test_duplicates_are_reported_not_nulled(): void
    {
        // Legacy dupe pair: one dashed-stored, one plain — digit-forms collide.
        $a = $this->seedCompany('First Dupe', '35202-1234567-1');
        $b = $this->seedCompany('Second Dupe', '3520212345671', 'fbrpos');
        $this->seedCompany('Unique Co', '3410112345675');

        $this->runMigration();

        // BOTH keep their CNIC — never auto-nulled.
        $this->assertSame('3520212345671', DB::table('companies')->where('id', $a)->value('cnic'));
        $this->assertSame('3520212345671', DB::table('companies')->where('id', $b)->value('cnic'));

        $this->assertSame(0, Artisan::call('cnic:duplicates'));
        $out = Artisan::output();
        $this->assertStringContainsString('duplicate CNIC group(s) found', $out);
        $this->assertStringContainsString('3520212345671', $out);
        $this->assertStringContainsString('First Dupe', $out);
        $this->assertStringContainsString('Second Dupe', $out);
    }

    public function test_duplicates_command_clean_db(): void
    {
        $this->seedCompany('Only Co', '3520212345671');

        $this->assertSame(0, Artisan::call('cnic:duplicates'));
        $this->assertStringContainsString('No duplicate CNICs', Artisan::output());
    }

    /** Dashed-stored dupe is caught by the command even BEFORE normalization ran. */
    public function test_command_digit_compares_without_migration(): void
    {
        $this->seedCompany('Dashed Store', '35202-1234567-1');
        $this->seedCompany('Plain Store', '3520212345671', 'fbrpos');

        $this->assertSame(0, Artisan::call('cnic:duplicates'));
        $this->assertStringContainsString('3520212345671', Artisan::output());
    }

    /** Soft-deleted companies excluded by default, included with --with-deleted. */
    public function test_deleted_companies_flag(): void
    {
        $this->seedCompany('Live Co', '3520212345671');
        $this->seedCompany('Dead Co', '3520212345671', 'pos', true);

        $this->assertSame(0, Artisan::call('cnic:duplicates'));
        $this->assertStringContainsString('No duplicate CNICs', Artisan::output());

        $this->assertSame(0, Artisan::call('cnic:duplicates', ['--with-deleted' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('3520212345671', $out);
        $this->assertStringContainsString('(deleted)', $out);
    }
}
