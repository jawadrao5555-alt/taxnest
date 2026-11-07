<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\DiagnosticSourceCollection;
use App\Services\Reporting\DiagnosticSourceEvidence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DiagnosticExpandedSourcesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        config(['app.timezone' => 'UTC', 'reporting_connector.timezone' => 'Asia/Karachi']);
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type');
        });
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Synthetic DI', 'product_type' => 'di'], ['id' => 3, 'name' => 'Synthetic Health', 'product_type' => 'erps'],
            ['id' => 4, 'name' => 'Synthetic FBR', 'product_type' => 'fbrpos'], ['id' => 5, 'name' => 'Synthetic PRA', 'product_type' => 'pos'],
        ]);
    }

    public function test_new_adapters_filter_product_company_and_window_without_payloads(): void
    {
        foreach ([
            [5, 'Synthetic PRA', 'pos.print_receipts', 'pos_print_evidence', 'received_at', 'pos'],
            [5, 'Synthetic PRA', 'pos.offline_samples', 'pra_diagnostic_samples', 'received_at', null],
            [5, 'Synthetic PRA', 'pos.ims', 'pos_ims_submissions', 'created_at', null],
            [5, 'Synthetic PRA', 'pos.di', 'pos_di_sandbox_submissions', 'created_at', null],
            [5, 'Synthetic PRA', 'pos.orders', 'restaurant_orders', 'created_at', null],
            [4, 'Synthetic FBR', 'fbrpos.callbacks', 'fbr_pos_callback_diagnostics', 'callback_received_at', null],
            [3, 'Synthetic Health', 'health.payments', 'health_payments', 'created_at', null],
            [3, 'Synthetic Health', 'health.federal', 'health_fbr_submissions', 'created_at', null],
            [3, 'Synthetic Health', 'health.stock', 'health_batch_movements', 'created_at', null],
            [1, 'Synthetic DI', 'di.sales', 'invoices', 'created_at', null],
        ] as [$company,$name,$source,$table,$stamp,$product]) {
            Schema::create($table, function (Blueprint $t) use ($stamp, $product) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->dateTime($stamp);
                $t->text('private_payload');
                if ($product) {
                    $t->string('product_type');
                }
            });
            $rows = [['id' => 1, 'company_id' => $company, $stamp => '2026-11-06 20:00:00', 'private_payload' => 'PRIVATE-OWN'],
                ['id' => 2, 'company_id' => 99, $stamp => '2026-11-06 20:00:00', 'private_payload' => 'PRIVATE-OTHER'],
                ['id' => 3, 'company_id' => $company, $stamp => '2026-11-06 20:01:00', 'private_payload' => 'PRIVATE-LATE']];
            if ($product) {
                foreach ($rows as &$row) {
                    $row['product_type'] = $product;
                }unset($row);
                $rows[] = ['id' => 4, 'company_id' => $company, $stamp => '2026-11-06 20:00:00', 'private_payload' => 'PRIVATE-WRONG-PRODUCT', 'product_type' => 'fbrpos'];
            }
            DB::table($table)->insert($rows);
            $before = DB::table($table)->get()->toJson();
            $r = app(DiagnosticSourceEvidence::class)->read(['company_name' => $name, 'date_from' => '2026-11-07', 'source' => $source, 'after_id' => 1, 'limit' => 1], [$company]);
            $this->assertSame([3], array_column($r['rows'], 'id'), $source);
            $this->assertSame(2, $r['observed']['retained_rows_in_window']);
            $this->assertSame('2026-11-06 20:00:00', $r['observed']['earliest_in_window']);
            $this->assertFalse($r['coverage']['complete']);
            $this->assertStringNotContainsString('PRIVATE', json_encode($r));
            $this->assertSame($before, DB::table($table)->get()->toJson());
            if ($source === 'pos.offline_samples') {
                $this->assertSame('agent_collection', $r['related_operation']['stream']);
            }
        }
    }

    public function test_read_and_collection_apply_identical_print_product_filter(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07 12:00:00', 'UTC'));
        Schema::create('pos_print_evidence', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('product_type');
            $t->dateTime('received_at');
        });
        DB::table('pos_print_evidence')->insert([
            ['id' => 1, 'company_id' => 4, 'product_type' => 'fbrpos', 'received_at' => '2026-11-06 20:00:00'],
            ['id' => 2, 'company_id' => 4, 'product_type' => 'pos', 'received_at' => '2026-11-06 20:00:00'],
            ['id' => 3, 'company_id' => 5, 'product_type' => 'fbrpos', 'received_at' => '2026-11-06 20:00:00'],
        ]);
        (require base_path('database/migrations/2026_12_10_040000_create_diagnostic_source_observations.php'))->up();
        $collector = app(DiagnosticSourceCollection::class);
        $this->assertSame('OBSERVED', $collector->capture(4, 'fbrpos.print_receipts', '2026-11-07', (string) \Illuminate\Support\Str::uuid()));
        $r = app(DiagnosticSourceEvidence::class)->read(['company_name' => 'Synthetic FBR', 'date_from' => '2026-11-07', 'source' => 'fbrpos.print_receipts'], [4]);
        $this->assertSame(1, $r['collection_observation']['retained_rows']);
        $this->assertSame(1, $r['observed']['retained_rows_in_window']);
        $this->assertSame([1], array_column($r['rows'], 'id'));
        $this->travelBack();
    }

    public function test_source_read_failure_is_safe_and_does_not_invent_empty_activity(): void
    {
        Schema::create('pos_ims_submissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->dateTime('created_at');
        });
        DB::connection()->beforeExecuting(function ($sql) {
            if (str_contains($sql, 'from "pos_ims_submissions"')) {
                throw new \Illuminate\Database\QueryException('sqlite', $sql, [], new \RuntimeException('PRIVATE-FAILURE'));
            }
        });
        $r = app(DiagnosticSourceEvidence::class)->read(['company_name' => 'Synthetic PRA', 'date_from' => '2026-11-07', 'source' => 'pos.ims'], [5]);
        $this->assertSame('SOURCE_QUERY_FAILED', $r['status']);
        $this->assertNull($r['observed']);
        $this->assertStringNotContainsString('PRIVATE',json_encode($r));
        $this->assertStringNotContainsString('select',json_encode($r));
    }
}
