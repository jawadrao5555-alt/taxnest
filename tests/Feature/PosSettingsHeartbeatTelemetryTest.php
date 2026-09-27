<?php

namespace Tests\Feature;

use App\Services\PosSettingsSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PosSettingsHeartbeatTelemetryTest extends TestCase
{
    private const OBSERVATIONS = [
        'agent_version' => '1.13.13', 'agent_offline_mode' => '0',
        'agent_update_target' => '1.13.14', 'agent_update_stage' => 'download',
        'agent_update_error' => 'Synthetic update failure',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('agent_enabled')->default(true);
            $table->boolean('agent_submits_pra')->default(true);
            $table->string('pra_connection_mode')->default('agent');
            $table->string('agent_future_policy')->default('manual');
            $table->decimal('pos_tax_rate', 8, 2)->default(16);
            $table->text('feature_flags')->nullable();
            $table->text('pos_printer_settings')->nullable();
            foreach (array_keys(self::OBSERVATIONS) as $column) {
                $table->string($column)->nullable();
            }
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            // Same names on another table must not inherit company exceptions.
            $table->string('agent_version')->nullable();
            $table->string('agent_offline_mode')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->text('pos_custom_access')->nullable();
        });
        foreach (range(1, 4) as $id) {
            DB::table('companies')->insert([
                'id' => $id, 'name' => 'Fictional shop '.$id,
                'feature_flags' => '{"agent_offline_mode":true}',
                'pos_printer_settings' => '{"receipt_printer":"Counter-A","kot_printer":"Kitchen"}',
            ] + self::OBSERVATIONS);
        }
        DB::table('branches')->insert(['id' => 10, 'company_id' => 2, 'agent_version' => 'configured', 'agent_offline_mode' => 'manual']);
        DB::table('users')->insert(['id' => 10, 'company_id' => 2, 'pos_custom_access' => '["sales"]']);
    }

    private function legacyBaseline(): array
    {
        $snapshot = (new PosSettingsSnapshot())->capture();
        foreach ($snapshot['tables']['companies'] as &$row) {
            $row = array_replace($row, self::OBSERVATIONS);
        }
        unset($row);

        return $snapshot;
    }

    private function reportNewVersions(): void
    {
        DB::table('companies')->update(['agent_version' => '1.13.14']);
        DB::table('companies')->where('id', 1)->update(['agent_offline_mode' => '1']);
    }

    public function test_capture_ignores_reported_observations_without_changing_database_values(): void
    {
        $service = new PosSettingsSnapshot();
        $before = $service->capture();
        $this->reportNewVersions();
        // A successful update clears the old attempt telemetry on heartbeat.
        DB::table('companies')->where('id', 2)->update([
            'agent_update_target' => null, 'agent_update_stage' => null, 'agent_update_error' => null,
        ]);
        $stored = DB::table('companies')->orderBy('id')->get()->all();
        $after = $service->capture();
        $this->assertSame([], $service->diff($before, $after)['changed']);
        foreach (array_keys(self::OBSERVATIONS) as $column) {
            $this->assertNotContains($column, $service->settingColumns('companies'));
            $this->assertArrayNotHasKey($column, $after['tables']['companies'][1]);
        }
        foreach (['agent_enabled', 'agent_submits_pra', 'agent_future_policy', 'pra_connection_mode', 'pos_printer_settings'] as $column) {
            $this->assertContains($column, $service->settingColumns('companies'));
        }
        $this->assertEquals($stored, DB::table('companies')->orderBy('id')->get()->all());
        $this->assertSame(['1'], array_map('strval', array_keys($service->capture(1)['tables']['companies'])));
    }

    public function test_retained_four_version_one_offline_report_baseline_needs_no_restore(): void
    {
        $legacy = $this->legacyBaseline();
        $this->reportNewVersions();
        $stored = DB::table('companies')->orderBy('id')->get()->all();
        $service = new PosSettingsSnapshot();
        $after = $service->capture();
        foreach ([[$legacy, $after], [$after, $legacy]] as [$beforeSnapshot, $afterSnapshot]) {
            $diff = $service->diff($beforeSnapshot, $afterSnapshot);
            $this->assertSame([], $diff['changed']);
            $this->assertSame([], $diff['dropped_columns']);
            $this->assertSame([], $diff['new_columns']);
        }
        $path = tempnam(sys_get_temp_dir(), 'tn-heartbeat-');
        $bytes = json_encode($legacy, JSON_THROW_ON_ERROR);
        file_put_contents($path, $bytes);
        try {
            $this->artisan('pos:settings-snapshot', ['--compare' => $path])->assertExitCode(0);
            foreach ([false, true] as $write) {
                $this->artisan('pos:settings-restore', ['--from' => $path, '--write' => $write])
                    ->expectsOutputToContain('Already clean against baseline')->assertExitCode(0);
            }
            $this->assertSame($bytes, file_get_contents($path));
            $this->assertEquals($stored, DB::table('companies')->orderBy('id')->get()->all());
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('protectedChanges')]
    public function test_other_tenant_settings_remain_protected_during_heartbeats(string $column, mixed $value): void
    {
        $before = $this->legacyBaseline();
        $this->reportNewVersions();
        DB::table('companies')->where('id', 2)->update([$column => $value]);
        $service = new PosSettingsSnapshot();
        $after = $service->capture();
        $diff = $service->diff($before, $after);
        $this->assertCount(1, $diff['changed']);
        $this->assertSame('2', $diff['changed'][0]['company_id']);
        $this->assertSame($column, $diff['changed'][0]['column']);
        $plan = $service->planProtectedRestore($before, $after);
        $this->assertFalse($plan['ok']);
        $this->assertSame([], $plan['restore']);
        $this->assertSame('unsupported_column', $plan['refused'][0]['reason']);
    }

    public static function protectedChanges(): array
    {
        return [
            'Agent enabled' => ['agent_enabled', 0],
            'PRA submission policy' => ['agent_submits_pra', 0],
            'PRA connection policy' => ['pra_connection_mode', 'cloud'],
            'future Agent setting' => ['agent_future_policy', 'automatic'],
            'nested observation name' => ['feature_flags', '{"agent_offline_mode":false}'],
            'printer selection' => ['pos_printer_settings', '{"receipt_printer":"Counter-B","kot_printer":"Kitchen"}'],
            'tax rate' => ['pos_tax_rate', 0],
        ];
    }

    public function test_same_named_branch_columns_remain_protected(): void
    {
        $before = $this->legacyBaseline();
        $this->reportNewVersions();
        DB::table('branches')->where('id', 10)->update(['agent_version' => 'changed', 'agent_offline_mode' => 'automatic']);
        $service = new PosSettingsSnapshot();
        $diff = $service->diff($before, $service->capture());
        $this->assertCount(2, $diff['changed']);
        $this->assertSame(['branches'], array_values(array_unique(array_column($diff['changed'], 'table'))));
    }

    public function test_legacy_schema_and_dropped_settings_are_distinguished(): void
    {
        $before = $this->legacyBaseline();
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(array_keys(self::OBSERVATIONS)));
        $service = new PosSettingsSnapshot();
        $this->assertTrue($service->planProtectedRestore($before, $service->capture())['ok']);
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('agent_enabled'));
        $plan = $service->planProtectedRestore($before, $service->capture());
        $this->assertFalse($plan['ok']);
        $this->assertSame('destructive_schema_change', $plan['reason']);
    }

    public function test_existing_permission_restore_does_not_revert_reported_agent_state(): void
    {
        $before = $this->legacyBaseline();
        $this->reportNewVersions();
        DB::table('users')->where('id', 10)->update(['pos_custom_access' => '["sales","service_jobs"]']);
        $stored = DB::table('companies')->orderBy('id')->get()->all();
        $path = tempnam(sys_get_temp_dir(), 'tn-heartbeat-');
        file_put_contents($path, json_encode($before, JSON_THROW_ON_ERROR));
        try {
            $this->artisan('pos:settings-restore', ['--from' => $path, '--write' => true])
                ->expectsOutputToContain('Verified: service_jobs-only rows match the baseline again.')->assertExitCode(0);
            $this->assertSame('["sales"]', DB::table('users')->where('id', 10)->value('pos_custom_access'));
            $this->assertEquals($stored, DB::table('companies')->orderBy('id')->get()->all());
        } finally {
            unlink($path);
        }
    }
}
