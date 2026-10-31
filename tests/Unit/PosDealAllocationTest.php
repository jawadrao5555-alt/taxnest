<?php

namespace Tests\Unit;

use App\Services\PosDealAllocation;
use PHPUnit\Framework\TestCase;

class PosDealAllocationTest extends TestCase
{
    private function snapshot(): array
    {
        return [
            ['allocation_version' => 1, 'product_id' => 1, 'allocation_weight' => 80000, 'quantity' => 1, 'unit_cost' => 250,
                'tax_facts' => ['is_tax_exempt' => false]],
            ['allocation_version' => 1, 'product_id' => 2, 'allocation_weight' => 20000, 'quantity' => 1, 'unit_cost' => 80,
                'tax_facts' => ['is_tax_exempt' => true]],
        ];
    }

    public function test_mixed_deal_discount_allocates_tax_only_to_taxable_components(): void
    {
        $snapshot = $this->snapshot();
        $this->assertSame(640.0, PosDealAllocation::taxableSubtotal($snapshot, 800));
        $frozen = PosDealAllocation::capture($snapshot, 800, 80, 16, false);
        $this->assertEquals(720, PosDealAllocation::sum($frozen, 'taxable_base') + PosDealAllocation::sum($frozen, 'exempt_base'));
        $this->assertEquals(576, PosDealAllocation::sum($frozen, 'taxable_base'));
        $this->assertEquals(144, PosDealAllocation::sum($frozen, 'exempt_base'));
        $this->assertEquals(92.16, PosDealAllocation::sum($frozen, 'tax'));
        $this->assertEquals(330, PosDealAllocation::unitCost($frozen));
        $this->assertEquals(0, $frozen[1]['allocation']['tax']);
    }

    public function test_inclusive_card_save_keeps_exempt_menu_share_whole(): void
    {
        $snapshot = PosDealAllocation::capture($this->snapshot(), 1000, 0, 5, true, 16);
        $this->assertEquals(200, $snapshot[1]['allocation']['total']);
        $this->assertEquals(689.66, PosDealAllocation::sum($snapshot, 'taxable_base'));
        $this->assertEquals(34.48, PosDealAllocation::sum($snapshot, 'tax'));
        $this->assertEquals(924.14, PosDealAllocation::sum($snapshot, 'total'));
    }

    public function test_remainders_are_stable_and_sum_exactly_for_quantity_and_discount_edges(): void
    {
        $this->assertSame([34, 33, 33], PosDealAllocation::cents(100, [1, 1, 1]));
        $this->assertSame([0, 1, 0], PosDealAllocation::cents(1, [0, 3, 1]));
        foreach ([0, 1, 99, 10001, 99999999] as $total) {
            $this->assertSame($total, array_sum(PosDealAllocation::cents($total, [111, 49, 0, 345])));
        }
    }

    public function test_legacy_snapshots_keep_legacy_tax_semantics_and_unknown_cost(): void
    {
        $legacy = [['product_id' => 1, 'qty' => 2, 'tax_facts' => ['is_tax_exempt' => true]]];
        $this->assertSame(100.0, PosDealAllocation::taxableSubtotal($legacy, 100, false));
        $this->assertSame(0.0, PosDealAllocation::taxableSubtotal($legacy, 100, true));
        $this->assertNull(PosDealAllocation::unitCost($legacy));
        $this->assertSame($legacy, PosDealAllocation::capture($legacy, 100, 0, 16, false));
    }

    public function test_inclusive_paisa_tie_does_not_add_a_paisa_to_the_menu_price(): void
    {
        $one = [$this->snapshot()[0]];
        $frozen = PosDealAllocation::capture($one, .03, 0, 100, true);
        $this->assertEquals(.03, PosDealAllocation::sum($frozen, 'total'));
        $this->assertEquals(.01, PosDealAllocation::sum($frozen, 'taxable_base'));
        $zeroMenuRate = PosDealAllocation::capture($one, .03, 0, 100, true, 0);
        $this->assertEquals(.03, PosDealAllocation::sum($zeroMenuRate, 'total'));
    }
    public function test_bill_discount_pennies_and_tax_are_conserved_across_lines(): void
    {
        $snapshot = [
            ['allocation_version' => 1, 'allocation_weight' => 800, 'tax_facts' => ['is_tax_exempt' => false]],
            ['allocation_version' => 1, 'allocation_weight' => 200, 'tax_facts' => ['is_tax_exempt' => true]],
        ];
        $bill = \App\Services\PosDealAllocation::bill([
            ['snapshot' => $snapshot, 'subtotal' => .03], ['subtotal' => .02, 'exempt' => true],
        ], .01, 16, true);
        $this->assertEquals(.01, array_sum(array_column($bill['lines'], 'discount')));
        $this->assertEquals(.04, round($bill['subtotal_col'] - $bill['discount_amount'] + $bill['tax_amount'], 2));
        $this->assertNull(\App\Services\PosDealAllocation::bill([['subtotal' => 100]], 0, 16, false));
    }

}
