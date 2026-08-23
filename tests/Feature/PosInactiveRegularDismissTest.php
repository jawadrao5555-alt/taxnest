<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\User;
use App\Services\PosFeatureService;
use App\Services\PosRepeatCustomerAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Owner, 23 Aug 2026 (voice notes): the dashboard's "Regular customers gone
 * quiet" card had no way to clear a customer once the shop had called them, so
 * the same names sat on top for days and pushed the dashboard down — "jis ko
 * handle kar liya us ko clear karna chahta hoon taake neeche wala front par aa
 * jaye… teen number bas show hon, baqi automatic hide."
 */
class PosInactiveRegularDismissTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $company = Company::create([
            'name' => 'Quiet Regulars Shop',
            'ntn' => (string) random_int(1000000, 9999999) . random_int(10, 99),
            'email' => uniqid('quiet-', true) . '@test.pk',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
            'is_internal_account' => true,
            'pos_setup_completed' => true,
        ]);
        PosFeatureService::flushGateCaches();

        return $company;
    }

    private function user(Company $company, string $posRole = 'pos_admin'): User
    {
        return User::create([
            'name' => 'Shop Admin',
            'email' => uniqid('qr-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => $posRole === 'pos_admin' ? 'company_admin' : 'staff',
            'pos_role' => $posRole,
            'is_active' => true,
        ]);
    }

    /** A repeat customer (3 completed bills) whose last order is $days old. */
    private function quietRegular(Company $company, string $name, int $days): PosCustomer
    {
        $customer = PosCustomer::create([
            'company_id' => $company->id,
            'name' => $name,
            'phone' => '0300' . random_int(1000000, 9999999),
            'is_active' => true,
        ]);

        for ($i = 0; $i < PosRepeatCustomerAlert::MIN_ORDERS; $i++) {
            $when = now()->subDays($days + $i);
            $id = DB::table('pos_transactions')->insertGetId([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'invoice_number' => 'L-' . random_int(10000, 99999),
                'invoice_mode' => 'local',
                'pra_status' => 'local',
                'subtotal' => 100,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 100,
                'payment_method' => 'cash',
                'status' => 'completed',
                'created_at' => $when,
                'updated_at' => $when,
            ]);
            DB::table('pos_transactions')->where('id', $id)->update(['created_at' => $when]);
        }

        Cache::flush();

        return $customer;
    }

    public function test_a_handled_customer_leaves_the_card_and_the_next_one_moves_up(): void
    {
        $company = $this->company();
        $handled = $this->quietRegular($company, 'Asif', 20);
        $this->quietRegular($company, 'Sats', 25);

        $this->assertCount(2, PosRepeatCustomerAlert::listFor($company->id));

        $this->actingAs($this->user($company), 'pos')
            ->postJson('/pos/customers/alert-dismiss', ['customer_id' => $handled->id])
            ->assertOk()
            ->assertJson(['success' => true, 'remaining' => 1]);

        $left = PosRepeatCustomerAlert::listFor($company->id);
        $this->assertCount(1, $left, 'Only the handled customer disappears.');
        $this->assertSame('Sats', $left->first()['name']);
    }

    public function test_the_alert_returns_if_the_customer_orders_again_and_goes_quiet_again(): void
    {
        $company = $this->company();
        $customer = $this->quietRegular($company, 'Wapas Aane Wala', 20);

        $this->actingAs($this->user($company), 'pos')
            ->postJson('/pos/customers/alert-dismiss', ['customer_id' => $customer->id])
            ->assertOk();
        $this->assertCount(0, PosRepeatCustomerAlert::listFor($company->id));

        // He came back, ordered once — and then went quiet again.
        $when = now()->subDays(PosRepeatCustomerAlert::INACTIVE_DAYS + 1);
        DB::table('pos_transactions')->insert([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'L-77777',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'subtotal' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'cash',
            'status' => 'completed',
            'created_at' => $when,
            'updated_at' => $when,
        ]);
        Cache::flush();

        $this->assertCount(1, PosRepeatCustomerAlert::listFor($company->id),
            'A dismissal covers one silence only — a fresh one must alert again.');
    }

    public function test_a_cashier_cannot_clear_the_card(): void
    {
        $company = $this->company();
        $customer = $this->quietRegular($company, 'Asif', 20);

        $this->actingAs($this->user($company, 'pos_cashier'), 'pos')
            ->postJson('/pos/customers/alert-dismiss', ['customer_id' => $customer->id])
            ->assertForbidden();

        $this->assertCount(1, PosRepeatCustomerAlert::listFor($company->id));
    }

    public function test_another_shops_customer_cannot_be_dismissed(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $theirCustomer = $this->quietRegular($theirs, 'Unka Customer', 20);

        $this->actingAs($this->user($mine), 'pos')
            ->postJson('/pos/customers/alert-dismiss', ['customer_id' => $theirCustomer->id])
            ->assertNotFound();

        $this->assertCount(1, PosRepeatCustomerAlert::listFor($theirs->id),
            'Their card must be untouched.');
    }

    public function test_the_card_shows_only_three_rows_and_hides_the_rest(): void
    {
        $this->assertSame(3, PosRepeatCustomerAlert::CARD_LIMIT,
            'Owner asked for three visible rows on the dashboard card.');

        $company = $this->company();
        foreach (['Ek', 'Do', 'Teen', 'Chaar', 'Paanch'] as $i => $name) {
            $this->quietRegular($company, $name, 20 + $i);
        }

        $html = $this->actingAs($this->user($company), 'pos')
            ->get('/pos/dashboard')
            ->assertOk()
            ->getContent();

        $rows = substr_count($html, 'data-ic-row');
        $hidden = substr_count($html, 'data-ic-row data-cid="');
        $this->assertGreaterThan(3, $rows, 'The next customers are pre-rendered so they can slide up.');
        $this->assertSame(5, $hidden, 'All five are in the DOM…');
        $this->assertSame(2, substr_count($html, 'class="py-2 flex items-center gap-3" style="display:none"'),
            '…but only three are visible at a time.');
    }
}
