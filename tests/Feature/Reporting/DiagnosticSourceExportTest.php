<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\DiagnosticSourceExport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiagnosticSourceExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        config(['app.timezone' => 'UTC', 'reporting_connector.timezone' => 'Asia/Karachi']);
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type');
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->dateTime('created_at');
        });
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->dateTime('created_at');
            $table->text('private_payload');
        });
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Synthetic export PRA', 'product_type' => 'pos'],
            ['id' => 2, 'name' => 'Synthetic foreign', 'product_type' => 'pos'],
        ]);
        DB::table('branches')->insert([
            ['id' => 10, 'company_id' => 1, 'created_at' => '2026-11-06 20:00:00'],
            ['id' => 11, 'company_id' => 1, 'created_at' => '2026-11-06 20:00:00'],
            ['id' => 20, 'company_id' => 2, 'created_at' => '2026-11-06 20:00:00'],
        ]);
        for ($id = 1; $id <= 130; $id++) {
            DB::table('pos_transactions')->insert([
                'id' => $id,
                'company_id' => 1,
                'branch_id' => 10,
                'created_at' => '2026-11-06 20:00:00',
                'private_payload' => 'PRIVATE-SELECTED-'.$id,
            ]);
        }
        DB::table('pos_transactions')->insert([
            ['id' => 131, 'company_id' => 1, 'branch_id' => 11, 'created_at' => '2026-11-06 20:00:00', 'private_payload' => 'PRIVATE-SIBLING'],
            ['id' => 132, 'company_id' => 2, 'branch_id' => 20, 'created_at' => '2026-11-06 20:00:00', 'private_payload' => 'PRIVATE-FOREIGN'],
        ]);
    }

    public function test_export_is_bounded_branch_scoped_redacted_and_resumable(): void
    {
        $before = DB::table('pos_transactions')->orderBy('id')->get()->toJson();
        $args = ['company_name' => 'Synthetic export PRA', 'date_from' => '2026-11-07',
            'source' => 'pos.sales', 'branch_id' => 10, 'max_rows' => 120];
        $first = app(DiagnosticSourceExport::class)->read($args, [1]);

        $this->assertCount(120, $first['rows']);
        $this->assertSame(1, $first['rows'][0]['id']);
        $this->assertSame(120, $first['rows'][119]['id']);
        $this->assertTrue($first['export']['truncated']);
        $this->assertSame(120, $first['export']['next_after_id']);
        $this->assertFalse($first['export']['retained_window_rows_exhausted']);
        $this->assertFalse($first['export']['source_collection_complete']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($first));

        $second = app(DiagnosticSourceExport::class)->read(array_replace($args, ['after_id' => 120, 'max_rows' => 50]), [1]);
        $this->assertSame(range(121, 130), array_column($second['rows'], 'id'));
        $this->assertFalse($second['export']['truncated']);
        $this->assertTrue($second['export']['retained_window_rows_exhausted']);
        $this->assertNull($second['export']['next_after_id']);
        $this->assertSame($before, DB::table('pos_transactions')->orderBy('id')->get()->toJson());
    }

    public function test_export_rejects_unbounded_or_arbitrary_filters(): void
    {
        foreach ([
            ['max_rows' => DiagnosticSourceExport::MAX_ROWS + 1],
            ['table' => 'users'],
        ] as $invalid) {
            try {
                app(DiagnosticSourceExport::class)->read(['company_name' => 'Synthetic export PRA',
                    'date_from' => '2026-11-07', 'source' => 'pos.sales'] + $invalid, [1]);
                $this->fail('Invalid export filter was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
