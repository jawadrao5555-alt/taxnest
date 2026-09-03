<?php

namespace Tests\Feature;

use App\Models\PosPrintJob;
use App\Services\PrintJobWakePublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrintJobRealtimeWakeTest extends TestCase
{
    private int $companyId;
    private int $otherCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('agent_api_key')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('pos_agent_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('device_uid');
            $table->timestamps();
            $table->unique(['company_id', 'device_uid']);
        });
        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('status')->default('pending');
            $table->string('device_uid')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });

        $now = now();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Wake Shop', 'agent_api_key' => 'wake-key', 'agent_enabled' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Other Shop', 'agent_api_key' => 'other-wake-key', 'agent_enabled' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('pos_agent_devices')->insert([
            'company_id' => $this->companyId, 'device_uid' => 'counter-a',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('pos_agent_devices')->insert([
            'company_id' => $this->otherCompanyId, 'device_uid' => 'other-counter',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        Config::set('print.realtime_gateway_url', null);
        Config::set('print.realtime_gateway_secret', null);
    }

    public function test_realtime_auth_binds_a_registered_device_to_its_authenticated_company(): void
    {
        $this->getJson('/api/agent/realtime-auth?device_uid=counter-a', [
            'Authorization' => 'Bearer wake-key',
        ])->assertOk()->assertExactJson([
            'ok' => true,
            'company_id' => (string) $this->companyId,
            'device_uid' => 'counter-a',
        ]);

        $this->getJson('/api/agent/realtime-auth?device_uid=other-counter', [
            'Authorization' => 'Bearer wake-key',
        ])->assertForbidden();
        $this->getJson('/api/agent/realtime-auth', [
            'Authorization' => 'Bearer wake-key',
        ])->assertStatus(422);
    }

    public function test_publisher_posts_only_the_targeted_wake_payload(): void
    {
        Config::set('print.realtime_gateway_url', 'http://127.0.0.1:4189');
        Config::set('print.realtime_gateway_secret', 'local-secret');
        Http::fake(['http://127.0.0.1:4189/internal/wake' => Http::response(['ok' => true])]);

        app(PrintJobWakePublisher::class)->publish($this->job(44, 'counter-a'));

        Http::assertSent(function ($request) {
            return $request->url() === 'http://127.0.0.1:4189/internal/wake'
                && $request->hasHeader('X-Wake-Secret', 'local-secret')
                && $request->data() === [
                    'company_id' => $this->companyId,
                    'device_uid' => 'counter-a',
                    'job_id' => 44,
                ];
        });
    }

    public function test_disabled_or_failed_wake_never_breaks_print_job_flow(): void
    {
        Http::fake();
        app(PrintJobWakePublisher::class)->publish($this->job(45, 'counter-a'));
        Http::assertNothingSent();

        Config::set('print.realtime_gateway_url', 'http://127.0.0.1:4189');
        Config::set('print.realtime_gateway_secret', 'local-secret');
        Http::fake(['http://127.0.0.1:4189/internal/wake' => Http::response(['error' => 'unavailable'], 503)]);
        app(PrintJobWakePublisher::class)->publish($this->job(46, 'counter-a'));
        Http::assertSentCount(1);
    }

    public function test_model_hook_wakes_after_commit_but_not_after_rollback(): void
    {
        Config::set('print.realtime_gateway_url', 'http://127.0.0.1:4189');
        Config::set('print.realtime_gateway_secret', 'local-secret');
        Http::fake();

        DB::transaction(function () {
            PosPrintJob::create([
                'company_id' => $this->companyId, 'type' => 'bill', 'status' => 'pending',
                'device_uid' => 'counter-a',
            ]);
        });
        Http::assertSentCount(1);

        Http::fake();
        DB::beginTransaction();
        PosPrintJob::create([
            'company_id' => $this->companyId, 'type' => 'bill', 'status' => 'pending',
            'device_uid' => 'counter-a',
        ]);
        DB::rollBack();
        Http::assertNothingSent();
    }

    private function job(int $id, ?string $deviceUid): PosPrintJob
    {
        $job = new PosPrintJob();
        $job->setRawAttributes([
            'id' => $id,
            'company_id' => $this->companyId,
            'device_uid' => $deviceUid,
        ], true);

        return $job;
    }
}