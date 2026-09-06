<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One email, several products (5 Sep 2026).
 *
 * Identity is unique per product line now, so the SAME email can hold a PRA
 * POS account and a Digital Invoice account. Everything that looks a user up
 * by email alone therefore has to say which product it means — otherwise the
 * first row created wins, and one product's password opens another product's
 * session.
 */
class CrossProductIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_EMAIL = 'one.owner@business.pk';

    /** @return array{0: User, 1: User} POS user (created first), DI user */
    private function twoAccountsOnOneEmail(): array
    {
        $pos = Company::create([
            'name' => 'Owner Hotel', 'owner_name' => 'One Owner', 'product_type' => 'pos',
            'email' => self::SHARED_EMAIL, 'phone' => '03004871932', 'ntn' => '4871932',
            'cnic' => '3520287416395', 'status' => 'approved', 'company_status' => 'active',
        ]);
        $di = Company::create([
            'name' => 'Owner Distribution', 'owner_name' => 'One Owner', 'product_type' => 'di',
            'email' => self::SHARED_EMAIL, 'phone' => '03004871932', 'ntn' => '4871932',
            'cnic' => '3520287416395', 'status' => 'approved', 'company_status' => 'active',
        ]);

        $posUser = User::create([
            'name' => 'One Owner', 'email' => self::SHARED_EMAIL, 'password' => Hash::make('pos-password-1'),
            'company_id' => $pos->id, 'role' => 'company_admin',
        ]);
        $diUser = User::create([
            'name' => 'One Owner', 'email' => self::SHARED_EMAIL, 'password' => Hash::make('di-password-2'),
            'company_id' => $di->id, 'role' => 'company_admin',
        ]);

        return [$posUser, $diUser];
    }

    public function test_a_pos_password_cannot_open_a_digital_invoice_session(): void
    {
        [$posUser] = $this->twoAccountsOnOneEmail();

        $response = $this->post('/login', [
            'login'    => self::SHARED_EMAIL,
            'password' => 'pos-password-1',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->assertFalse(
            auth()->check() && auth()->id() === $posUser->id,
            'a POS account must never be authenticated on the Digital Invoice panel'
        );
    }

    public function test_the_digital_invoice_owner_signs_in_with_their_own_password(): void
    {
        [, $diUser] = $this->twoAccountsOnOneEmail();

        $this->post('/login', [
            'login'    => self::SHARED_EMAIL,
            'password' => 'di-password-2',
        ]);

        $this->assertAuthenticatedAs($diUser);
    }

    public function test_a_panel_less_reset_still_verifies_a_product_scoped_code(): void
    {
        // A PRA POS owner who opened the plain /forgot-password page: nothing
        // in the URL says "pos", but the code is issued for their POS account.
        $company = Company::create([
            'name' => 'Solo Hotel', 'owner_name' => 'Solo Owner', 'product_type' => 'pos',
            'email' => 'solo.owner@business.pk', 'phone' => '03005551234', 'ntn' => '5551234',
            'cnic' => '3520255512340', 'status' => 'approved', 'company_status' => 'active',
        ]);
        User::create([
            'name' => 'Solo Owner', 'email' => 'solo.owner@business.pk', 'password' => Hash::make('old-password'),
            'company_id' => $company->id, 'role' => 'company_admin',
        ]);

        $this->post('/forgot-password', ['email' => 'solo.owner@business.pk'])
            ->assertRedirect(route('password.verify.otp', ['email' => 'solo.owner@business.pk']));

        $record = DB::table('password_reset_otps')->where('email', 'solo.owner@business.pk')->latest('id')->first();
        $this->assertNotNull($record);
        $this->assertSame('pos', $record->product_type, 'the code must be issued for the account it belongs to');

        $this->post('/verify-otp', ['email' => 'solo.owner@business.pk', 'otp' => $record->otp, 'panel' => ''])
            ->assertSessionHasNoErrors()
            ->assertRedirectContains('reset-password');

        $this->assertSame('pos', session('password_reset_product'));
    }

    public function test_the_reset_redirect_looks_the_same_for_a_known_and_an_unknown_email(): void
    {
        Company::create([
            'name' => 'Known Shop', 'owner_name' => 'Known Owner', 'product_type' => 'pos',
            'email' => 'known@business.pk', 'phone' => '03007778888', 'ntn' => '7778888',
            'cnic' => '3520277788881', 'status' => 'approved', 'company_status' => 'active',
        ]);
        User::create([
            'name' => 'Known Owner', 'email' => 'known@business.pk', 'password' => Hash::make('secret123'),
            'company_id' => Company::where('email', 'known@business.pk')->value('id'), 'role' => 'company_admin',
        ]);

        $known = $this->post('/forgot-password', ['email' => 'known@business.pk']);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@business.pk']);

        // Same shape, different address only — the reply must not reveal which
        // of the two addresses is registered, nor on which product.
        $shape = fn (string $url, string $email) => str_replace(
            [$email, urlencode($email)],
            'EMAIL',
            $url
        );

        $this->assertSame(
            $shape($known->headers->get('Location'), 'known@business.pk'),
            $shape($unknown->headers->get('Location'), 'nobody@business.pk')
        );
    }
}
