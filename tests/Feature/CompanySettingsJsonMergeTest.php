<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * companies JSON columns are whole-column writes. Two concurrent requests that
 * each own a different key of the same column used to clobber each other
 * (historical: Local receipt prefs wiped by a PRA receipt save).
 *
 * Company::mergeJsonColumn() re-reads the row under lockForUpdate, so a stale
 * in-memory instance still preserves keys it did not intend to change.
 */
class CompanySettingsJsonMergeTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'Merge Shop',
            'ntn' => 'MERGE-' . uniqid(),
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
            'invoice_display_prefs' => [
                'pos' => ['show_ntn' => true],
                'pos_local' => ['show_ntn' => false],
                'pos_style' => ['bold' => true, 'logo' => 'center'],
            ],
            'pos_printer_settings' => [
                'receipt_printer' => 'Counter-80',
                'kot_printer' => 'Kitchen-58',
                'available_printers' => ['Counter-80', 'Kitchen-58'],
            ],
        ]);
    }

    public function test_stale_instances_writing_different_pref_keys_do_not_clobber_each_other(): void
    {
        $id = $this->company()->id;
        $pra = Company::find($id);
        $local = Company::find($id);

        $pra->mergeJsonColumn('invoice_display_prefs', function (array $current): array {
            $current['pos'] = ['show_ntn' => false, 'footer' => 'PRA'];
            return $current;
        });

        $local->mergeJsonColumn('invoice_display_prefs', function (array $current): array {
            $current['pos_local'] = ['show_ntn' => true, 'footer' => 'LOCAL'];
            return $current;
        });

        $fresh = Company::find($id)->invoice_display_prefs;
        $this->assertSame('PRA', $fresh['pos']['footer'] ?? null);
        $this->assertFalse((bool) ($fresh['pos']['show_ntn'] ?? true));
        $this->assertSame('LOCAL', $fresh['pos_local']['footer'] ?? null);
        $this->assertTrue((bool) ($fresh['pos_local']['show_ntn'] ?? false));
        $this->assertTrue((bool) ($fresh['pos_style']['bold'] ?? false));
        $this->assertSame('center', $fresh['pos_style']['logo'] ?? null);
    }

    public function test_style_save_does_not_erase_pos_or_pos_local(): void
    {
        $company = $this->company();

        $company->mergeJsonColumn('invoice_display_prefs', function (array $current): array {
            $current['pos_style'] = ['bold' => false, 'logo' => 'left'];
            return $current;
        });

        $fresh = $company->fresh()->invoice_display_prefs;
        $this->assertFalse((bool) ($fresh['pos_style']['bold'] ?? true));
        $this->assertSame('left', $fresh['pos_style']['logo'] ?? null);
        $this->assertTrue((bool) ($fresh['pos']['show_ntn'] ?? false));
        $this->assertFalse((bool) ($fresh['pos_local']['show_ntn'] ?? true));
    }

    public function test_agent_available_printers_write_does_not_erase_panel_printers(): void
    {
        $id = $this->company()->id;
        $panel = Company::find($id);
        $agent = Company::find($id);

        $panel->mergeJsonColumn('pos_printer_settings', function (array $current): array {
            $current['receipt_printer'] = 'New-Counter';
            $current['kot_printer'] = 'New-Kitchen';
            return $current;
        });

        $agent->mergeJsonColumn('pos_printer_settings', function (array $current): array {
            $current['available_printers'] = ['New-Counter', 'New-Kitchen', 'Spare'];
            return $current;
        });

        $fresh = Company::find($id)->pos_printer_settings;
        $this->assertSame('New-Counter', $fresh['receipt_printer'] ?? null);
        $this->assertSame('New-Kitchen', $fresh['kot_printer'] ?? null);
        $this->assertSame(['New-Counter', 'New-Kitchen', 'Spare'], $fresh['available_printers'] ?? null);
    }

    public function test_liveops_rebind_does_not_erase_available_printers(): void
    {
        $company = $this->company();

        $company->mergeJsonColumn('pos_printer_settings', function (array $current): array {
            $current['receipt_printer'] = 'Rebound';
            return $current;
        });

        $fresh = $company->fresh()->pos_printer_settings;
        $this->assertSame('Rebound', $fresh['receipt_printer'] ?? null);
        $this->assertSame('Kitchen-58', $fresh['kot_printer'] ?? null);
        $this->assertSame(['Counter-80', 'Kitchen-58'], $fresh['available_printers'] ?? null);
    }

    public function test_null_mutator_leaves_the_column_untouched(): void
    {
        $company = $this->company();
        $before = $company->invoice_display_prefs;

        $result = $company->mergeJsonColumn('invoice_display_prefs', fn (array $current) => null);

        $this->assertSame($before, $result);
        $this->assertSame($before, $company->fresh()->invoice_display_prefs);
    }
}
