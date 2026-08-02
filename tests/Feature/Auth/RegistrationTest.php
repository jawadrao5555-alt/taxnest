<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registration here is the custom DI signup: it also creates a Company
 * (pending approval) + trial subscription and does NOT auto-login —
 * it redirects to /login with a pending-approval notice.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'company_name' => 'Test Company',
            'company_ntn' => '1234567',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/login');

        // No auto-login: company is pending approval
        $this->assertGuest();

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('company_admin', $user->role);
        $this->assertNotNull($user->company_id);
        $this->assertDatabaseHas('companies', [
            'id' => $user->company_id,
            'ntn' => '1234567',
            'product_type' => 'di',
            'status' => 'pending',
        ]);
    }
}
