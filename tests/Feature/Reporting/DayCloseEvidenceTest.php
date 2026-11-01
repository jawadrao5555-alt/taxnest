<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\CompanyReadReports;
use App\Services\Reporting\DayCloseEvidence;
use App\Services\Reporting\DiagnosticCoverage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DayCloseEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Carbon::setTestNow(Carbon::parse('2026-11-01 02:00:00', 'Asia/Karachi'));
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type');
            $t->softDeletes();
        });
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Synthetic Cafe', 'product_type' => 'pos'],
            ['id' => 2, 'name' => 'Other Cafe', 'product_type' => 'pos'],
            ['id' => 3, 'name' => 'FBR Cafe', 'product_type' => 'fbrpos'],
        ]);
        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->default(0);
            $t->date('report_date');
            foreach (['opening_float', 'cash_amount', 'card_amount', 'other_amount', 'expected_cash', 'counted_cash', 'cash_variance', 'total_amount', 'returns_amount'] as $field) {
                $t->decimal($field, 14, 2)->nullable();
            }
            $t->text('counter_summary')->nullable();
            $t->text('rider_summary')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(array $override = []): int
    {
        return DB::table('pos_day_close_reports')->insertGetId($override + [
            'company_id' => 1, 'branch_id' => 0, 'report_date' => '2026-10-31', 'opening_float' => 0,
            'cash_amount' => 800, 'card_amount' => 200, 'other_amount' => 0, 'expected_cash' => 750,
            'counted_cash' => 760, 'cash_variance' => 10, 'total_amount' => 1000, 'returns_amount' => 100,
            'rider_summary' => json_encode(['cash_out' => 100, 'cash_in' => 50, 'secret_note' => 'PRIVATE-RIDER']),
            'counter_summary' => json_encode([
                ['terminal_id' => 5, 'opening' => 0, 'cash_sales' => 300, 'rider_out' => 0, 'rider_in' => 0, 'expected' => 300, 'counted' => 300, 'variance' => 0, 'notes' => 'PRIVATE-COUNTER'],
                ['terminal_id' => 6, 'opening' => 0, 'cash_sales' => 500, 'rider_out' => 100, 'rider_in' => 50, 'expected' => 450, 'counted' => 460, 'variance' => 10],
            ]), 'created_at' => '2026-11-01 01:30:00',
        ]);
    }

    private function read(array $args = []): array
    {
        return app(DayCloseEvidence::class)->read(1, $args + ['date_from' => '2026-10-26', 'date_to' => '2026-11-01']);
    }

    public function test_seven_saved_business_dates_after_midnight_multiple_counters_and_archived_bills(): void
    {
        // No live transaction table exists: frozen history must not be reconstructed.
        $id = $this->row();
        $this->row(['company_id' => 2, 'expected_cash' => 999999]);
        $this->row(['report_date' => '2026-10-25']);
        $before = DB::table('pos_day_close_reports')->get()->toJson();
        $r = $this->read();
        $this->assertCount(7, $r['days']);
        $this->assertSame('NO_RECORD', $r['days'][6]['status']);
        $this->assertTrue($r['days'][6]['current_calendar_date']);
        $this->assertCount(1, $r['records']);
        $row = $r['records'][0];
        $this->assertSame($id, $row['id']);
        $this->assertSame('2026-10-31', $row['business_date']);
        $this->assertSame(750.0, $row['arithmetic']['calculated_expected']);
        $this->assertSame('PASS', $row['arithmetic']['expected_check']);
        $this->assertSame('PASS', $row['arithmetic']['variance_check']);
        $this->assertSame(450.0, $row['counter_snapshot'][1]['expected']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        $this->assertFalse($r['coverage']['complete']);
        $this->assertSame($before, DB::table('pos_day_close_reports')->get()->toJson());
    }

    public function test_missing_opening_and_rider_are_not_zero_and_saved_mismatch_is_detected(): void
    {
        $this->row(['opening_float' => null]);
        $this->row(['rider_summary' => null]);
        $this->row(['expected_cash' => 900, 'cash_variance' => 140]);
        $r = $this->read()['records'];
        $this->assertNull($r[0]['opening_float']);
        $this->assertNull($r[0]['arithmetic']['calculated_expected']);
        $this->assertSame('UNAVAILABLE', $r[1]['arithmetic']['expected_check']);
        $this->assertSame('ISSUE', $r[2]['arithmetic']['expected_check']);
        $this->assertSame('ISSUE', $r[2]['arithmetic']['variance_check']);
        $this->assertSame(150.0, $r[2]['arithmetic']['stored_minus_calculated']);
    }

    public function test_pagination_keeps_total_and_branch_zero_and_cannot_hide_counter_truncation(): void
    {
        $first = $this->row(['counter_summary' => json_encode(array_fill(0, 26, ['terminal_id' => 5]))]);
        $second = $this->row();
        $this->row(['branch_id' => 8]);
        $r = $this->read(['branch_id' => 0, 'limit' => 1]);
        $this->assertSame(2, $r['coverage']['total_matching']);
        $this->assertSame($first, $r['pagination']['next_after_id']);
        $this->assertSame('PARTIAL', $r['records'][0]['counter_snapshot_coverage']['status']);
        $r = $this->read(['branch_id' => 0, 'after_id' => $first, 'limit' => 1]);
        $this->assertSame($second, $r['records'][0]['id']);
        $this->assertFalse($r['pagination']['has_more']);
        $this->assertSame(2, $r['coverage']['total_matching']);
    }

    public function test_missing_sources_and_malformed_or_oversize_snapshots_stay_unknown(): void
    {
        $this->assertSame('UNAVAILABLE', $this->read(['stream' => 'openings'])['coverage']['status']);
        $this->row(['rider_summary' => '{broken']);
        $this->row(['rider_summary' => json_encode(['cash_out' => 0, 'cash_in' => 0, 'extra' => str_repeat('x', 65536)])]);
        foreach ($this->read()['records'] as $r) {
            $this->assertSame('UNAVAILABLE', $r['arithmetic']['expected_check']);
        }
        Schema::drop('pos_day_close_reports');
        $this->assertSame('UNAVAILABLE', $this->read()['coverage']['status']);
    }

    public function test_cross_tenant_id_is_rejected_before_reading_evidence(): void
    {
        $this->expectException(ValidationException::class);
        app(CompanyReadReports::class)->dayClose(['company_name' => 'Other Cafe', 'company_id' => 2], [1]);
    }

    public function test_opening_and_counter_streams_are_scoped_and_never_assume_rider_zero(): void
    {
        Schema::create('pos_day_openings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('terminal_id');
            $t->date('business_date');
            $t->decimal('opening_cash', 14, 2)->nullable();
        });
        Schema::create('pos_counter_closes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('terminal_id');
            $t->date('business_date');
            foreach (['opening_float', 'cash_sales', 'expected_cash', 'counted_cash', 'cash_variance'] as $key) {
                $t->decimal($key, 14, 2)->nullable();
            }
        });
        foreach ([1, 2] as $company) {
            DB::table('pos_day_openings')->insert(['company_id' => $company, 'branch_id' => 4, 'terminal_id' => 7, 'business_date' => '2026-10-31', 'opening_cash' => 0]);
            DB::table('pos_counter_closes')->insert(['company_id' => $company, 'branch_id' => 4, 'terminal_id' => 7, 'business_date' => '2026-10-31', 'opening_float' => 0, 'cash_sales' => 100, 'expected_cash' => 100, 'counted_cash' => 100, 'cash_variance' => 0]);
        }
        $opening = $this->read(['stream' => 'openings', 'branch_id' => 4, 'terminal_id' => 7]);
        $this->assertCount(1, $opening['records']);
        $this->assertSame(0.0, $opening['records'][0]['opening_cash']);
        $close = $this->read(['stream' => 'counter_closes', 'branch_id' => 4, 'terminal_id' => 7]);
        $this->assertCount(1, $close['records']);
        $this->assertSame('PASS', $close['records'][0]['arithmetic']['variance_check']);
        $this->assertSame('UNAVAILABLE', $close['records'][0]['arithmetic']['expected_check']);
        $this->assertCount(0, $this->read(['stream' => 'counter_closes', 'terminal_id' => 8])['records']);
    }

    public function test_range_and_pagination_bounds_are_validated_before_query(): void
    {
        foreach ([['date_from' => '2026-10-01', 'date_to' => '2026-11-01'], ['date_from' => '2026-11-02'], ['limit' => 51], ['after_id' => -1], ['terminal_id' => 0], ['stream' => 'arbitrary_table']] as $args) {
            try {
                $this->read($args);
                $this->fail('Invalid scope was accepted: '.json_encode($args));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_fbr_is_not_silently_read_as_pra(): void
    {
        $this->expectException(ValidationException::class);
        app(DayCloseEvidence::class)->read(3, []);
    }

    public function test_unsupported_filter_is_not_silently_ignored(): void
    {
        $this->expectException(ValidationException::class);
        app(CompanyReadReports::class)->dayClose(['company_name' => 'Synthetic Cafe', 'payment_method' => 'card'], [1]);
    }

    public function test_catalog_reports_new_routes_as_unmapped_and_does_not_execute_them(): void
    {
        $service = app(DiagnosticCoverage::class);
        $before = $service->catalog(['product' => 'erp']);
        Route::get('/new-erp-feature', function () {
            throw new \RuntimeException('Must never execute');
        });
        $r = $service->catalog(['product' => 'shared', 'limit' => 50]);
        $this->assertFalse($r['complete']);
        $this->assertSame(0, $r['tenant_checks_run']);
        $this->assertSame('NOT_IMPLEMENTED', $before['features'][0]['capability']);
        $found = false;
        do {
            foreach ($r['route_inventory']['rows'] as $route) {
                if ($route['uri_template'] === 'new-erp-feature') {
                    $this->assertSame('UNMAPPED', $route['mapping']);
                    $found = true;
                }
            }
            $next = $r['route_inventory']['pagination']['next_offset'];
            if ($next !== null) {
                $r = $service->catalog(['product' => 'shared', 'offset' => $next, 'limit' => 50]);
            }
        } while ($next !== null);
        $this->assertTrue($found);
    }
}
