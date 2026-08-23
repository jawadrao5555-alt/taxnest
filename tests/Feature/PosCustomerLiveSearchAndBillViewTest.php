<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Owner's counter complaints, 23 Aug 2026:
 *
 *   1. "yeh khud se search karna chahiye" — the customer box only filtered the
 *      100 rows already on screen, so a shop with 11k customers typed a phone
 *      number, read "0 customers found", and had to press Enter for the real
 *      search. Typing must now ask the SERVER.
 *   2. "iska faida to tab hoga na ke koi bhi bill khol ke check kar sake order
 *      kya kya tha" — the customer history listed bills but nothing opened, so
 *      keeping the record was pointless. A history row must open its items.
 *
 * These tests pin the behaviour AND the authorization: the quick view may never
 * show a bill the shop's own bill page would refuse.
 */
class PosCustomerLiveSearchAndBillViewTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $company = Company::create([
            'name' => 'Live Search Shop',
            'ntn' => (string) random_int(1000000, 9999999) . random_int(10, 99),
            'email' => uniqid('livesearch-', true) . '@test.pk',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
            'is_internal_account' => true,
        ]);
        PosFeatureService::flushGateCaches();

        return $company;
    }

    private function owner(Company $company): User
    {
        return User::create([
            'name' => 'Shop Owner',
            'email' => uniqid('owner-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'is_active' => true,
        ]);
    }

    private function customer(Company $company, string $name, string $phone): PosCustomer
    {
        return PosCustomer::create([
            'company_id' => $company->id,
            'name' => $name,
            'phone' => $phone,
        ]);
    }

    private function bill(Company $company, PosCustomer $customer, array $items): PosTransaction
    {
        $subtotal = collect($items)->sum(fn ($i) => $i['qty'] * $i['price']);

        $txn = PosTransaction::create([
            'company_id' => $company->id,
            'invoice_number' => 'L-' . random_int(100, 999),
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'customer_id' => $customer->id,
            'customer_phone' => $customer->phone,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $subtotal,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        foreach ($items as $i) {
            PosTransactionItem::create([
                'transaction_id' => $txn->id,
                'item_type' => 'product',
                'item_name' => $i['name'],
                'quantity' => $i['qty'],
                'unit_price' => $i['price'],
                'subtotal' => $i['qty'] * $i['price'],
                'tax_rate' => 0,
                'tax_amount' => 0,
            ]);
        }

        return $txn;
    }

    // ── 1. the customer box searches the server as the shop types ──

    public function test_typing_returns_server_matched_rows_not_just_the_current_page(): void
    {
        $company = $this->company();
        $this->customer($company, 'Zahid Irfan', '03012907488');
        $this->customer($company, 'Someone Else', '03331112222');

        $response = $this->actingAs($this->owner($company), 'pos')
            ->getJson('/pos/customers?rows=1&q=03012907488');

        $response->assertOk()->assertJson(['success' => true, 'found' => 1]);

        $html = $response->json('html');
        $this->assertStringContainsString('Zahid Irfan', $html);
        $this->assertStringNotContainsString('Someone Else', $html,
            'The server must return only the matching rows — that is the whole point of live search.');
        $this->assertSame(2, $response->json('total'),
            'The total customer count must stay the shop total, not the filtered count.');
    }

    public function test_the_live_search_flag_never_leaks_into_the_pager_links(): void
    {
        $company = $this->company();
        // 101 customers = two pages, so the pager renders.
        for ($i = 0; $i < 101; $i++) {
            $this->customer($company, 'Customer ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), '0300000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $response = $this->actingAs($this->owner($company), 'pos')
            ->getJson('/pos/customers?rows=1&q=Customer');

        $response->assertOk();
        $pagination = (string) $response->json('pagination');

        $this->assertNotSame('', $pagination, 'Two pages of results must still come with a pager.');
        $this->assertStringNotContainsString('rows=1', $pagination,
            'A pager link carrying rows=1 would download the JSON payload instead of the page.');
        $this->assertStringContainsString('q=Customer', $pagination,
            'The pager must keep the search, or page 2 silently drops it.');
    }

    public function test_the_page_still_renders_normally_without_the_live_search_flag(): void
    {
        $company = $this->company();
        $this->customer($company, 'Zahid Irfan', '03012907488');

        $this->actingAs($this->owner($company), 'pos')
            ->get('/pos/customers')
            ->assertOk()
            ->assertSee('Zahid Irfan')
            ->assertSee('custTable', false)
            // One tbody per customer: the inline edit row is a sibling of the
            // main row, so the Alpine scope has to sit on a shared parent.
            ->assertSee('<tbody class="cust-tb', false);
    }

    // ── 2. a history row opens the bill's items ──

    public function test_a_history_row_returns_the_items_that_were_ordered(): void
    {
        $company = $this->company();
        $customer = $this->customer($company, 'Zahid Irfan', '03012907488');
        $bill = $this->bill($company, $customer, [
            ['name' => 'Beast Burger', 'qty' => 2, 'price' => 550],
            ['name' => 'Cheese Slice', 'qty' => 1, 'price' => 80],
        ]);

        $response = $this->actingAs($this->owner($company), 'pos')
            ->getJson('/pos/customers/history/bill/' . $bill->id);

        $response->assertOk()->assertJson(['success' => true, 'total' => 1180]);
        $items = collect($response->json('items'));
        $this->assertSame(['Beast Burger', 'Cheese Slice'], $items->pluck('name')->all());
        $this->assertSame(2.0, (float) $items->firstWhere('name', 'Beast Burger')['qty']);
        $this->assertStringContainsString('/pos/transaction/' . $bill->id, (string) $response->json('url'),
            'The modal must be able to hand off to the full bill page.');
    }

    public function test_the_history_page_makes_real_bills_clickable(): void
    {
        $company = $this->company();
        $customer = $this->customer($company, 'Zahid Irfan', '03012907488');
        $bill = $this->bill($company, $customer, [['name' => 'Beast Burger', 'qty' => 1, 'price' => 550]]);

        $this->actingAs($this->owner($company), 'pos')
            ->get('/pos/customers/' . $customer->id . '/history')
            ->assertOk()
            ->assertSee('openBill(' . $bill->id . ')', false);
    }

    public function test_another_shops_bill_is_not_readable_through_the_quick_view(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $theirCustomer = $this->customer($theirs, 'Their Customer', '03009998888');
        $theirBill = $this->bill($theirs, $theirCustomer, [['name' => 'Secret Item', 'qty' => 1, 'price' => 100]]);

        $this->actingAs($this->owner($mine), 'pos')
            ->getJson('/pos/customers/history/bill/' . $theirBill->id)
            ->assertNotFound();
    }

    public function test_a_cashier_cannot_reach_the_quick_view_at_all(): void
    {
        $company = $this->company();
        $customer = $this->customer($company, 'Zahid Irfan', '03012907488');
        $bill = $this->bill($company, $customer, [['name' => 'Beast Burger', 'qty' => 1, 'price' => 550]]);

        $cashier = User::create([
            'name' => 'Counter Cashier',
            'email' => uniqid('cashier-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => 'staff',
            'pos_role' => 'pos_cashier',
            'is_active' => true,
        ]);

        // The history routes sit behind the admin-only group; the quick view
        // must live in exactly the same place, never one step looser.
        $response = $this->actingAs($cashier, 'pos')
            ->get('/pos/customers/history/bill/' . $bill->id);

        $this->assertContains($response->getStatusCode(), [302, 403],
            'A cashier must not be able to open another screen of bill detail through the quick view.');
    }
}
