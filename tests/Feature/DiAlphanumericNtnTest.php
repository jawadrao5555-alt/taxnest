<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Services\FbrService;
use Tests\TestCase;

class DiAlphanumericNtnTest extends TestCase
{
    public function test_payload_identifiers_preserve_letter_ntns_and_existing_numeric_identifiers(): void
    {
        $service = new FbrService();
        $format = new \ReflectionMethod($service, 'formatNtnCnic');
        foreach ([
            'A123456' => 'A123456', 'A123456-7' => 'A123456',
            ' a123456 ' => 'A123456', '1234567-8' => '1234567',
            '12345-1234567-1' => '1234512345671', '' => '',
        ] as $input => $expected) {
            $this->assertSame($expected, $format->invoke($service, $input));
        }
    }

    public function test_letter_ntn_buyers_are_registered_in_both_entry_and_payload_flows(): void
    {
        $service = new FbrService();
        $detect = new \ReflectionMethod($service, 'determineBuyerRegistrationType');
        foreach (['A123456', 'A123456-7', '1234567', '1234512345671'] as $ntn) {
            $this->assertSame('Registered', $detect->invoke($service, $ntn));
            $this->assertSame('Registered', InvoiceController::detectBuyerRegistrationType($ntn));
        }
        $this->assertSame('Unregistered', $detect->invoke($service, null));
        $this->assertSame('Unregistered', InvoiceController::detectBuyerRegistrationType(null));
    }
}
