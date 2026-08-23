<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Owner, 23 Aug 2026: a day stayed open because of one pending bill, and nobody
 * noticed — next morning the shop opens the app and goes STRAIGHT to New Sale,
 * so the red banner on the dashboard / day-close page was never seen and a
 * fresh day's bills piled on top of an unclosed one.
 *
 * "Popup ban ke show ho jaye… jab tak woh isko khud close na kare, auto close
 * na ho." So: a modal, on the sale screen and the dashboard, fed at runtime by
 * this endpoint — and only for the people who may actually close a day.
 */
class PosUnclosedDayPopupTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $company = Company::create([
            'name' => 'Unclosed Day Shop',
            'ntn' => (string) random_int(1000000, 9999999) . random_int(10, 99),
            'email' => uniqid('unclosed-', true) . '@test.pk',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
            'is_internal_account' => true,
            // Skip the first-run feature wizard, or the sale screen redirects.
            'pos_setup_completed' => true,
        ]);
        PosFeatureService::flushGateCaches();

        return $company;
    }

    private function user(Company $company, string $posRole = 'pos_admin'): User
    {
        return User::create([
            'name' => 'Day Closer',
            'email' => uniqid('dc-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => $posRole === 'pos_admin' ? 'company_admin' : 'staff',
            'pos_role' => $posRole,
            'is_active' => true,
        ]);
    }

    /** A completed bill dated on a previous business day, never closed. */
    private function billOnPreviousDay(Company $company): PosTransaction
    {
        $txn = PosTransaction::create([
            'company_id' => $company->id,
            'invoice_number' => 'L-' . random_int(100, 999),
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'subtotal' => 500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 500,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        $yesterday = now()->subDay();
        $update = ['created_at' => $yesterday, 'updated_at' => $yesterday];
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'business_date')) {
            $update['business_date'] = $yesterday->toDateString();
        }
        DB::table('pos_transactions')->where('id', $txn->id)->update($update);

        return $txn->refresh();
    }

    public function test_an_unclosed_previous_day_is_reported_to_the_popup(): void
    {
        $company = $this->company();
        $this->billOnPreviousDay($company);

        $response = $this->actingAs($this->user($company), 'pos')
            ->getJson('/pos/api/day-close-pending');

        $response->assertOk()->assertJson(['pending' => true, 'can_close' => true, 'count' => 1]);
        $this->assertSame(
            [now()->subDay()->format('d M Y')],
            $response->json('labels'),
            'The popup shows the date, so the shop knows which day to close.'
        );
        $this->assertStringContainsString('date=' . now()->subDay()->toDateString(), (string) $response->json('url'),
            'The button must land on that exact day, not on a blank day-close page.');
    }

    public function test_nothing_pops_when_every_previous_day_is_closed(): void
    {
        $company = $this->company();
        // Today's bills alone are not an unclosed PREVIOUS day.
        PosTransaction::create([
            'company_id' => $company->id,
            'invoice_number' => 'L-001',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'subtotal' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        $this->actingAs($this->user($company), 'pos')
            ->getJson('/pos/api/day-close-pending')
            ->assertOk()
            ->assertJson(['pending' => false]);
    }

    public function test_a_cashier_who_may_not_close_a_day_is_never_nagged(): void
    {
        $company = $this->company();
        $this->billOnPreviousDay($company);

        $this->actingAs($this->user($company, 'pos_cashier'), 'pos')
            ->getJson('/pos/api/day-close-pending')
            ->assertOk()
            ->assertJson(['pending' => false, 'can_close' => false]);
    }

    public function test_another_shops_unclosed_day_is_not_visible(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $this->billOnPreviousDay($theirs);

        $this->actingAs($this->user($mine), 'pos')
            ->getJson('/pos/api/day-close-pending')
            ->assertOk()
            ->assertJson(['pending' => false]);
    }

    public function test_the_popup_is_present_on_the_dashboard_and_the_sale_screen(): void
    {
        $company = $this->company();
        $user = $this->user($company);

        $this->actingAs($user, 'pos')->get('/pos/dashboard')
            ->assertOk()
            ->assertSee('dayClosePendingPopup()', false);

        $this->actingAs($user, 'pos')->get('/pos/invoice/create')
            ->assertOk()
            ->assertSee('dayClosePendingPopup()', false);
    }
}
