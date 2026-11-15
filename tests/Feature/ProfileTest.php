<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
    public function test_optional_birth_date_and_phone_are_validated_without_enabling_messages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/profile', ['name'=>$user->name, 'email'=>$user->email,
            'phone'=>'+92 (300) 123-4567', 'date_of_birth'=>'2000-02-29', 'company_id'=>987654, 'role'=>'super_admin'])
            ->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame('2000-02-29', $user->date_of_birth->format('Y-m-d'));
        $this->assertArrayNotHasKey('date_of_birth', $user->toArray());
        $this->assertNotEquals(987654, $user->company_id);
        $this->assertNotEquals('super_admin', $user->role);
        $this->actingAs($user)->patch('/profile', ['name'=>$user->name, 'email'=>$user->email,
            'phone'=>'call me <script>', 'date_of_birth'=>now()->addDay()->toDateString()])
            ->assertSessionHasErrors(['phone','date_of_birth']);
        $this->assertSame('2000-02-29', $user->fresh()->date_of_birth->format('Y-m-d'));
        $this->actingAs($user)->patch('/profile', ['name'=>$user->name, 'email'=>$user->email])
            ->assertSessionHasNoErrors();
        $this->assertSame('2000-02-29', $user->fresh()->date_of_birth->format('Y-m-d'));
        $this->actingAs($user)->patch('/profile', ['name'=>$user->name, 'email'=>$user->email, 'date_of_birth'=>null])
            ->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->date_of_birth);
    }

    public function test_contact_format_rules_reject_invalid_cnic_phone_and_calendar_date(): void
    {
        $rules = ['cnic'=>\App\Support\ProfileFieldRules::cnic(), 'phone'=>\App\Support\ProfileFieldRules::phone()];
        foreach (['1234512345671', '12345-1234567-1'] as $cnic) {
            $this->assertFalse(\Validator::make(['cnic'=>$cnic,'phone'=>'042-1234567'],$rules)->fails());
        }
        foreach (['abc','123','12345-1234567-X'] as $cnic) {
            $this->assertTrue(\Validator::make(['cnic'=>$cnic],$rules)->fails());
        }
        foreach (['123','+1234567890123456','03OO1234567','123/4567'] as $phone) {
            $this->assertTrue(\Validator::make(['phone'=>$phone],$rules)->fails());
        }
        $user=User::factory()->create();
        $this->actingAs($user)->patch('/profile',['name'=>$user->name,'email'=>$user->email,'date_of_birth'=>'2001-02-29'])
            ->assertSessionHasErrors('date_of_birth');
    }

    public function test_email_identity_is_product_scoped_and_same_product_duplicates_rejected(): void
    {
        $di=\App\Models\Company::create(['name'=>'Synthetic DI Profile','ntn'=>'9876543','product_type'=>'di','status'=>'approved','company_status'=>'active']);
        $pos=\App\Models\Company::create(['name'=>'Synthetic POS Profile','ntn'=>'9876544','product_type'=>'pos','status'=>'approved','company_status'=>'active']);
        $owner=User::factory()->create(['company_id'=>$di->id]);
        $foreign=User::factory()->create(['company_id'=>$pos->id]);
        $same=User::factory()->create(['company_id'=>$di->id]);
        $this->actingAs($owner)->patch('/profile',['name'=>$owner->name,'email'=>$foreign->email])->assertSessionHasNoErrors();
        $this->assertSame($foreign->email,$owner->fresh()->email);
        $this->actingAs($owner->fresh())->patch('/profile',['name'=>$owner->name,'email'=>$same->email])->assertSessionHasErrors('email');
    }

    public function test_cached_admin_cannot_edit_business_after_role_revocation(): void
    {
        $company=\App\Models\Company::create(['name'=>'Preserved synthetic company','ntn'=>'9876545','product_type'=>'di']);
        $user=User::factory()->create(['company_id'=>$company->id,'role'=>'company_admin']);
        \Illuminate\Support\Facades\DB::table('users')->where('id',$user->id)->update(['role'=>'company_user']);
        $request=\Illuminate\Http\Request::create('/profile/company','PUT',['name'=>'Unwanted change','company_id'=>99999]);
        $request->setUserResolver(fn()=>$user);
        (new \App\Http\Controllers\ProfileController())->updateCompany($request);
        $this->assertSame('Preserved synthetic company',$company->fresh()->name);
    }

}
