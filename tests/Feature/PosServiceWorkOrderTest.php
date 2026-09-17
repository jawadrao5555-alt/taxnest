<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosService;
use App\Services\PosCategoryProfiles;
use App\Services\PosServiceWorkflowProfiles;
use App\Services\PosServiceWorkOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PosServiceWorkOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->schema();
    }

    public function test_lab1_profiles_are_real_distinct_and_category_relevant(): void
    {
        $this->assertCount(30, PosServiceWorkflowProfiles::PROFILES);
        $nouns = [];
        foreach (PosServiceWorkflowProfiles::PROFILES as $category => $profile) {
            $this->assertTrue(PosCategoryProfiles::has($category), "{$category} must be a registered category");
            $this->assertContains('service_jobs', PosCategoryProfiles::modules($category));
            $this->assertGreaterThanOrEqual(5, count($profile['stages']));
            $this->assertSame(count($profile['stages']), count(array_unique($profile['stages'])));
            $this->assertNotEmpty($profile['terminal']);
            foreach ($profile['terminal'] as $terminal) {
                $this->assertContains($terminal, $profile['stages']);
                $this->assertSame([], PosServiceWorkflowProfiles::nextStatuses($profile, $terminal));
                $this->assertFalse(PosServiceWorkflowProfiles::canTransition($profile, $terminal, 'cancelled'));
            }
            $this->assertNotEmpty($profile['fields']);
            $permissions = PosServiceWorkflowProfiles::permissions($profile);
            $this->assertSame(
                ['create', 'transition', 'invoice', 'report'],
                array_keys($permissions),
                "{$category} must declare every work-order action permission"
            );
            foreach ($permissions as $action => $roles) {
                $this->assertContains('pos_manager', $roles, "{$category}: {$action} must preserve manager access");
                $this->assertContains('pos_cashier', $roles, "{$category}: {$action} must preserve cashier access");
            }
            $nouns[] = $profile['noun'];
        }
        $this->assertGreaterThanOrEqual(8, count(array_unique($nouns)), 'Profiles must not be renamed copies of one generic workflow.');
    }

    public function test_hotel_is_not_rebuilt_as_a_generic_work_order_profile(): void
    {
        $this->assertArrayNotHasKey('hotel', PosServiceWorkflowProfiles::PROFILES);
        $this->assertFalse(PosServiceWorkflowProfiles::supports($this->company('hotel', 'Hotel Lab')));
    }

    public function test_audited_twelve_typed_workflows_remain_present_and_executable(): void
    {
        $audited = [
            'salon', 'laundry', 'workshop', 'courier', 'photography', 'rent_a_car',
            'cargo', 'cleaning', 'repair_service', 'printing', 'equipment_rental', 'tailoring',
        ];
        $this->assertCount(12, $audited);
        foreach ($audited as $category) {
            $profile = PosServiceWorkflowProfiles::PROFILES[$category] ?? null;
            $this->assertNotNull($profile, "{$category} must retain its typed workflow");
            $this->assertNotEmpty($profile['fields']);
            $this->assertNotEmpty($profile['terminal']);
        }
    }

    #[DataProvider('operationalProfileProvider')]
    public function test_every_implemented_category_creates_its_native_first_stage(
        string $category,
        string $prefix,
        string $firstStage
    ): void {
        $company = $this->company($category, ucfirst(str_replace('_', ' ', $category)).' Lab');
        $profile = PosServiceWorkflowProfiles::forCompany($company);
        $detailKey = array_key_first($profile['fields']);
        $input = [
            'customer_name' => 'Fictional Customer',
            'quantity' => 1,
            'unit_price' => 250,
            'details' => [$detailKey => 'Fictional detail', 'foreign' => 'drop'],
        ];
        foreach (PosServiceWorkflowProfiles::requiredFields($profile) as $required) {
            $input['details'][$required] = 'Required fictional detail';
        }
        if ($profile['schedule']) {
            $input['scheduled_at'] = now()->addHour();
        }

        $job = app(PosServiceWorkOrderService::class)->create($company, $input, 1, null);

        $this->assertSame($prefix.'-000001', $job->job_number);
        $this->assertSame($firstStage, $job->status);
        $expectedDetails = [$detailKey => 'Fictional detail'];
        foreach (PosServiceWorkflowProfiles::requiredFields($profile) as $required) {
            $expectedDetails[$required] = 'Required fictional detail';
        }
        $this->assertSame($expectedDetails, $job->details);
        $this->assertSame(1, $job->events()->count());
    }

    public static function operationalProfileProvider(): array
    {
        $cases = [];
        foreach (PosServiceWorkflowProfiles::PROFILES as $category => $profile) {
            $cases[$category] = [$category, $profile['prefix'], $profile['stages'][0]];
        }

        return $cases;
    }

    #[DataProvider('operationalProfileProvider')]
    public function test_every_typed_profile_completes_its_declared_lifecycle(
        string $category,
        string $prefix,
        string $firstStage
    ): void {
        $company = $this->company($category, ucfirst(str_replace('_', ' ', $category)).' Lifecycle Lab');
        $profile = PosServiceWorkflowProfiles::forCompany($company);
        $details = [];
        foreach (PosServiceWorkflowProfiles::requiredFields($profile) as $field) {
            $details[$field] = 'Required fictional detail';
        }
        $input = [
            'customer_name' => 'Fictional Customer',
            'quantity' => 1,
            'unit_price' => 250,
            'details' => $details,
        ];
        if ($profile['schedule']) {
            $input['scheduled_at'] = now()->addHour();
        }

        $jobs = app(PosServiceWorkOrderService::class);
        $job = $jobs->create($company, $input, 9, null);
        while (! in_array($job->status, $profile['terminal'], true)) {
            $next = PosServiceWorkflowProfiles::nextStatuses($profile, $job->status);
            $this->assertNotEmpty($next, "{$category} must lead to a terminal state");
            $job = $jobs->transition($company, $job->id, $next[0], null, null, 9);
        }

        $this->assertSame($profile['terminal'][0], $job->status);
        $this->assertNotNull($job->completed_at);
        $this->assertFalse(PosServiceWorkflowProfiles::canTransition($profile, $job->status, 'cancelled'));
    }

    public function test_remaining_service_categories_require_their_declared_operational_fields(): void
    {
        foreach ([
            'gym', 'event_management', 'travel_agent', 'property_dealer', 'advertising', 'it_services',
            'security_services', 'clinic', 'education', 'consultant', 'architect', 'construction',
            'manpower', 'warehouse', 'media_production', 'entertainment', 'financial_services', 'other_service',
        ] as $category) {
            $company = $this->company($category, $category.' Required Field Lab');
            $profile = PosServiceWorkflowProfiles::forCompany($company);
            $this->assertNotEmpty(PosServiceWorkflowProfiles::requiredFields($profile), "{$category} needs operational fields");

            try {
                app(PosServiceWorkOrderService::class)->create($company, [
                    'customer_name' => 'Fictional Customer', 'quantity' => 1, 'unit_price' => 1,
                ], null, null);
                $this->fail("{$category} accepted a record without its required workflow fields.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('required', strtolower($e->getMessage()));
            }
        }
    }

    public function test_workflow_role_policy_allows_standard_operational_roles_but_not_confined_roles(): void
    {
        $profile = PosServiceWorkflowProfiles::PROFILES['event_management'];
        $cashier = new \App\Models\User(['role' => 'staff', 'pos_role' => 'pos_cashier']);
        $manager = new \App\Models\User(['role' => 'staff', 'pos_role' => 'pos_manager']);
        $waiter = new \App\Models\User(['role' => 'staff', 'pos_role' => 'pos_waiter']);
        $owner = new \App\Models\User(['role' => 'company_admin', 'pos_role' => 'pos_admin']);

        foreach (['create', 'transition', 'invoice', 'report'] as $action) {
            $this->assertTrue(PosServiceWorkflowProfiles::allows($profile, $cashier, $action));
            $this->assertTrue(PosServiceWorkflowProfiles::allows($profile, $manager, $action));
            $this->assertTrue(PosServiceWorkflowProfiles::allows($profile, $owner, $action));
            $this->assertFalse(PosServiceWorkflowProfiles::allows($profile, $waiter, $action));
        }
    }

    public function test_lab2_laundry_job_uses_native_lifecycle_safe_details_and_immutable_timeline(): void
    {
        $company = $this->company('laundry', 'Laundry Lab');
        $service = PosService::create(['company_id' => $company->id, 'name' => 'Dry clean', 'price' => 500, 'tax_rate' => 16, 'is_active' => true]);
        $jobs = app(PosServiceWorkOrderService::class);
        $job = $jobs->create($company, [
            'customer_name' => 'Fictional Customer', 'service_id' => $service->id,
            'scheduled_at' => now()->addHour(), 'quantity' => 3, 'unit_price' => 500,
            'details' => ['pieces' => '3', 'care' => 'Dry clean', 'untrusted_extra' => 'must drop'],
        ], 10, null);

        $this->assertSame('LND-000001', $job->job_number);
        $this->assertSame('received', $job->status);
        $this->assertSame('1500.00', $job->total_amount);
        $this->assertSame(['pieces' => '3', 'care' => 'Dry clean'], $job->details);
        $this->assertSame(1, $job->events()->count());

        $jobs->transition($company, $job->id, 'tagged', 'Tag 41', null);
        $this->assertSame('tagged', $job->fresh()->status);
        $this->assertSame(2, $job->events()->count());
        $this->assertSame(['received', 'tagged'], $job->events()->pluck('to_status')->all());
    }

    public function test_workflow_refuses_skipped_and_backward_transitions(): void
    {
        $company = $this->company('workshop', 'Workshop Lab');
        $job = app(PosServiceWorkOrderService::class)->create($company, [
            'customer_name' => 'Fictional Driver', 'quantity' => 1, 'unit_price' => 1000,
            'details' => ['asset' => 'Test car'],
        ], null, null);

        $this->expectException(\InvalidArgumentException::class);
        app(PosServiceWorkOrderService::class)->transition($company, $job->id, 'ready', null, null);
    }

    public function test_terminal_stage_stamps_completion_and_refuses_later_changes(): void
    {
        $company = $this->company('salon', 'Salon Lab');
        $jobs = app(PosServiceWorkOrderService::class);
        $job = $jobs->create($company, [
            'customer_name' => 'Fictional Client', 'scheduled_at' => now(),
            'quantity' => 1, 'unit_price' => 1500,
        ], 1, null);
        foreach (['checked_in', 'in_service', 'completed'] as $status) {
            $job = $jobs->transition($company, $job->id, $status, null, null, 1);
        }

        $this->assertNotNull($job->completed_at);
        $this->assertFalse(PosServiceWorkflowProfiles::canTransition(
            PosServiceWorkflowProfiles::forCompany($company), 'completed', 'cancelled'
        ));
    }

    public function test_courier_can_finish_as_delivered_or_returned_without_linear_state_leak(): void
    {
        $profile = PosServiceWorkflowProfiles::PROFILES['courier'];
        $this->assertEqualsCanonicalizing(
            ['delivered', 'returned'],
            PosServiceWorkflowProfiles::nextStatuses($profile, 'out_for_delivery')
        );
        $this->assertTrue(PosServiceWorkflowProfiles::canTransition($profile, 'out_for_delivery', 'returned'));
        $this->assertFalse(PosServiceWorkflowProfiles::canTransition($profile, 'delivered', 'returned'));
    }

    public function test_tenant_scope_prevents_cross_company_transition(): void
    {
        $owner = $this->company('repair_service', 'Repair A');
        $foreign = $this->company('repair_service', 'Repair B');
        $job = app(PosServiceWorkOrderService::class)->create($owner, [
            'customer_name' => 'Fictional Owner', 'quantity' => 1, 'unit_price' => 200,
        ], 5, null);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(PosServiceWorkOrderService::class)->transition($foreign, $job->id, 'diagnosing', null, null);
    }

    public function test_number_series_is_company_isolated(): void
    {
        $a = $this->company('courier', 'Courier A');
        $b = $this->company('courier', 'Courier B');
        $jobs = app(PosServiceWorkOrderService::class);
        $input = ['customer_name' => 'Fictional', 'scheduled_at' => now(), 'quantity' => 1, 'unit_price' => 100];
        $this->assertSame('CRR-000001', $jobs->create($a, $input, 1, null)->job_number);
        $this->assertSame('CRR-000002', $jobs->create($a, $input, 1, null)->job_number);
        $this->assertSame('CRR-000001', $jobs->create($b, $input, 2, null)->job_number);
    }

    public function test_branch_scoped_transition_refuses_a_foreign_branch_job(): void
    {
        $company = $this->company('cleaning', 'Cleaning Lab');
        $job = app(PosServiceWorkOrderService::class)->create($company, [
            'customer_name' => 'Fictional Site', 'scheduled_at' => now(),
            'quantity' => 1, 'unit_price' => 1200,
        ], 20, null);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(PosServiceWorkOrderService::class)->transition(
            $company, $job->id, 'team_assigned', null, null, 21
        );
    }

    public function test_service_jobs_migration_does_not_rewrite_saved_custom_access(): void
    {
        DB::table('users')->insert([
            ['id' => 1, 'pos_custom_access' => json_encode(['orders']), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'pos_custom_access' => json_encode(['orders', 'service_jobs']), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'pos_custom_access' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $legacy = require database_path('migrations/2026_09_15_011000_backfill_service_jobs_custom_access.php');
        $policy = require database_path('migrations/2026_09_15_013000_service_jobs_custom_access_no_silent_rewrite.php');
        $legacy->up();
        $policy->up();
        $this->assertSame(['orders'], json_decode(DB::table('users')->where('id', 1)->value('pos_custom_access'), true));
        $this->assertSame(['orders', 'service_jobs'], json_decode(DB::table('users')->where('id', 2)->value('pos_custom_access'), true));
        $this->assertNull(DB::table('users')->where('id', 3)->value('pos_custom_access'));
        $legacy->down();
        $policy->down();
        $this->assertSame(['orders'], json_decode(DB::table('users')->where('id', 1)->value('pos_custom_access'), true));
        $this->assertSame(['orders', 'service_jobs'], json_decode(DB::table('users')->where('id', 2)->value('pos_custom_access'), true));
    }

    private function company(string $category, string $name): Company
    {
        $id = DB::table('companies')->insertGetId([
            'name' => $name, 'product_type' => 'pos', 'business_category' => $category,
            'pos_type' => $category, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Company::findOrFail($id);
    }

    private function schema(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('business_category')->nullable();
            $table->string('pos_type')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pos_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_tax_exempt')->default(false);
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->json('pos_custom_access')->nullable();
            $table->timestamps();
        });
        $migration = require database_path('migrations/2026_09_15_010000_create_pos_service_work_orders.php');
        $migration->up();
    }
}
