<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Services\FbrPosInvoiceSeries;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FbrPosDurableInvoiceSeriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', fn (Blueprint $t) => $t->id());
        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('invoice_number');
        });
        DB::table('companies')->insert([['id' => 91], ['id' => 92]]);
        $this->travelTo(now()->setDate(2026, 10, 12));
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_12_100001_fbr_pos_durable_invoice_sequences.php'))->up();
    }

    private function issue(bool $local = false, int $company = 91): string
    {
        return (new \ReflectionMethod(FbrPosController::class, $local ? 'generateLocalInvoiceNumber' : 'generateInvoiceNumber'))
            ->invoke(new FbrPosController, $company);
    }

    public function test_uses_highest_serial_not_last_inserted_row_and_never_reuses_deleted_reference(): void
    {
        DB::table('fbr_pos_transactions')->insert([
            ['company_id' => 91, 'invoice_number' => 'FPOS-2026-00099'],
            ['company_id' => 91, 'invoice_number' => 'FPOS-2026-00002'],
        ]);
        $this->migrate();
        $this->assertSame('FPOS-2026-00100', $this->issue());
        DB::table('fbr_pos_transactions')->delete();
        $this->assertSame('FPOS-2026-00101', $this->issue());
    }

    public function test_first_bill_separate_tenants_local_series_returns_and_year_rollover(): void
    {
        $this->migrate();
        $this->assertSame('FPOS-2026-00001', $this->issue());
        $this->assertSame('FPOS-2026-00002', $this->issue());
        $this->assertSame('FPOS-2026-00001', $this->issue(false, 92));
        $this->assertSame('FLOCAL-2026-00001', $this->issue(true));
        $this->assertSame('RET-261012-00001', FbrPosInvoiceSeries::issue(91, 'RET-261012-'));
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertSame('FPOS-2027-00001', $this->issue());
    }

    public function test_migration_reserves_old_local_numbers_and_unique_constraint_is_tenant_scoped(): void
    {
        DB::table('fbr_pos_transactions')->insert(['company_id' => 91, 'invoice_number' => 'FLOCAL-2026-100001']);
        $this->migrate();
        DB::table('fbr_pos_transactions')->delete();
        $this->assertSame('FLOCAL-2026-100002', $this->issue(true));
        DB::table('fbr_pos_transactions')->insert([
            ['company_id' => 91, 'invoice_number' => 'FPOS-2026-00001'],
            ['company_id' => 92, 'invoice_number' => 'FPOS-2026-00001'],
        ]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('fbr_pos_transactions')->insert(['company_id' => 91, 'invoice_number' => 'FPOS-2026-00001']);
    }

    public function test_duplicate_historical_references_stop_migration_without_renumbering(): void
    {
        DB::table('fbr_pos_transactions')->insert([
            ['company_id' => 91, 'invoice_number' => 'FPOS-2026-00001'],
            ['company_id' => 91, 'invoice_number' => 'FPOS-2026-00001'],
        ]);
        try { $this->migrate(); $this->fail('Duplicate references must not be silently rewritten.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('reconciliation', $e->getMessage()); }
        $this->assertSame(2, DB::table('fbr_pos_transactions')->where('invoice_number', 'FPOS-2026-00001')->count());
    }
}
