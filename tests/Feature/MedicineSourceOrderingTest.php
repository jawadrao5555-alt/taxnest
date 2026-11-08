<?php

namespace Tests\Feature;

use App\Models\MedicineCatalogueEntry;
use App\Services\Pharmacy\MedicineCatalogueSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MedicineSourceOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_older_effective_source_cannot_replace_newer_price(): void
    {
        $svc = app(MedicineCatalogueSyncService::class);
        $row = ['brand_name' => 'Synthetic source medicine', 'drap_reg_no' => 'SYNMED001',
            'manufacturer' => 'Synthetic maker', 'pack_size' => '10 tablets', 'mrp' => 250, 'effective_date' => '2026-11-01'];
        $entry = $svc->upsertRow($row)['entry'];
        $before = $entry->getAttributes();
        $svc->upsertRow(array_replace($row, ['mrp' => 180, 'effective_date' => '2026-10-01']));
        $entry->refresh();
        $this->assertEquals(250, $entry->mrp);
        $this->assertSame('2026-11-01', $entry->effective_date->toDateString());
        $this->assertSame(1, $entry->prices()->count());
    }

    public function test_invalid_price_and_date_do_not_create_catalogue_rows(): void
    {
        $svc = app(MedicineCatalogueSyncService::class);
        $base = ['brand_name' => 'Synthetic invalid medicine', 'drap_reg_no' => 'SYNMEDINVALID', 'mrp' => 100];
        $svc->ingestPage([array_replace($base, ['mrp' => -4]), array_replace($base, ['mrp' => 'unknown']), array_replace($base, ['mrp' => '0.001']),
            array_replace($base, ['effective_date' => '2026-02-31'])]);
        $this->assertSame(0, MedicineCatalogueEntry::count());
        $this->assertSame(4, DB::table('medicine_source_observations')->where('disposition', 'invalid')->count());
    }

    public function test_future_prices_wait_until_due_and_conflicting_same_date_sources_require_review(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-08 12:00:00', 'Asia/Karachi'));
        $svc = app(MedicineCatalogueSyncService::class);
        $base = ['brand_name' => 'Synthetic future medicine', 'drap_reg_no' => 'SYNMEDFUTURE',
            'manufacturer' => 'Synthetic maker', 'pack_size' => '10 tablets', 'mrp' => 100, 'effective_date' => '2026-11-01'];
        $entry = $svc->upsertRow($base)['entry'];
        $svc->upsertRow(array_replace($base, ['mrp' => 140, 'effective_date' => '2026-11-10']));
        $this->assertEquals(100, $entry->fresh()->mrp);
        $this->assertSame(1, DB::table('medicine_source_observations')->where('disposition', 'pending')->count());
        $this->travelTo(\Carbon\Carbon::parse('2026-11-10 12:00:00', 'Asia/Karachi'));
        $this->assertSame(1, \App\Services\Pharmacy\MedicineSourceReview::applyDue($svc));
        $this->assertEquals(140, $entry->fresh()->mrp);
        $this->assertSame(0, \App\Services\Pharmacy\MedicineSourceReview::applyDue($svc));
        $svc->upsertRow(array_replace($base, ['mrp' => 160, 'effective_date' => '2026-11-12']));
        $svc->upsertRow(array_replace($base, ['mrp' => 170, 'effective_date' => '2026-11-12']));
        $this->travelTo(\Carbon\Carbon::parse('2026-11-12 12:00:00', 'Asia/Karachi'));
        $this->assertSame(0, \App\Services\Pharmacy\MedicineSourceReview::applyDue($svc));
        $this->assertEquals(140, $entry->fresh()->mrp);
        $this->assertSame(2, DB::table('medicine_source_observations')->where('disposition', 'date_conflict')->count());
    }
}
