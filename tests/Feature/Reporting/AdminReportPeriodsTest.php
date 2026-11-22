<?php

namespace Tests\Feature\Reporting;

use App\Models\AdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminReportPeriodsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('status');
            $table->string('fbr_status');
            foreach (['subtotal', 'tax_amount', 'discount_amount', 'total_amount'] as $field) {
                $table->decimal($field, 12, 2)->default(0);
            }
            $table->timestamp('created_at');
        });
        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('product_id');
            $table->string('item_name');
            foreach (['quantity', 'subtotal', 'tax_amount', 'total'] as $field) {
                $table->decimal($field, 12, 2)->default(0);
            }
        });
        $this->travelTo(Carbon::parse('2028-02-29 12:00:00', config('app.timezone')));
        $this->actingAs((new AdminUser)->forceFill(['id' => 1, 'name' => 'Fictional admin', 'role' => 'super_admin']), 'admin');
        foreach ([
            [1, 3, 7, '2028-02-29 23:59:59', 120],
            [2, 3, 7, '2028-02-01 00:00:00', 100],
            [3, 3, 7, '2028-01-05 12:00:00', 80],
            [4, 3, 8, '2028-02-29 00:00:00', 400],
            [5, 9, 7, '2028-02-29 00:00:00', 800],
            [6, 3, 7, '2028-03-01 00:00:00', 60],
            [7, 3, 7, '2027-02-28 23:59:59', 90],
            [8, 3, 7, '2027-03-01 00:00:00', 900],
            [9, 3, 8, '2027-02-28 00:00:00', 800],
            [10, 9, 7, '2027-02-28 00:00:00', 700],
        ] as [$id, $company, $branch, $when, $amount]) {
            DB::table('fbr_pos_transactions')->insert([
                'id' => $id, 'company_id' => $company, 'branch_id' => $branch, 'status' => 'completed',
                'fbr_status' => 'submitted', 'created_at' => $when, 'subtotal' => $amount, 'total_amount' => $amount,
            ]);
            DB::table('fbr_pos_transaction_items')->insert([
                'transaction_id' => $id, 'product_id' => 1, 'item_name' => 'Fictional item',
                'quantity' => 1, 'subtotal' => $amount, 'total' => $amount,
            ]);
        }
    }

    public function test_date_presets_and_explicit_export_dates_share_totals_and_scope(): void
    {
        $scope = '&company_id=3&branch_id=7';
        foreach ([
            'daily' => ['2028-02-29', 120], 'monthly' => ['2028-02-01', 220], 'yearly' => ['2028-01-01', 300],
        ] as $period => [$start, $total]) {
            foreach (['daily' => 'totals.total', 'products' => 'totals.gross', 'fbr' => 'excelRows.0.2'] as $report => $totalPath) {
                $url = '/admin/reports/'.$report.'?export=json'.$scope;
                $preset = $this->getJson($url.'&period='.$period.'&date_from=ignored')
                    ->assertOk()->assertJsonPath('from', $start)->assertJsonPath('to', '2028-02-29');
                $this->assertEquals($total, $preset->json($totalPath));

                $custom = $this->getJson($url.'&date_from='.$start.'&date_to=2028-02-29')->assertOk();
                $this->assertSame($preset->json('excelRows'), $custom->json('excelRows'));
            }
        }
    }

    public function test_custom_dates_and_periods_are_validated(): void
    {
        $url = '/admin/reports/daily?export=json';
        $this->getJson($url.'&date_from=2028-02-30')->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->getJson($url.'&date_from=2028-02-29&date_to=2028-02-01')->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->getJson($url.'&period=unsupported')->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_year_comparison_uses_the_same_scope_and_keeps_the_export_cutoff(): void
    {
        $query = '?period=two_year&company_id=3&branch_id=7';
        foreach (['daily' => ['total', 300, 90], 'products' => ['gross', 300, 90], 'fbr' => ['total_count', 3, 1]] as $report => [$metric, $current, $prior]) {
            $url = '/admin/reports/'.$report.$query;
            $result = $this->getJson($url.'&export=json')->assertOk()
                ->assertJsonPath('from', '2028-01-01')->assertJsonPath('to', '2028-02-29')
                ->assertJsonPath('comparison.from', '2027-01-01')->assertJsonPath('comparison.to', '2027-02-28');
            $this->assertEquals($current, $result->json('totals.'.$metric));
            $this->assertEquals($prior, $result->json('comparison.totals.'.$metric));
            $summary = view('admin.reports._year-comparison', $result->json())->render();
            $this->assertStringContainsString('2027-01-01 to 2027-02-28', $summary);
            $buttons = view('admin.reports._export-buttons', $result->json() + ['route' => '/admin/reports/'.$report])->render();
            $this->assertSame(3, substr_count($buttons, 'comparison_as_of=2028-02-29'));
            $this->travel(1)->days();
            $pinned = $this->getJson($url.'&export=json&comparison_as_of=2028-02-29')->assertOk();
            $this->assertSame($result->json('comparison'), $pinned->json('comparison'));
            $this->assertSame($result->json('totals'), $pinned->json('totals'));
            $this->travel(-1)->days();
        }
    }
}
