<?php

namespace Tests\Unit;

use App\Services\InvoicePdfService;
use PHPUnit\Framework\TestCase;

/**
 * The invoice PDF prints the total in words under the totals box, and buyers
 * check that line against the figure — a wrong word is a disputed invoice.
 */
class AmountInWordsTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            [0, 'Rupees Zero Only'],
            [1, 'Rupees One Only'],
            [15, 'Rupees Fifteen Only'],
            [40, 'Rupees Forty Only'],
            [99, 'Rupees Ninety Nine Only'],
            [100, 'Rupees One Hundred Only'],
            [118, 'Rupees One Hundred Eighteen Only'],
            [1000, 'Rupees One Thousand Only'],
            [14750, 'Rupees Fourteen Thousand Seven Hundred Fifty Only'],
            [100000, 'Rupees One Hundred Thousand Only'],
            [1234567, 'Rupees One Million Two Hundred Thirty Four Thousand Five Hundred Sixty Seven Only'],
            [2000000000, 'Rupees Two Billion Only'],
        ];
    }

    /** @dataProvider amounts */
    public function test_whole_rupees(float $amount, string $expected): void
    {
        $this->assertSame($expected, InvoicePdfService::amountInWords($amount));
    }

    public function test_paisa_is_spelled_out_only_when_present(): void
    {
        $this->assertSame('Rupees Fourteen Thousand Seven Hundred Fifty Only', InvoicePdfService::amountInWords(14750.00));
        $this->assertSame('Rupees One Hundred and Fifty Paisa Only', InvoicePdfService::amountInWords(100.50));
        $this->assertSame('Rupees Ninety Nine and One Paisa Only', InvoicePdfService::amountInWords(99.01));
    }

    /** A rounding artefact must not print "and One Hundred Paisa". */
    public function test_almost_whole_amount_rounds_up_to_the_next_rupee(): void
    {
        $this->assertSame('Rupees One Hundred Only', InvoicePdfService::amountInWords(99.999));
    }

    public function test_a_credit_note_negative_reads_as_its_magnitude(): void
    {
        $this->assertSame('Rupees Five Hundred Only', InvoicePdfService::amountInWords(-500));
    }
}
