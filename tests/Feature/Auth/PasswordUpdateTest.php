<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }
    public function test_current_persisted_password_is_required_and_other_browser_generations_are_revoked(): void
    {
        $user = \App\Models\User::factory()->create(['password'=>\Illuminate\Support\Facades\Hash::make('OldOwner123!')]);
        $this->actingAs($user);
        \Illuminate\Support\Facades\DB::table('users')->where('id',$user->id)->update(['password'=>\Illuminate\Support\Facades\Hash::make('CurrentOwner123!')]);
        $this->from('/profile')->put('/password',['current_password'=>'OldOwner123!','password'=>'NewOwner123!','password_confirmation'=>'NewOwner123!'])
            ->assertSessionHasErrorsIn('updatePassword','current_password');
        $this->put('/password',['current_password'=>'CurrentOwner123!','password'=>'NewOwner123!','password_confirmation'=>'NewOwner123!'])
            ->assertSessionHas('status','password-updated');
        $current = $user->fresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewOwner123!', $current->password));
        $this->assertTrue(\Illuminate\Support\Str::isUuid($current->session_generation));
        $this->assertSame($current->session_generation, session(\App\Http\Middleware\CompanySessionGeneration::key('web'))['generation']);
        $this->assertNotNull($current->remember_token);
    }

}
