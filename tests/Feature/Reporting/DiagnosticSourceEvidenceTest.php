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
}
