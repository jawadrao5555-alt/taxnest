<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\DiagnosticSourceEvidence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiagnosticSourceEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        config(['app.timezone' => 'UTC', 'reporting_connector.timezone' => 'Asia/Karachi']);
        Schema::create('companies', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('product_type'); });
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Synthetic DI', 'product_type' => 'di'],
            ['id' => 2, 'name' => 'Synthetic Other', 'product_type' => 'di'],
            ['id' => 3, 'name' => 'Synthetic Health', 'product_type' => 'erps'],
            ['id' => 4, 'name' => 'Synthetic FBR', 'product_type' => 'fbrpos'],
            ['id' => 5, 'name' => 'Synthetic PRA', 'product_type' => 'pos'],
        ]);
    }

    public function test_di_source_resolves_tenant_through_invoice_and_never_returns_payloads(): void
    {
        Schema::create('invoices', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); });
        Schema::create('fbr_logs', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('invoice_id'); $t->text('request_payload'); $t->dateTime('created_at'); });
        DB::table('invoices')->insert([['id' => 11, 'company_id' => 1], ['id' => 22, 'company_id' => 2]]);
        DB::table('fbr_logs')->insert([
            ['id' => 1, 'invoice_id' => 11, 'request_payload' => 'PRIVATE-TOKEN', 'created_at' => '2026-11-06 20:00:00'],
            ['id' => 2, 'invoice_id' => 22, 'request_payload' => 'PRIVATE-FOREIGN', 'created_at' => '2026-11-06 20:00:00'],
            ['id' => 3, 'invoice_id' => 11, 'request_payload' => 'PRIVATE-OLD', 'created_at' => '2026-11-06 18:59:59'],
        ]);
        $r = app(DiagnosticSourceEvidence::class)->read(['company_name' => 'Synthetic DI', 'date_from' => '2026-11-07', 'source' => 'di.fiscal'], [1]);
        $this->assertSame([1], array_column($r['rows'], 'id'));
        $this->assertSame('2026-11-07T01:00:00+05:00', $r['rows'][0]['event_at']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        $this->assertFalse($r['coverage']['seven_day_complete']);
        $this->assertSame(DiagnosticSourceEvidence::FIELD_CONTRACT_VERSION, $r['field_coverage']['contract_version']);
        $this->assertSame(['id', 'event_at'], $r['field_coverage']['returned_fields']);
        $this->assertFalse($r['field_coverage']['source_record_complete']);
        $this->assertFalse($r['field_coverage']['non_allowlisted_fields_returned']);
        $this->assertSame('NOT_RETURNED', $r['field_coverage']['payload_content']);
        $this->assertSame('NOT_RETURNED', $r['field_coverage']['personal_data']);
        $this->assertSame('NOT_RETURNED', $r['field_coverage']['financial_values']);
        $this->assertSame('NOT_RETURNED', $r['field_coverage']['regulator_response']);
    }

    public function test_health_metadata_and_missing_fbr_source_remain_explicit(): void
    {
        Schema::create('health_audit_events', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->dateTime('occurred_at'); $t->text('meta'); });
        DB::table('health_audit_events')->insert(['id' => 5, 'company_id' => 3, 'occurred_at' => '2026-11-07 01:00:00', 'meta' => 'PRIVATE-PATIENT']);
        $service = app(DiagnosticSourceEvidence::class);
        $r = $service->read(['company_name' => 'Synthetic Health', 'date_from' => '2026-11-07', 'source' => 'health.audit'], [3]);
        $this->assertSame('PARTIAL', $r['status']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        $args = ['company_name' => 'Synthetic FBR', 'date_from' => '2026-11-07', 'source' => 'fbrpos.fiscal'];
        $this->assertSame('UNAVAILABLE', $service->read($args, [4])['status']);
        Schema::create('fbr_pos_logs', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->dateTime('created_at'); });
        $r = $service->read($args, [4]);
        $this->assertSame('NO_RETAINED_EVENTS', $r['status']);
        $this->assertSame('UNKNOWN', $r['coverage']['collection_status']);
        $this->expectException(ValidationException::class);
        $service->read($args, [1]);
    }

    public function test_operational_adapters_are_tenant_scoped_and_return_metadata_only(): void
    {
        foreach ([
            'pos_transactions' => 'pos.sales',
            'pos_print_jobs' => 'pos.print_attempts',
            'inventory_movements' => 'pos.stock_movements',
        ] as $table => $source) {
            Schema::create($table, function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->dateTime('created_at'); $t->text('private_payload'); });
            DB::table($table)->insert([
                ['id'=>1, 'company_id'=>5, 'created_at'=>'2026-11-06 20:00:00', 'private_payload'=>'PRIVATE-OWN'],
                ['id'=>2, 'company_id'=>99, 'created_at'=>'2026-11-06 20:00:00', 'private_payload'=>'PRIVATE-FOREIGN'],
            ]);
            $r = app(DiagnosticSourceEvidence::class)->read(['company_name'=>'Synthetic PRA', 'date_from'=>'2026-11-07', 'source'=>$source], [5]);
            $this->assertSame([1], array_column($r['rows'], 'id'), $source);
            $this->assertSame(DiagnosticSourceEvidence::REGISTRY_VERSION, $r['registry_version']);
            $this->assertStringNotContainsString('PRIVATE', json_encode($r));
            $this->assertFalse($r['coverage']['complete']);
            $this->assertSame(['id', 'event_at'], $r['field_coverage']['returned_fields'], $source);
            $this->assertFalse($r['field_coverage']['source_record_complete'], $source);
        }

        foreach ([
            ['fbr_pos_transactions', 'fbrpos.sales', 4, 'Synthetic FBR'],
            ['health_bills', 'health.billing', 3, 'Synthetic Health'],
        ] as [$table, $source, $companyId, $companyName]) {
            Schema::create($table, function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->dateTime('created_at'); $t->text('private_payload'); });
            DB::table($table)->insert(['id'=>1, 'company_id'=>$companyId, 'created_at'=>'2026-11-06 20:00:00', 'private_payload'=>'PRIVATE']);
            $r = app(DiagnosticSourceEvidence::class)->read(['company_name'=>$companyName, 'date_from'=>'2026-11-07', 'source'=>$source], [$companyId]);
            $this->assertSame([1], array_column($r['rows'], 'id'));
            $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        }
    }

    public function test_optional_branch_scope_is_exact_and_never_falls_back_to_company_wide_rows(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->dateTime('created_at');
        });
        DB::table('branches')->insert([
            ['id' => 10, 'company_id' => 5, 'created_at' => '2026-11-06 20:00:00'],
            ['id' => 11, 'company_id' => 5, 'created_at' => '2026-11-06 20:00:00'],
            ['id' => 20, 'company_id' => 2, 'created_at' => '2026-11-06 20:00:00'],
        ]);
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->dateTime('created_at');
            $t->text('private_payload');
        });
        DB::table('pos_transactions')->insert([
            ['id' => 1, 'company_id' => 5, 'branch_id' => 10, 'created_at' => '2026-11-06 20:00:00', 'private_payload' => 'PRIVATE-SELECTED'],
            ['id' => 2, 'company_id' => 5, 'branch_id' => 11, 'created_at' => '2026-11-06 20:00:00', 'private_payload' => 'PRIVATE-OTHER-BRANCH'],
            ['id' => 3, 'company_id' => 2, 'branch_id' => 20, 'created_at' => '2026-11-06 20:00:00', 'private_payload' => 'PRIVATE-FOREIGN'],
        ]);

        $service = app(DiagnosticSourceEvidence::class);
        $r = $service->read(['company_name' => 'Synthetic PRA', 'date_from' => '2026-11-07',
            'source' => 'pos.sales', 'branch_id' => 10], [5]);
        $this->assertSame([1], array_column($r['rows'], 'id'));
        $this->assertSame(['id' => 10, 'scope' => 'exact'], $r['branch']);
        $this->assertSame('UNAVAILABLE_FOR_BRANCH_SCOPE', $r['collection_observation']['status']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));

        $branch = $service->read(['company_name' => 'Synthetic PRA', 'date_from' => '2026-11-07',
            'source' => 'pos.branches', 'branch_id' => 11], [5]);
        $this->assertSame([11], array_column($branch['rows'], 'id'));

        Schema::create('pra_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->dateTime('created_at');
        });
        DB::table('pra_logs')->insert(['id' => 9, 'company_id' => 5, 'created_at' => '2026-11-06 20:00:00']);
        $unsupported = $service->read(['company_name' => 'Synthetic PRA', 'date_from' => '2026-11-07',
            'source' => 'pra.fiscal', 'branch_id' => 10], [5]);
        $this->assertSame('BRANCH_SCOPE_UNAVAILABLE', $unsupported['status']);
        $this->assertSame([], $unsupported['rows']);

        $this->expectException(ValidationException::class);
        $service->read(['company_name' => 'Synthetic PRA', 'date_from' => '2026-11-07',
            'source' => 'pos.sales', 'branch_id' => 20], [5]);
    }
}
