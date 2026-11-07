<?php

namespace Tests\Feature;

use App\Models\{Company, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use Tests\TestCase;

class OnboardingCompletionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_setup_cannot_be_marked_complete_by_direct_post(): void
    {
        Mail::fake(); Http::preventStrayRequests();
        $company = Company::create(['name' => 'Synthetic setup', 'ntn' => 'RCSETUP1', 'product_type' => 'di',
            'status' => 'approved', 'company_status' => 'active', 'onboarding_completed' => false]);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);
        $this->actingAs($owner)->post('/onboarding/complete')->assertRedirect('/onboarding')->assertSessionHasErrors('setup');
        $this->assertFalse((bool) $company->fresh()->onboarding_completed);
        Http::assertNothingSent();
    }

    public function test_employee_cannot_finish_or_skip_company_setup_and_owner_skip_remains_available(): void
    {
        Mail::fake(); Http::preventStrayRequests();
        $company = Company::create(['name' => 'Synthetic setup access', 'ntn' => 'RCSETUP2', 'product_type' => 'di',
            'status' => 'approved', 'company_status' => 'active', 'onboarding_completed' => false]);
        $employee = User::factory()->create(['company_id' => $company->id, 'role' => 'employee', 'is_active' => true]);
        $this->actingAs($employee)->post('/onboarding/complete')->assertForbidden();
        $this->post('/onboarding/skip')->assertForbidden();
        $this->assertFalse((bool) $company->fresh()->onboarding_completed);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);
        $this->actingAs($owner)->post('/onboarding/skip')->assertRedirect('/dashboard');
        $this->assertTrue((bool) $company->fresh()->onboarding_completed);
    }

    public function test_complete_uses_own_existing_records_and_preserves_other_company(): void
    {
        Mail::fake(); Http::preventStrayRequests();
        $company = Company::create(['name' => 'Synthetic ready setup', 'ntn' => 'RCSETUP3', 'product_type' => 'di',
            'status' => 'approved', 'company_status' => 'active', 'onboarding_completed' => false, 'fbr_sandbox_token' => 'fictional-local-token']);
        $other = Company::create(['name' => 'Synthetic other setup', 'ntn' => 'RCSETUP4', 'product_type' => 'di', 'onboarding_completed' => false]);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);
        $this->actingAs($owner);
        \App\Models\Branch::create(['company_id' => $other->id, 'name' => 'Other branch']);
        \App\Models\Product::create(['company_id' => $other->id, 'name' => 'Other product', 'hs_code' => '0000.0000']);
        \App\Models\Invoice::create(['company_id' => $other->id, 'buyer_name' => 'Other fictional buyer']);
        $this->post('/onboarding/complete')->assertRedirect('/onboarding');
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Existing branch']);
        \App\Models\Product::create(['company_id' => $company->id, 'name' => 'Existing product', 'hs_code' => '0000.0000']);
        \App\Models\Invoice::create(['company_id' => $company->id, 'buyer_name' => 'Fictional buyer']);
        $this->post('/onboarding/complete')->assertRedirect('/dashboard')->assertSessionHas('success');
        $this->post('/onboarding/complete')->assertRedirect('/dashboard');
        $this->assertTrue((bool) $company->fresh()->onboarding_completed);
        $this->assertFalse((bool) $other->fresh()->onboarding_completed);
        $this->assertSame(2, \App\Models\Invoice::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }
}
