<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The FBR DI API re-derives an item's tax rate from the numbers we POST
 * (salesTaxApplicable / valueSalesExcludingST). If json_encode writes a float
 * as 360.81000000000000227373675443232059478759765625 instead of 360.81, that
 * division lands on 18.001164% and FBR rejects the line with
 * "[0077] Valid SRO/Schedule No. is mandatory where rate is not 18%".
 *
 * The live host ships serialize_precision=100 in its CLI php.ini, so this only
 * ever broke invoices filed by a queue worker — the same invoice sent from the
 * browser was accepted. bootstrap/app.php forces the setting back to -1; this
 * test is the guard that keeps it there.
 */
class FbrPayloadPrecisionTest extends TestCase
{
    public function test_serialize_precision_is_pinned_to_shortest_round_trip(): void
    {
        $this->assertSame('-1', (string) ini_get('serialize_precision'));
    }

    public function test_money_values_encode_exactly_as_the_browser_would_send_them(): void
    {
        $value = round(9020.17 * 0.04, 2);          // 3rd-schedule retail base
        $tax = round($value * 18 / 100, 2);         // 18% of it

        $json = json_encode([
            'valueSalesExcludingST' => (float) $value,
            'salesTaxApplicable' => (float) $tax,
        ]);

        $this->assertSame('{"valueSalesExcludingST":360.81,"salesTaxApplicable":64.95}', $json);
        $this->assertStringNotContainsString('0000000', $json);
    }

    public function test_a_whole_item_payload_carries_no_binary_expansion(): void
    {
        $item = [
            'quantity' => (float) round(0.04, 4),
            'discount' => (float) round(0.0, 2),
            'totalValues' => (float) round(360.81 + 64.95, 2),
            'valueSalesExcludingST' => (float) round(360.81, 2),
            'salesTaxApplicable' => (float) round(64.95, 2),
            'fixedNotifiedValueOrRetailPrice' => (float) round(360.81, 2),
        ];

        $this->assertSame(
            '{"quantity":0.04,"discount":0,"totalValues":425.76,"valueSalesExcludingST":360.81,"salesTaxApplicable":64.95,"fixedNotifiedValueOrRetailPrice":360.81}',
            json_encode($item)
        );
    }
}
