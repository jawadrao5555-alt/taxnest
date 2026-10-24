<?php

namespace Tests\Unit;

use App\Models\PosRider;
use App\Support\PosFulfillment;
use PHPUnit\Framework\TestCase;

class PosFulfillmentTest extends TestCase
{
    private function line(string $mode, float $subtotal, bool $exempt = false, bool $fee = false): object
    {
        return (object) ['fulfillment' => $mode, 'subtotal' => $subtotal, 'is_tax_exempt' => $exempt, 'skip_kitchen' => $fee];
    }

    public function test_mixed_detection_keeps_legacy_and_single_service_offline_contract(): void
    {
        self::assertFalse(PosFulfillment::isMixed([['quantity' => 2]], 'dine_in'));
        self::assertFalse(PosFulfillment::isMixed([['fulfillment' => 'delivery']], 'delivery'));
        self::assertTrue(PosFulfillment::isMixed([['fulfillment' => 'delivery']], 'dine_in'));
        self::assertTrue(PosFulfillment::isMixed([['fulfillment' => 'dine_in'], ['fulfillment' => 'takeaway']], 'dine_in'));
    }

    public function test_counter_paid_and_delivery_only_cash_keep_one_invoice_total(): void
    {
        $lines = [$this->line('dine_in', 100), $this->line('takeaway', 100), $this->line('delivery', 100)];
        self::assertSame(0.0, PosFulfillment::collectionAmount(300, $lines, 'counter'));
        self::assertSame(100.0, PosFulfillment::collectionAmount(300, $lines, 'rider'));
    }

    public function test_tax_exemption_fee_and_discount_weight_do_not_charge_the_entire_bill_to_rider(): void
    {
        $lines = [$this->line('dine_in', 100), $this->line('delivery', 100), $this->line('delivery', 20, true, true)];
        // Two taxable Rs100 lines + one exempt Rs20 fee, all discounted 10%.
        // Gross weights104.4 +104.4 +18 =226.8, invoice whole-rupee total227.
        $due = PosFulfillment::collectionAmount(227, $lines, 'rider', .9, 16, false);
        self::assertSame(122.51, $due);
        self::assertSame(227.0, round($due + (227 - $due), 2));
        self::assertSame(124.36, PosFulfillment::collectionAmount(228, $lines, 'rider', 1, 0, true));
    }

    public function test_old_bill_cash_contract_and_partial_receipts_stay_unchanged(): void
    {
        $legacy = (object) ['total_amount' => 300, 'rider_partial_paid' => 50];
        self::assertSame(250.0, PosRider::remainingAmount($legacy));
        $mixed = (object) ['total_amount' => 300, 'rider_collection_amount' => 100, 'rider_partial_paid' => 25];
        self::assertSame(75.0, PosRider::remainingAmount($mixed));
        $paid = (object) ['total_amount' => 300, 'rider_collection_amount' => 0];
        self::assertSame(0.0, PosRider::remainingAmount($paid));
    }

    public function test_new_service_field_is_separate_from_notes_and_legacy_fields_are_untouched(): void
    {
        self::assertSame([], PosFulfillment::fields(['special_notes' => 'Parcel for family']));
        self::assertSame(['fulfillment' => 'takeaway', 'line_uid' => 'line_1'], PosFulfillment::fields(['fulfillment' => 'takeaway', 'cart_uid' => 'line_1', 'special_notes' => 'No onions']));
        self::assertSame('PACK / DELIVERY', PosFulfillment::label('delivery'));
        self::assertSame('', PosFulfillment::label(null));
    }
}
