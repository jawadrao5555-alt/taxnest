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
            [1, 'Synthetic DI', 'di.import_batches', 'invoice_import_batches', 'created_at', null],
            [1, 'Synthetic DI', 'di.audit_exports', 'audit_packs', 'created_at', null],
            [3, 'Synthetic Health', 'health.prescriptions', 'health_prescriptions', 'created_at', null],
            [3, 'Synthetic Health', 'health.pharmacy_sales', 'health_pharmacy_sales', 'created_at', null],
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

    public function test_shared_streams_read_and_collect_only_exact_company_rows_for_all_products(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07 12:00:00', 'UTC'));
        (require base_path('database/migrations/2026_12_10_040000_create_diagnostic_source_observations.php'))->up();
        foreach (['platform_audit' => 'audit_logs', 'notifications' => 'notifications', 'branches' => 'branches'] as $suffix => $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id')->nullable(); $t->dateTime('created_at'); $t->text('private_payload');
            });
            foreach ([1,3,4,5,null] as $id) {
                DB::table($table)->insert(['company_id' => $id, 'created_at' => '2026-11-06 20:00:00', 'private_payload' => 'PRIVATE-DETAIL']);
            }
            $before = DB::table($table)->get()->toJson();
            foreach ([[1,'di','Synthetic DI'],[3,'health','Synthetic Health'],[4,'fbrpos','Synthetic FBR'],[5,'pos','Synthetic PRA']] as [$id,$prefix,$name]) {
                $source = $prefix.'.'.$suffix;
                $this->assertSame('OBSERVED', app(DiagnosticSourceCollection::class)->capture($id,$source,'2026-11-07',(string) \Illuminate\Support\Str::uuid()));
                $r = app(DiagnosticSourceEvidence::class)->read(['company_name' => $name,'date_from' => '2026-11-07','source' => $source],[$id]);
                $this->assertSame('PARTIAL',$r['status']);
                $this->assertSame(1,$r['observed']['retained_rows_in_window']);
                $this->assertSame(1,$r['collection_observation']['retained_rows']);
                $this->assertSame((int) DB::table($table)->where('company_id',$id)->value('id'),$r['rows'][0]['id']);
                $this->assertStringNotContainsString('PRIVATE',json_encode($r));
                $this->assertFalse($r['coverage']['seven_day_complete']);
            }
            $this->assertSame($before,DB::table($table)->get()->toJson());
        }
        $this->travelBack();
    }

    public function test_support_case_lifecycle_is_a_company_scoped_read_only_source_for_all_products(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07 12:00:00', 'UTC'));
        (require base_path('database/migrations/2026_12_10_040000_create_diagnostic_source_observations.php'))->up();
        Schema::create('support_cases', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('state');
            $t->string('subject');
            $t->text('private_note')->nullable();
            $t->timestamps();
        });
        foreach ([1, 3, 4, 5] as $companyId) {
            DB::table('support_cases')->insert([
                'company_id' => $companyId,
                'state' => 'reopened',
                'subject' => 'PRIVATE-SUBJECT-'.$companyId,
                'private_note' => 'PRIVATE-NOTE-'.$companyId,
                'created_at' => '2026-11-06 19:00:00',
                'updated_at' => '2026-11-06 20:00:00',
            ]);
        }
        DB::table('support_cases')->insert([
            'company_id' => 99,
            'state' => 'open',
            'subject' => 'PRIVATE-FOREIGN',
            'private_note' => 'PRIVATE-FOREIGN-NOTE',
            'created_at' => '2026-11-06 19:00:00',
            'updated_at' => '2026-11-06 20:00:00',
        ]);
        $before = DB::table('support_cases')->orderBy('id')->get()->toJson();
        foreach ([[1, 'di', 'Synthetic DI'], [3, 'health', 'Synthetic Health'], [4, 'fbrpos', 'Synthetic FBR'], [5, 'pos', 'Synthetic PRA']] as [$id, $prefix, $name]) {
            $source = $prefix.'.support_cases';
            $this->assertSame('OBSERVED', app(DiagnosticSourceCollection::class)->capture($id, $source, '2026-11-07', (string) \Illuminate\Support\Str::uuid()));
            $report = app(DiagnosticSourceEvidence::class)->read([
                'company_name' => $name,
                'date_from' => '2026-11-07',
                'source' => $source,
            ], [$id]);
            $this->assertSame('PARTIAL', $report['status']);
            $this->assertSame(1, $report['observed']['retained_rows_in_window']);
            $this->assertSame(1, $report['collection_observation']['retained_rows']);
            $this->assertCount(1, $report['rows']);
            $this->assertStringNotContainsString('PRIVATE', json_encode($report));
            $this->assertFalse($report['coverage']['complete']);
        }
        $this->assertSame($before, DB::table('support_cases')->orderBy('id')->get()->toJson());
        $this->travelBack();
    }

    public function test_live_ops_job_and_release_evidence_is_company_scoped_and_payload_free(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07 12:00:00', 'UTC'));
        (require base_path('database/migrations/2026_12_10_040000_create_diagnostic_source_observations.php'))->up();
        Schema::create('live_ops_autonomous_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('status');
            $t->string('operation')->nullable();
            $t->string('fix_commit', 64)->nullable();
            $t->string('pull_request', 32)->nullable();
            $t->string('deployment_sha', 64)->nullable();
            $t->text('error')->nullable();
            $t->json('tests_result')->nullable();
            $t->json('deployment_result')->nullable();
            $t->timestamps();
        });
        foreach ([1, 3, 4, 5] as $companyId) {
            DB::table('live_ops_autonomous_requests')->insert([
                'company_id' => $companyId,
                'status' => 'FAILED',
                'operation' => 'diagnose',
                'fix_commit' => 'commit-'.$companyId,
                'pull_request' => '#'.$companyId,
                'deployment_sha' => null,
                'error' => 'PRIVATE-FAILURE-'.$companyId,
                'tests_result' => json_encode(['private' => 'PRIVATE-TESTS']),
                'deployment_result' => json_encode(['private' => 'PRIVATE-DEPLOYMENT']),
                'created_at' => '2026-11-06 19:00:00',
                'updated_at' => '2026-11-06 20:00:00',
            ]);
        }
        DB::table('live_ops_autonomous_requests')->insert([
            'company_id' => null,
            'status' => 'FAILED',
            'operation' => 'global',
            'error' => 'PRIVATE-GLOBAL',
            'created_at' => '2026-11-06 19:00:00',
            'updated_at' => '2026-11-06 20:00:00',
        ]);
        DB::table('live_ops_autonomous_requests')->insert([
            'company_id' => 99,
            'status' => 'FAILED',
            'operation' => 'foreign',
            'error' => 'PRIVATE-FOREIGN',
            'created_at' => '2026-11-06 19:00:00',
            'updated_at' => '2026-11-06 20:00:00',
        ]);
        $before = DB::table('live_ops_autonomous_requests')->orderBy('id')->get()->toJson();
        foreach ([[1, 'di', 'Synthetic DI'], [3, 'health', 'Synthetic Health'], [4, 'fbrpos', 'Synthetic FBR'], [5, 'pos', 'Synthetic PRA']] as [$id, $prefix, $name]) {
            $source = $prefix.'.live_ops';
            $this->assertSame('OBSERVED', app(DiagnosticSourceCollection::class)->capture($id, $source, '2026-11-07', (string) \Illuminate\Support\Str::uuid()));
            $report = app(DiagnosticSourceEvidence::class)->read([
                'company_name' => $name,
                'date_from' => '2026-11-07',
                'source' => $source,
            ], [$id]);
            $this->assertSame('PARTIAL', $report['status']);
            $this->assertSame(1, $report['observed']['retained_rows_in_window']);
            $this->assertSame(1, $report['collection_observation']['retained_rows']);
            $this->assertCount(1, $report['rows']);
            $this->assertSame(['id', 'event_at', 'status', 'operation', 'fix_commit', 'pull_request', 'deployment_sha'], array_keys($report['rows'][0]));
            $this->assertSame('FAILED', $report['rows'][0]['status']);
            $this->assertSame('diagnose', $report['rows'][0]['operation']);
            $this->assertSame('commit-'.$id, $report['rows'][0]['fix_commit']);
            $this->assertSame('#'.$id, $report['rows'][0]['pull_request']);
            $this->assertNull($report['rows'][0]['deployment_sha']);
            $this->assertStringContainsString('do not prove', $report['release_evidence_note']);
            $this->assertStringNotContainsString('PRIVATE', json_encode($report));
            $this->assertFalse($report['coverage']['complete']);
        }
        $this->assertSame($before, DB::table('live_ops_autonomous_requests')->orderBy('id')->get()->toJson());
        $this->travelBack();
    }

    public function test_exhausted_cursor_does_not_claim_the_retained_window_is_empty(): void
    {
        Schema::create('pos_ims_submissions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->dateTime('created_at');
        });
        DB::table('pos_ims_submissions')->insert(['id' => 1, 'company_id' => 5, 'created_at' => '2026-11-06 20:00:00']);
        $r = app(DiagnosticSourceEvidence::class)->read(['company_name' => 'Synthetic PRA', 'date_from' => '2026-11-07',
            'source' => 'pos.ims', 'after_id' => 999], [5]);
        $this->assertSame('PARTIAL', $r['status']);
        $this->assertSame(1, $r['observed']['retained_rows_in_window']);
        $this->assertSame(0, $r['observed']['page_rows']);
        $this->assertSame([], $r['rows']);
        $this->assertFalse($r['pagination']['has_more']);
        $this->assertFalse($r['coverage']['complete']);
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
