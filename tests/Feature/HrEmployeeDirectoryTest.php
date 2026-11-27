<?php

namespace Tests\Feature;

use App\Models\HrOrganization;
use App\Models\HrOrganizationMembership;
use App\Models\User;
use App\Services\HrEmployeeDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrEmployeeDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_tenant_access_is_rejected_even_for_an_owner(): void
    {
        $first = HrOrganization::create(['name' => 'Fictional First']);
        $second = HrOrganization::create(['name' => 'Fictional Second']);
        $user = User::factory()->create();
        HrOrganizationMembership::create(['organization_id'=>$first->id,'user_id'=>$user->id,'role'=>'owner','active'=>true]);
        $directory = app(HrEmployeeDirectory::class);
        $employee = $directory->create($first->id,$user->id,['employee_number'=>'A001','full_name'=>'Example Employee']);
        $this->assertSame(1, $directory->list($first->id,$user->id)->total());
        $this->expectException(AuthorizationException::class);
        $directory->find($second->id,$user->id,$employee->id);
    }

    public function test_inactive_membership_cannot_write_employees(): void
    {
        $organization = HrOrganization::create(['name'=>'Fictional HR']);
        $user = User::factory()->create();
        HrOrganizationMembership::create(['organization_id'=>$organization->id,'user_id'=>$user->id,'role'=>'owner','active'=>false]);
        $this->expectException(AuthorizationException::class);
        app(HrEmployeeDirectory::class)->create($organization->id,$user->id,['employee_number'=>'A001','full_name'=>'Example Employee']);
    }
}
