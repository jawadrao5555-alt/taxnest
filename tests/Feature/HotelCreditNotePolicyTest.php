<?php

namespace Tests\Feature;

use App\Exceptions\HotelStayException;
use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\User;
use App\Services\HotelFolioService;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use App\Services\HotelCreditNotePolicy;
use App\Models\PosTransaction;

class HotelCreditNotePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        PosFeatureService::flushGateCaches();
        $company = $this->company('hotel', ['pos_tax_rate_cash' => 0]);
        $owner = $this->owner($company);
        $stays = app(HotelStayService::class);
        $room = $this->room($stays, $company, 'CN-1', 1000);
        $stay = $stays->book((int) $company->id, (int) $owner->id, [
            'room_id' => $room->id, 'check_in_date' => '2026-10-04', 'check_out_date' => '2026-10-06',
            'guest_name' => 'Synthetic credit review', 'walk_in' => true,
        ]);
        $folio = app(HotelFolioService::class);
        $folio->postPayment($stay, ['amount' => 2000, 'payment_method' => 'cash'], (int) $owner->id);
        $bill = $folio->settleCoveredCharges($stay, (int) $owner->id, 'cash')['transaction'];
        // Synthetic acceptance fixture only; no regulator call.
        $bill->update(['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'TEST-FISCAL-CN']);
        return [$stay, $owner, $bill->fresh('items')];
    }

    public function test_partial_and_full_review_preserve_bill_money_and_stay(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $item = $bill->items->first();
        $beforeBill = $bill->getAttributes();
        $beforeFolio = HotelFolioEntry::where('stay_id', $stay->id)->get()->toArray();
        $policy = app(HotelCreditNotePolicy::class);
        $partial = $policy->review($stay, $owner, (int) $bill->id, [$item->id => (float) $item->quantity / 2]);
        $this->assertSame('partial', $partial['kind']);
        $this->assertSame($bill->invoice_number, $partial['original_usin']);
        $this->assertEquals(round((float) $item->subtotal / 2, 2), $partial['lines'][0]['original_subtotal_share']);
        $this->assertNull($partial['refund_amount']);
        $this->assertFalse($partial['issuance_enabled']);
        $this->assertSame('full_remaining', $policy->review($stay, $owner, (int) $bill->id)['kind']);
        $this->assertSame($beforeBill, $bill->fresh()->getAttributes());
        $this->assertSame($beforeFolio, HotelFolioEntry::where('stay_id', $stay->id)->get()->toArray());
        $this->assertSame('checked_in', $stay->fresh()->status);
        $this->assertDatabaseMissing('pos_transactions', ['parent_transaction_id' => $bill->id]);
    }

    public function test_foreign_actor_cannot_review_bill(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $foreign = $this->owner($this->company('hotel'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(HotelCreditNotePolicy::class)->review($stay, $foreign, (int) $bill->id);
    }

    public function test_over_quantity_is_rejected(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $item = $bill->items->first();
        $this->expectException(HotelStayException::class);
        app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id, [$item->id => (float) $item->quantity + 1]);
    }

    public function test_unconfirmed_original_cannot_be_treated_as_reported(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $bill->update(['pra_status' => 'offline']);
        $this->expectException(HotelStayException::class);
        app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id);
    }

    public function test_existing_failed_credit_must_be_resolved_first(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $child = $bill->replicate();
        $child->invoice_number = 'TEST-CREDIT-PENDING';
        $child->parent_transaction_id = $bill->id;
        $child->transaction_type = 'return';
        $child->pra_status = 'failed';
        $child->pra_invoice_number = null;
        $child->save();
        $this->expectException(HotelStayException::class);
        app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id);
    }
    private function company(string $category, array $overrides = []): Company
    {
        $defaults = PosFeatureService::defaultsForCategory($category);
        $flags = $overrides['feature_flags'] ?? $defaults;
        unset($overrides['feature_flags']);

        return Company::create(array_merge([
            'name' => 'Hotel Test ' . $category,
            'ntn' => (string) random_int(100000000, 999999999),
            'email' => uniqid('hotel-', true) . '@test.pk',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
            'business_category' => $category,
            'feature_flags' => $flags,
            'restaurant_mode' => PosFeatureService::restaurantModeFrom($flags),
            'is_internal_account' => true,
            'pos_setup_completed' => true,
        ], $overrides));
    }

    private function owner(Company $company): User
    {
        return User::create([
            'name' => 'Hotel Owner',
            'email' => uniqid('hotel-owner-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'is_active' => true,
        ]);
    }

    private function room(HotelStayService $stays, Company $company, string $number = '101', float $rate = 5000): \App\Models\HotelRoom
    {
        return $stays->createRoom((int) $company->id, [
            'room_number' => $number,
            'room_type' => 'Deluxe',
            'capacity' => 2,
            'rate_amount' => $rate,
            'rate_unit' => 'NGT',
            'branch_id' => null,
        ]);
    }

}
