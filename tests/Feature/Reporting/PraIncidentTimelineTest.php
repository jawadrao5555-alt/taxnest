<?php

namespace Tests\Feature\Reporting;

use App\Models\PosPrintJob;
use App\Services\PrintJobEvidence;
use App\Services\Reporting\PraIncidentTimeline;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PraIncidentTimelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        config(['app.timezone' => 'Asia/Karachi']);
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type'); $t->softDeletes();
        });
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number'); $t->string('status'); $t->timestamps();
        });
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('pos_transaction_id')->nullable(); $t->timestamps();
        });
        Schema::create('pos_print_jobs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('transaction_id')->nullable();
            $t->unsignedBigInteger('restaurant_order_id')->nullable(); $t->string('type'); $t->string('status');
            $t->unsignedInteger('attempts')->default(0); $t->string('claim_token')->nullable();
            $t->text('error')->nullable(); $t->string('device_uid')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_10_03_170000_add_print_job_evidence.php'))->up();
        (require database_path('migrations/2026_12_21_090000_add_print_evidence_correlation.php'))->up();
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Fictional PRA', 'product_type' => 'pos'],
            ['id' => 2, 'name' => 'Other PRA', 'product_type' => 'pos'],
            ['id' => 3, 'name' => 'Fictional FBR', 'product_type' => 'fbrpos'],
        ]);
        DB::table('pos_transactions')->insert(['id' => 10, 'company_id' => 1, 'branch_id' => 7,
            'invoice_number' => 'L010', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function args(array $extra = []): array
    {
        return $extra + ['company_name' => 'Fictional PRA', 'date_from' => '2026-10-30'];
    }

    public function test_real_job_creation_and_result_survive_job_purge_with_redacted_bill_correlation(): void
    {
        $job = PosPrintJob::create(['company_id' => 1, 'transaction_id' => 10, 'type' => 'kot', 'status' => 'pending']);
        PrintJobEvidence::record(1, 'result_received', $job->id, 1, ['outcome' => 'spool_accepted',
            'device_uid' => 'secret-host-name', 'claim_token' => 'SECRET', 'error' => 'buyer phone SECRET']);
        DB::table('pos_print_jobs')->where('id', $job->id)->delete();
        $r = app(PraIncidentTimeline::class)->read($this->args(['bill_id' => 10]), [1]);
        $this->assertCount(2, $r['events']);
        $this->assertSame(['enqueued', 'result_received'], array_column($r['events'], 'event'));
        $this->assertSame(10, $r['events'][1]['bill_id']);
        $this->assertSame('spool_accepted', $r['events'][1]['details']['outcome']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
        $this->assertStringNotContainsString('secret-host-name', json_encode($r));
        $this->assertFalse($r['coverage']['complete']);
        $this->assertSame('unknown', $r['physical_print']);
    }

    public function test_scopes_pagination_cross_midnight_and_unsupported_inputs(): void
    {
        $job = PosPrintJob::create(['company_id' => 1, 'transaction_id' => 10, 'type' => 'kot', 'status' => 'pending']);
        PrintJobEvidence::record(1, 'claimed', $job->id, 1);
        PrintJobEvidence::record(2, 'result_received', $job->id, 1, ['outcome' => 'spool_accepted']);
        DB::table('pos_print_evidence')->where('event', 'enqueued')->update(['received_at' => '2026-10-29 23:59:59']);
        $r = app(PraIncidentTimeline::class)->read($this->args(['date_from' => '2026-10-29', 'date_to' => '2026-10-30', 'branch_id' => 7, 'limit' => 1]), [1]);
        $this->assertCount(1, $r['events']); $this->assertTrue($r['pagination']['has_more']);
        $next = app(PraIncidentTimeline::class)->read($this->args(['date_from' => '2026-10-29', 'date_to' => '2026-10-30', 'after_id' => $r['pagination']['next_after_id']]), [1]);
        $this->assertCount(1, $next['events']); $this->assertSame('claimed', $next['events'][0]['event']);
        $this->assertSame(2, $r['observed']['matching_events']);
        $this->assertStringContainsString('+05:00', $r['events'][0]['received_at']);
        $this->assertCount(0, app(PraIncidentTimeline::class)->read($this->args(['branch_id' => 999]), [1])['events']);
        foreach ([[$this->args(), [2]], [$this->args(['company_name' => 'Fictional FBR']), null],
            [$this->args(['date_from' => '2026-10-20', 'date_to' => '2026-10-30']), [1]], [$this->args(['date_basis' => 'business']), [1]],
            [$this->args(['limit' => 51]), [1]]] as [$args, $scope]) {
            try { app(PraIncidentTimeline::class)->read($args, $scope); $this->fail('Invalid scope/filter accepted'); }
            catch (ValidationException $expected) { $this->assertNotEmpty($expected->errors()); }
        }
    }

    public function test_retention_keeps_seven_days_and_boundary_with_bounded_cleanup_and_no_job_changes(): void
    {
        $job = PosPrintJob::create(['company_id' => 1, 'transaction_id' => 10, 'type' => 'kot', 'status' => 'pending']);
        foreach ([7, 30, 31, 32] as $days) {
            DB::table('pos_print_evidence')->insert(['company_id' => 1, 'event' => 'claimed', 'context' => '{}', 'received_at' => now()->subDays($days)]);
        }
        $before = DB::table('pos_print_jobs')->get()->toJson();
        $this->assertSame(1, PrintJobEvidence::prune(1));
        $this->assertSame(1, PrintJobEvidence::prune(1000));
        $this->assertSame(0, PrintJobEvidence::prune());
        $this->assertSame(3, DB::table('pos_print_evidence')->count());
        $this->assertSame($before, DB::table('pos_print_jobs')->get()->toJson());
        $this->assertNotNull(DB::table('pos_print_evidence_health')->where('company_id', 1)->value('first_recorded_at'));
        (require database_path('migrations/2026_12_21_090000_add_print_evidence_correlation.php'))->up();
        $this->assertSame(3, DB::table('pos_print_evidence')->count());
    }

    public function test_legacy_rows_unavailable_schema_and_failures_are_not_healthy_zeroes(): void
    {
        DB::table('pos_print_evidence')->insert(['company_id' => 1, 'event' => 'claimed', 'context' => '{}', 'received_at' => now()]);
        $r = app(PraIncidentTimeline::class)->read($this->args(), [1]);
        $this->assertSame(1, $r['observed']['legacy_unattributed_events_in_company_period']);
        $this->assertSame('partial', $r['coverage']['status']);
        Schema::drop('pos_print_evidence');
        PrintJobEvidence::record(1, 'claimed', 1, 1);
        $r = app(PraIncidentTimeline::class)->read($this->args(), [1]);
        $this->assertSame('unavailable', $r['coverage']['status']);
        $this->assertNull($r['observed']);
        $this->assertSame(1, $r['coverage']['recording_failure_count']);
    }

    public function test_rolled_back_jobs_leave_no_phantom_event_or_collection_health(): void
    {
        DB::beginTransaction();
        PosPrintJob::create(['company_id' => 1, 'transaction_id' => 10, 'type' => 'kot', 'status' => 'pending']);
        DB::rollBack();
        $this->assertSame(0, DB::table('pos_print_jobs')->count());
        $this->assertSame(0, DB::table('pos_print_evidence')->count());
        $this->assertSame(0, DB::table('pos_print_evidence_health')->count());
    }

    public function test_utc_storage_uses_requested_local_calendar_boundaries(): void
    {
        config(['app.timezone' => 'UTC', 'reporting_connector.timezone' => 'Asia/Karachi']);
        foreach (['2026-10-29 18:59:59', '2026-10-29 19:00:00', '2026-10-30 18:59:59', '2026-10-30 19:00:00'] as $date) {
            DB::table('pos_print_evidence')->insert(['company_id' => 1, 'product_type' => 'pos', 'event' => 'claimed', 'context' => '{}', 'received_at' => $date]);
        }
        $r = app(PraIncidentTimeline::class)->read($this->args(), [1]);
        $this->assertCount(2, $r['events']);
        $this->assertSame('2026-10-30T00:00:00+05:00', $r['events'][0]['received_at']);
        $this->assertSame('2026-10-30T23:59:59+05:00', $r['events'][1]['received_at']);
    }

    public function test_file_log_failure_does_not_silence_database_evidence(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('info')->once()->andThrow(new \RuntimeException('Fictional full log disk'));
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->zeroOrMoreTimes();
        PrintJobEvidence::record(1, 'claimed', null, 1);
        $this->assertSame(1, DB::table('pos_print_evidence')->where('company_id', 1)->count());
    }

    public function test_pre_bill_kot_keeps_observed_order_link_after_order_and_job_deletion(): void
    {
        DB::table('restaurant_orders')->insert(['id' => 20, 'company_id' => 1, 'branch_id' => 7]);
        $job = PosPrintJob::create(['company_id' => 1, 'restaurant_order_id' => 20, 'type' => 'kot', 'status' => 'pending']);
        $order = \App\Models\RestaurantOrder::findOrFail(20);
        $order->update(['pos_transaction_id' => 10]);
        DB::table('restaurant_orders')->where('id', 20)->delete();
        DB::table('pos_print_jobs')->where('id', $job->id)->delete();
        $r = app(PraIncidentTimeline::class)->read($this->args(['bill_id' => 10]), [1]);
        $this->assertSame(['enqueued', 'order_bill_linked'], array_column($r['events'], 'event'));
        $this->assertNull($r['events'][0]['bill_id']);
        $this->assertSame(20, $r['events'][0]['order_id']);
        $this->assertSame(10, $r['events'][1]['bill_id']);
    }
}
