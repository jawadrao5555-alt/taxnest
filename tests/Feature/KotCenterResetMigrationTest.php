<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 811 — the Task 761 "reset accidental KOT centering" migration must
 * NEVER touch fbrpos companies: for them kot_align_center is the RECEIPT
 * print position (Task 718, receipt-kot-margin-split), a deliberate owner
 * choice made on the FBR business-profile page — the pre-757 Kitchen
 * Settings ghost-save it rolls back cannot happen on fbrpos (no Kitchen
 * Settings page there).
 *
 * Executes the real migration file against both pos and fbrpos rows on the
 * sqlite test schema and proves the fbrpos true survives while the pos
 * ghost-save is reset.
 */
class KotCenterResetMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('companies');
        Schema::create('companies', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->boolean('kot_align_center')->nullable()->default(null);
            $table->boolean('kot_compact')->nullable()->default(false);
            $table->unsignedTinyInteger('kot_left_margin_mm')->nullable()->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function runResetMigration(): void
    {
        $migration = require base_path('database/migrations/2026_08_28_000000_reset_accidental_kot_center.php');
        $migration->up();
    }

    private function insertCompany(array $attrs): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Test Co',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    public function test_fbrpos_centered_receipt_survives_while_pos_ghost_save_is_reset(): void
    {
        // PRA POS ghost-save: true + no compact + no margin → must reset to NULL.
        $posGhost = $this->insertCompany([
            'product_type' => 'pos',
            'kot_align_center' => true,
            'kot_compact' => false,
            'kot_left_margin_mm' => 0,
        ]);

        // fbrpos shop that chose "Center of paper" for RECEIPTS — same column
        // state as the pos ghost, but it must be preserved.
        $fbrCentered = $this->insertCompany([
            'product_type' => 'fbrpos',
            'kot_align_center' => true,
            'kot_compact' => false,
            'kot_left_margin_mm' => 0,
        ]);

        $this->runResetMigration();

        $this->assertNull(
            DB::table('companies')->where('id', $posGhost)->value('kot_align_center'),
            'pos ghost-save true should be reset to NULL'
        );
        $this->assertEquals(
            1,
            DB::table('companies')->where('id', $fbrCentered)->value('kot_align_center'),
            'fbrpos centered receipt position must NEVER be reset'
        );
    }

    public function test_deliberate_pos_layouts_and_legacy_null_product_type_behave_as_designed(): void
    {
        // Deliberate pos layout (compact tweaked) → untouched even though true.
        $posDeliberate = $this->insertCompany([
            'product_type' => 'pos',
            'kot_align_center' => true,
            'kot_compact' => true,
            'kot_left_margin_mm' => 0,
        ]);

        // Margin tweaked → untouched.
        $posMargin = $this->insertCompany([
            'product_type' => 'pos',
            'kot_align_center' => true,
            'kot_compact' => false,
            'kot_left_margin_mm' => 5,
        ]);

        // Legacy row without product_type → still reset (original 761 scope).
        $legacy = $this->insertCompany([
            'product_type' => null,
            'kot_align_center' => true,
            'kot_compact' => false,
            'kot_left_margin_mm' => 0,
        ]);

        // fbrpos explicit LEFT (false) → stays false (reset only matches true).
        $fbrLeft = $this->insertCompany([
            'product_type' => 'fbrpos',
            'kot_align_center' => false,
            'kot_compact' => false,
            'kot_left_margin_mm' => 0,
        ]);

        $this->runResetMigration();

        $this->assertEquals(1, DB::table('companies')->where('id', $posDeliberate)->value('kot_align_center'));
        $this->assertEquals(1, DB::table('companies')->where('id', $posMargin)->value('kot_align_center'));
        $this->assertNull(DB::table('companies')->where('id', $legacy)->value('kot_align_center'));
        $this->assertEquals(0, DB::table('companies')->where('id', $fbrLeft)->value('kot_align_center'));
    }

    public function test_migration_is_idempotent_for_fbrpos(): void
    {
        $fbrCentered = $this->insertCompany([
            'product_type' => 'fbrpos',
            'kot_align_center' => true,
            'kot_compact' => false,
            'kot_left_margin_mm' => 0,
        ]);

        $this->runResetMigration();
        $this->runResetMigration(); // re-run (prod self-heal) must not flip it either

        $this->assertEquals(1, DB::table('companies')->where('id', $fbrCentered)->value('kot_align_center'));
    }
}
