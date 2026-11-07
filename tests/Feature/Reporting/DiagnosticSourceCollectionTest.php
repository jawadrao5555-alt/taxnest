<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\{DiagnosticSourceCollection, DiagnosticSourceEvidence};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Tests\TestCase;

class DiagnosticSourceCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07 12:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'reporting_connector.timezone' => 'Asia/Karachi']);
        Schema::create('companies', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('product_type'); $t->softDeletes(); });
        DB::table('companies')->insert([
            ['id'=>1,'name'=>'Synthetic observed PRA','product_type'=>'pos'],
            ['id'=>2,'name'=>'Other observed PRA','product_type'=>'pos'],
            ['id'=>3,'name'=>'Legacy observed Healthcare','product_type'=>'health'],
        ]);
        (require base_path('database/migrations/2026_12_10_040000_create_diagnostic_source_observations.php'))->up();
        Schema::create('pra_logs', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->dateTime('created_at'); $t->text('payload'); });
    }

    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    public function test_scoped_observations_preserve_late_arrivals_and_same_run_is_idempotent(): void
    {
        DB::table('pra_logs')->insert([
            ['id'=>1,'company_id'=>1,'created_at'=>'2026-11-05 20:00:00','payload'=>'PRIVATE-TOKEN'],
            ['id'=>2,'company_id'=>2,'created_at'=>'2026-11-05 21:00:00','payload'=>'FOREIGN-PRIVATE'],
            ['id'=>3,'company_id'=>1,'created_at'=>'2026-11-05 18:59:59','payload'=>'OUTSIDE-DATE'],
        ]);
        $collector = app(DiagnosticSourceCollection::class);
        $run = (string) Str::uuid();
        $collector->capture(1, 'pra.fiscal', '2026-11-06', $run);
        $collector->capture(1, 'pra.fiscal', '2026-11-06', $run);
        $this->assertSame(1, DB::table('diagnostic_source_observations')->count());
        $old = DB::table('diagnostic_source_observations')->sole();
        $this->assertSame(1, (int) $old->retained_rows);
        DB::table('pra_logs')->insert(['id'=>4,'company_id'=>1,'created_at'=>'2026-11-06 05:00:00','payload'=>'PRIVATE-LATE']);
        $collector->capture(1, 'pra.fiscal', '2026-11-06', (string) Str::uuid());
        $this->assertSame(2, DB::table('diagnostic_source_observations')->count());
        $this->assertEquals($old, DB::table('diagnostic_source_observations')->find($old->id));
        $report = app(DiagnosticSourceEvidence::class)->read(['company_name'=>'Synthetic observed PRA', 'source'=>'pra.fiscal','date_from'=>'2026-11-06'], [1]);
        $this->assertSame(2, $report['collection_observation']['retained_rows']);
        $this->assertFalse($report['collection_observation']['complete_collection_proven']);
        $foreign = $collector->latest(2, 'pra.fiscal', '2026-11-06', '2026-11-06');
        $this->assertSame('NOT_OBSERVED', $foreign['status']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($report));
        $this->assertSame(4, DB::table('pra_logs')->count());
    }

    public function test_missing_empty_and_legacy_health_sources_remain_distinct(): void
    {
        $collector = app(DiagnosticSourceCollection::class);
        $this->assertSame('NO_RETAINED_EVENTS', $collector->capture(1, 'pra.fiscal', '2026-11-06', (string) Str::uuid()));
        $this->assertSame('UNAVAILABLE', $collector->capture(3, 'health.audit', '2026-11-06', (string) Str::uuid()));
        $this->assertNull($collector->latest(3, 'health.audit', '2026-11-06', '2026-11-06')['retained_rows']);
        $this->assertSame(0, $collector->latest(1, 'pra.fiscal', '2026-11-06', '2026-11-06')['retained_rows']);
        $this->expectException(\InvalidArgumentException::class);
        $collector->capture(3, 'pra.fiscal', '2026-11-06', (string) Str::uuid());
    }

    public function test_scheduled_collection_retires_only_derived_old_observations(): void
    {
        $collector = app(DiagnosticSourceCollection::class);
        $collector->capture(1, 'pra.fiscal', '2026-11-06', (string) Str::uuid());
        DB::table('diagnostic_source_observations')->update(['collected_at'=>'2026-10-30 01:00:00']);
        DB::table('pra_logs')->insert(['id'=>1,'company_id'=>1,'created_at'=>'2026-10-01 01:00:00','payload'=>'RETAIN-SOURCE']);
        $this->artisan('diagnostics:observe-sources')->assertSuccessful();
        $this->assertSame(0, DB::table('diagnostic_source_observations')->where('collected_at','<','2026-10-31 12:00:00')->count());
        $this->assertSame(3, DB::table('diagnostic_source_observations')->count());
        $this->assertSame('RETAIN-SOURCE', DB::table('pra_logs')->sole()->payload);
        $this->expectException(\InvalidArgumentException::class);
        $collector->capture(1, 'pra.fiscal', '2026-10-01', (string) Str::uuid());
    }

    public function test_incompatible_observation_schema_does_not_erase_the_independent_source_result(): void
    {
        Schema::drop('diagnostic_source_observations');
        Schema::create('diagnostic_source_observations', fn (Blueprint $t) => $t->id());
        $report = app(DiagnosticSourceEvidence::class)->read(['company_name'=>'Synthetic observed PRA',
            'source'=>'pra.fiscal','date_from'=>'2026-11-06'], [1]);
        $this->assertSame('COLLECTION_READ_FAILED', $report['collection_observation']['status']);
        $this->assertSame('NO_RETAINED_EVENTS', $report['status']);
        $this->assertFalse($report['coverage']['seven_day_complete']);
        $this->assertStringNotContainsString('select', json_encode($report));
    }
}
