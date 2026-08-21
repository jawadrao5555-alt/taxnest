<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1392 — the repair migration for Local receipt sets that a STALE Receipt
 * Settings form wiped (Task 1377 fixed the cause; this restores the damage).
 *
 * Runs the real migration file against the sqlite test schema and proves:
 *  - the all-false "no lp_* fields were posted" block is dropped, so
 *    Company::posReceiptPrefs('local') mirrors the PRA set again;
 *  - a shop that deliberately switched individual Local options off keeps
 *    every one of them;
 *  - a shop that hides everything on BOTH bill types is left alone;
 *  - re-running (PROD `migrate --force`) changes nothing.
 */
class PosLocalPrefsWipeRepairMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('companies');
        Schema::create('companies', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->json('invoice_display_prefs')->nullable();
            $table->boolean('pos_receipt_show_tax')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function runRepairMigration(): void
    {
        $migration = require base_path('database/migrations/2026_09_07_000000_repair_wiped_pos_local_receipt_prefs.php');
        $migration->up();
    }

    private function insertCompany(?array $prefs, bool $showTax = true, string $name = 'Test Shop'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name,
            'product_type' => 'pos',
            'invoice_display_prefs' => $prefs === null ? null : json_encode($prefs),
            'pos_receipt_show_tax' => $showTax,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storedPrefs(int $id): ?array
    {
        $raw = DB::table('companies')->where('id', $id)->value('invoice_display_prefs');

        return $raw === null ? null : json_decode((string) $raw, true);
    }

    /** The full display set a stale form wrote: every key false, no footer text. */
    private function wipedLocalBlock(): array
    {
        return [
            'show_address' => false,
            'show_ntn' => false,
            'show_email' => false,
            'show_mobile' => false,
            'show_cashier' => false,
            'show_footer' => false,
            'show_business_name' => false,
            'show_developed_by' => false,
            'show_tax' => false,
            'footer_text' => null,
        ];
    }

    /** A healthy, customized PRA set (ZFC PIZZA POINT's shape on live). */
    private function healthyPraBlock(): array
    {
        return [
            'show_address' => true,
            'show_ntn' => true,
            'show_email' => true,
            'show_mobile' => true,
            'show_cashier' => true,
            'show_footer' => true,
            'show_business_name' => true,
            'show_developed_by' => true,
            'footer_text' => null,
            'show_verify_line' => false,
        ];
    }

    public function test_wiped_local_block_is_dropped_and_mirrors_the_pra_set_again(): void
    {
        $id = $this->insertCompany([
            'pos' => $this->healthyPraBlock(),
            'pos_local' => $this->wipedLocalBlock(),
            'pos_style' => ['bold' => true, 'logo' => 'center'],
        ], true, 'ZFC-shaped shop');

        $this->runRepairMigration();

        $prefs = $this->storedPrefs($id);
        $this->assertArrayNotHasKey('pos_local', $prefs, 'the wiped Local block must be removed');
        $this->assertSame($this->healthyPraBlock(), $prefs['pos'], 'the PRA set must be untouched');
        $this->assertSame(['bold' => true, 'logo' => 'center'], $prefs['pos_style'], 'other pref blocks must be untouched');

        // The whole point: the local bill prints its tax line (and address,
        // cashier, footer) again, mirroring the PRA set exactly as it does for a
        // shop that never opened the Local tab.
        $local = Company::find($id)->posReceiptPrefs('local');
        $this->assertTrue($local['show_tax']);
        $this->assertTrue($local['show_address']);
        $this->assertTrue($local['show_cashier']);
        $this->assertTrue($local['show_footer']);
        $this->assertEquals(Company::find($id)->posReceiptPrefs('pra'), $local);
    }

    public function test_deliberately_customized_local_block_is_left_alone(): void
    {
        // PIZZA MASTER's live shape: NTN/email off on the local bill, tax off to
        // match its PRA column — a real save from the Local tab.
        $local = [
            'show_address' => true,
            'show_ntn' => false,
            'show_email' => false,
            'show_mobile' => true,
            'show_cashier' => true,
            'show_footer' => true,
            'show_business_name' => true,
            'show_developed_by' => true,
            'show_tax' => false,
            'footer_text' => null,
        ];
        $id = $this->insertCompany([
            'pos' => $this->healthyPraBlock(),
            'pos_local' => $local,
        ], false, 'Deliberate shop');

        $this->runRepairMigration();

        $this->assertSame($local, $this->storedPrefs($id)['pos_local']);
        $this->assertFalse(Company::find($id)->posReceiptPrefs('local')['show_tax']);
    }

    public function test_shop_that_hides_everything_on_both_bill_types_is_left_alone(): void
    {
        // Dev "NestPOS Enterprise Store" shape: BOTH sets all-false. Nothing here
        // says "wipe" — mirroring would change nothing and guessing is not allowed.
        $allFalsePra = [
            'show_address' => false,
            'show_ntn' => false,
            'show_email' => false,
            'show_mobile' => false,
            'show_cashier' => false,
            'show_footer' => false,
            'show_business_name' => false,
            'show_developed_by' => false,
            'footer_text' => null,
        ];
        $id = $this->insertCompany([
            'pos' => $allFalsePra,
            'pos_local' => $this->wipedLocalBlock(),
        ], false, 'Hides everything');

        $this->runRepairMigration();

        $this->assertSame($this->wipedLocalBlock(), $this->storedPrefs($id)['pos_local']);
    }

    public function test_all_false_block_with_a_footer_text_is_left_alone(): void
    {
        // A stale form carries no lp_footer_text, so stored text = a real save.
        $local = array_merge($this->wipedLocalBlock(), ['footer_text' => 'Thank you, come again']);
        $id = $this->insertCompany([
            'pos' => $this->healthyPraBlock(),
            'pos_local' => $local,
        ], true, 'Footer-only shop');

        $this->runRepairMigration();

        $this->assertSame($local, $this->storedPrefs($id)['pos_local']);
    }

    public function test_companies_without_a_local_block_are_untouched(): void
    {
        $mirroring = $this->insertCompany(['pos' => $this->healthyPraBlock()], true, 'Never customized');
        $empty = $this->insertCompany(null, true, 'No prefs at all');

        $this->runRepairMigration();

        $this->assertSame(['pos' => $this->healthyPraBlock()], $this->storedPrefs($mirroring));
        $this->assertNull($this->storedPrefs($empty));
    }

    public function test_wiped_block_is_repaired_even_when_the_pra_block_was_never_customized(): void
    {
        // No 'pos' key = PRA defaults (everything on) — still clearly wipe damage.
        $id = $this->insertCompany(['pos_local' => $this->wipedLocalBlock()], true, 'Defaults shop');

        $this->runRepairMigration();

        $this->assertArrayNotHasKey('pos_local', $this->storedPrefs($id));
        $this->assertTrue(Company::find($id)->posReceiptPrefs('local')['show_tax']);
    }

    public function test_migration_is_idempotent(): void
    {
        $wiped = $this->insertCompany([
            'pos' => $this->healthyPraBlock(),
            'pos_local' => $this->wipedLocalBlock(),
        ], true, 'Wiped shop');
        $deliberate = $this->insertCompany([
            'pos' => $this->healthyPraBlock(),
            'pos_local' => array_merge($this->wipedLocalBlock(), ['show_address' => true]),
        ], true, 'Deliberate shop');

        $this->runRepairMigration();
        $after = $this->storedPrefs($wiped);
        $this->runRepairMigration(); // PROD re-runs migrate --force after any deploy

        $this->assertSame($after, $this->storedPrefs($wiped));
        $this->assertArrayNotHasKey('pos_local', $this->storedPrefs($wiped));
        $this->assertSame(
            array_merge($this->wipedLocalBlock(), ['show_address' => true]),
            $this->storedPrefs($deliberate)['pos_local']
        );
    }
}
