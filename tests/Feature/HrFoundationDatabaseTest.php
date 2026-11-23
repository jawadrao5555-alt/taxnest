<?php

namespace Tests\Feature;

use App\Models\HrEmployee;
use App\Models\HrOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrFoundationDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_employees_are_isolated_by_organization(): void
    {
        $a = HrOrganization::create(['name' => 'Fictional Alpha']);
        $b = HrOrganization::create(['name' => 'Fictional Beta']);
        HrEmployee::create(['organization_id'=>$a->id,'employee_number'=>'E001','full_name'=>'Sample Person']);
        HrEmployee::create(['organization_id'=>$b->id,'employee_number'=>'E001','full_name'=>'Another Sample']);
        $this->assertSame(1, $a->employees()->count());
        $this->assertSame(1, $b->employees()->count());
        $this->assertSame('Sample Person', $a->employees()->first()->full_name);
    }

    public function test_policy_versions_are_organization_scoped(): void
    {
        $a = HrOrganization::create(['name'=>'Fictional Company']);
        $a->policyVersions()->create(['version'=>1,'effective_from'=>'2026-11-23','rules'=>['grace_minutes'=>5]]);
        $this->assertSame(5, $a->policyVersions()->first()->rules['grace_minutes']);
    }
}
