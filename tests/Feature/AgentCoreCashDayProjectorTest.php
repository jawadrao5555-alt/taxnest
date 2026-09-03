<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\AgentCoreEvent;
use App\Models\Company;
use App\Models\PosDayCloseReport;
use App\Services\AgentCoreCashDayProjector;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AgentCoreCashDayProjectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 14:00:00', 'Asia/Karachi'));
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('pos_business_day_cutoff')->default('06:00');
            $t->softDeletes(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->timestamps();
        });
        Schema::create('pos_terminals', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('terminal_name');
            $t->string('terminal_code'); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('pos_agent_devices', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('device_uid');
            $t->unsignedBigInteger('terminal_id')->nullable(); $t->timestamps();
        });
        Schema::create('pos_day_openings', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->default(0);
            $t->unsignedBigInteger('terminal_id')->default(0); $t->date('business_date');
            $t->decimal('opening_cash', 14, 2); $t->unsignedBigInteger('entered_by')->nullable();
            $t->text('notes')->nullable(); $t->timestamps();
            $t->unique(['company_id', 'branch_id', 'terminal_id', 'business_date']);
        });
        Schema::create('pos_day_close_reports', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->default(0);
            $t->date('report_date'); $t->string('report_number'); $t->decimal('opening_float', 14, 2)->nullable();
            $t->decimal('counted_cash', 14, 2)->nullable(); $t->timestamps();
            $t->unique(['company_id', 'branch_id', 'report_date']);
        });
        Schema::create('pos_counter_closes', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->default(0);
            $t->unsignedBigInteger('terminal_id'); $t->date('business_date'); $t->timestamps();
        });
        Schema::create('pos_cash_expenses', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->default(0);
            $t->unsignedBigInteger('terminal_id')->default(0); $t->date('business_date');
            $t->decimal('amount', 14, 2); $t->string('idempotency_key');
            $t->unsignedBigInteger('recorded_by'); $t->text('notes')->nullable();
            $t->dateTime('occurred_at')->nullable(); $t->timestamps();
            $t->unique(['company_id', 'idempotency_key']);
        });
        Schema::create('pos_user_sessions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id');
            $t->dateTime('login_at'); $t->dateTime('logout_at')->nullable();
            $t->dateTime('last_activity_at')->nullable(); $t->string('ip', 45)->nullable(); $t->timestamps();
        });

        foreach ([1, 2] as $companyId) {
            DB::table('companies')->insert(['id' => $companyId, 'name' => "Company {$companyId}",
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('users')->insert(['id' => $companyId * 10, 'company_id' => $companyId,
                'name' => "User {$companyId}", 'created_at' => now(), 'updated_at' => now()]);
            DB::table('branches')->insert(['id' => $companyId * 100, 'company_id' => $companyId,
                'name' => "Branch {$companyId}", 'created_at' => now(), 'updated_at' => now()]);
            DB::table('pos_terminals')->insert(['id' => $companyId * 1000, 'company_id' => $companyId,
                'terminal_name' => "Counter {$companyId}", 'terminal_code' => "C{$companyId}",
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('pos_agent_devices')->insert(['company_id' => $companyId, 'device_uid' => "device-{$companyId}",
                'terminal_id' => $companyId * 1000, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_opening_uses_six_am_business_day_and_is_today_only_and_drawer_scoped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 02:00:00', 'Asia/Karachi'));
        $projector = $this->projector();

        $outcome = $projector->project($this->company(), $this->stored('open-1', 'cash.opened'), $this->wire(
            'cash.open', 'drawer-1', ['business_date' => '2026-10-05', 'opening_cents' => 12345, 'terminal_id' => 1000]
        ));
        $this->assertSame('projected', $outcome->status);
        $opening = DB::table('pos_day_openings')->first();
        $this->assertSame(1, (int) $opening->company_id);
        $this->assertSame(100, (int) $opening->branch_id);
        $this->assertSame(1000, (int) $opening->terminal_id);
        $this->assertSame('2026-10-05', substr($opening->business_date, 0, 10));
        $this->assertSame(123.45, (float) $opening->opening_cash);
        $replay = $projector->project($this->company(), $this->stored('open-1', 'cash.opened'), $this->wire(
            'cash.open', 'drawer-1', ['business_date' => '2026-10-05', 'opening_cents' => 12345, 'terminal_id' => 1000]
        ));
        $this->assertSame('projected', $replay->status);
        $this->assertSame(1, DB::table('pos_day_openings')->count());

        $wrong = $projector->project($this->company(), $this->stored('open-2', 'cash.opened'), $this->wire(
            'cash.open', 'drawer-2', ['business_date' => '2026-10-06', 'opening_cents' => 100, 'terminal_id' => 1000]
        ));
        $this->assertSame('rejected', $wrong->status);
    }

    public function test_opening_and_expense_reject_a_closed_branch_day_without_touching_rows(): void
    {
        DB::table('pos_day_close_reports')->insert([
            'company_id' => 1, 'branch_id' => 100, 'report_date' => '2026-10-06',
            'report_number' => 'ZRPT-1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $projector = $this->projector();
        $open = $projector->project($this->company(), $this->stored('open-locked', 'cash.opened'), $this->wire(
            'cash.open', 'drawer-locked', ['business_date' => '2026-10-06', 'opening_cents' => 500]
        ));
        $expense = $projector->project($this->company(), $this->stored('expense-locked', 'expense.recorded'), $this->wire(
            'cash.expense', 'expense-locked', ['business_date' => '2026-10-06', 'amount_cents' => 500]
        ));

        $this->assertSame('rejected', $open->status);
        $this->assertSame('rejected', $expense->status);
        $this->assertSame(0, DB::table('pos_day_openings')->count());
        $this->assertSame(0, DB::table('pos_cash_expenses')->count());
    }

    public function test_expense_is_idempotent_and_company_branch_counter_scoped(): void
    {
        $projector = $this->projector();
        $event = $this->stored('expense-1', 'expense.recorded');
        $wire = $this->wire('cash.expense', 'expense-aggregate', [
            'business_date' => '2026-10-06', 'amount_cents' => 725, 'terminal_id' => 1000, 'note' => 'Tea',
        ]);
        $first = $projector->project($this->company(), $event, $wire);
        $second = $projector->project($this->company(), $event, $wire);

        $this->assertSame('projected', $first->status);
        $this->assertFalse($first->result['replayed']);
        $this->assertTrue($second->result['replayed']);
        $this->assertSame(1, DB::table('pos_cash_expenses')->count());
        $this->assertDatabaseHas('pos_cash_expenses', [
            'company_id' => 1, 'branch_id' => 100, 'terminal_id' => 1000, 'amount' => 7.25,
        ]);
    }

    public function test_cross_company_branch_counter_or_user_scope_is_terminally_rejected(): void
    {
        $projector = $this->projector();
        foreach ([
            ['branch_id' => '200'],
            ['user_id' => '20'],
            ['data' => ['terminal_id' => 2000]],
        ] as $index => $override) {
            $data = ['business_date' => '2026-10-06', 'opening_cents' => 100]
                + ($override['data'] ?? []);
            unset($override['data']);
            $wire = $this->wire('cash.open', 'bad-' . $index, $data, $override);
            $outcome = $projector->project($this->company(), $this->stored('bad-' . $index, 'cash.opened'), $wire);
            $this->assertSame('rejected', $outcome->status);
        }
    }

    public function test_day_close_delegates_to_shared_close_engine_and_preserves_null_versus_zero(): void
    {
        foreach ([null, 0] as $index => $countedCents) {
            $date = $index === 0 ? '2026-10-05' : '2026-10-06';
            $report = new PosDayCloseReport([
                'company_id' => 1, 'branch_id' => 100, 'report_date' => $date,
                'report_number' => 'ZRPT-' . $index, 'counted_cash' => $countedCents,
            ]);
            $report->id = 80 + $index;
            $controller = Mockery::mock(PosController::class);
            $controller->shouldReceive('performDayClose')->once()->withArgs(function (
                $company, $actualDate, $user, $notes, $recon, $allowEmpty, $actions, $branch
            ) use ($date, $countedCents): bool {
                $this->assertSame(1, $company);
                $this->assertSame($date, $actualDate);
                $this->assertSame(10, $user);
                $this->assertSame(100, $branch);
                $this->assertFalse($allowEmpty);
                $this->assertArrayNotHasKey('opening_float', $recon);
                $this->assertSame($countedCents === null ? null : 0.0, $recon['counted_cash']);
                return true;
            })->andReturn(['status' => 'created', 'report' => $report, 'report_number' => $report->report_number]);

            $data = ['business_date' => $date, 'counted_cents' => $countedCents];
            $outcome = (new AgentCoreCashDayProjector($controller))->project(
                $this->company(), $this->stored('close-' . $index, 'day.closed'),
                $this->wire('cash.close', 'close-' . $index, $data)
            );
            $this->assertSame('projected', $outcome->status);
        }
    }

    public function test_existing_close_is_immutable_and_replayed_without_running_close_again(): void
    {
        DB::table('pos_day_close_reports')->insert([
            'id' => 91, 'company_id' => 1, 'branch_id' => 100, 'report_date' => '2026-10-06',
            'report_number' => 'ZRPT-EXISTING', 'counted_cash' => 44,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $controller = Mockery::mock(PosController::class);
        $controller->shouldNotReceive('performDayClose');
        $outcome = (new AgentCoreCashDayProjector($controller))->project(
            $this->company(), $this->stored('close-replay', 'day.closed'),
            $this->wire('cash.close', 'close-replay', ['business_date' => '2026-10-06', 'counted_cents' => 0])
        );

        $this->assertSame('projected', $outcome->status);
        $this->assertTrue($outcome->result['replayed']);
        $this->assertSame(44.0, (float) DB::table('pos_day_close_reports')->value('counted_cash'));
    }

    public function test_staff_session_start_and_end_are_idempotent_and_tenant_scoped(): void
    {
        $projector = $this->projector();
        $start = $this->wire('staff.start', 'session-A', ['user_id' => 10]);
        $first = $projector->project($this->company(), $this->stored('staff-start', 'staff.session.started'), $start);
        $again = $projector->project($this->company(), $this->stored('staff-start-2', 'staff.session.started'), $start);
        $end = $projector->project($this->company(), $this->stored('staff-end', 'staff.session.ended'),
            $this->wire('staff.end', 'session-A', ['user_id' => 10]));
        $endAgain = $projector->project($this->company(), $this->stored('staff-end-2', 'staff.session.ended'),
            $this->wire('staff.end', 'session-A', ['user_id' => 10]));

        $this->assertSame('projected', $first->status);
        $this->assertTrue($again->result['replayed']);
        $this->assertSame('projected', $end->status);
        $this->assertSame('projected', $endAgain->status);
        $this->assertSame(1, DB::table('pos_user_sessions')->count());
        $this->assertNotNull(DB::table('pos_user_sessions')->value('logout_at'));
    }

    public function test_missing_expense_schema_is_retryable_not_falsely_projected(): void
    {
        Schema::drop('pos_cash_expenses');
        $outcome = $this->projector()->project(
            $this->company(), $this->stored('expense-wait', 'expense.recorded'),
            $this->wire('cash.expense', 'expense-wait', ['business_date' => '2026-10-06', 'amount_cents' => 100])
        );
        $this->assertSame('retryable', $outcome->status);
        $this->assertSame('pos_cash_expenses', $outcome->dependency);
    }

    private function projector(): AgentCoreCashDayProjector
    {
        return new AgentCoreCashDayProjector(Mockery::mock(PosController::class));
    }

    private function company(): Company
    {
        return Company::query()->findOrFail(1);
    }

    private function stored(string $id, string $type): AgentCoreEvent
    {
        return new AgentCoreEvent([
            'company_id' => 1, 'device_uid' => 'device-1', 'event_id' => $id,
            'idempotency_key' => 'idem-' . $id, 'event_type' => $type,
            'occurred_at' => now(),
        ]);
    }

    private function wire(string $command, string $aggregate, array $data, array $scope = []): array
    {
        return [
            'payload' => [
                'schema' => 'local-core.test.v1',
                'command_type' => $command,
                'aggregate_id' => $aggregate,
                'aggregate_revision' => 1,
                'data' => $data,
            ],
            'scope' => $scope + [
                'company_id' => '1', 'branch_id' => '100',
                'device_id' => 'device-1', 'user_id' => '10',
            ],
        ];
    }
}