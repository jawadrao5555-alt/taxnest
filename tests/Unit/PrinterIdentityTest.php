<?php

namespace Tests\Unit;

use App\Support\KotPrintState;
use App\Support\PrinterIdentity;
use PHPUnit\Framework\TestCase;

class PrinterIdentityTest extends TestCase
{
    public function test_normalize_folds_case_and_interior_space(): void
    {
        $this->assertSame('kitchen printer', PrinterIdentity::normalize('  Kitchen   Printer '));
        $this->assertTrue(PrinterIdentity::same('Kitchen Printer', 'kitchen  printer'));
        $this->assertFalse(PrinterIdentity::same('Kitchen', 'Kitchen-LAN'));
        $this->assertFalse(PrinterIdentity::same('', 'Kitchen'));
    }

    public function test_reported_has_and_stale_saved_name(): void
    {
        $reported = [
            ['name' => 'Kitchen Printer'],
            ['name' => 'Counter-80'],
        ];
        $this->assertTrue(PrinterIdentity::reportedHas($reported, 'kitchen  printer'));
        $this->assertFalse(PrinterIdentity::reportedHas($reported, 'Back Office'));
        $this->assertTrue(PrinterIdentity::savedNameLooksStale('Old Kitchen', $reported));
        $this->assertFalse(PrinterIdentity::savedNameLooksStale('Kitchen Printer', $reported));
        $this->assertFalse(PrinterIdentity::savedNameLooksStale('Kitchen Printer', []));
    }

    public function test_print_states_cover_the_operator_contract(): void
    {
        $this->assertSame(KotPrintState::PRINTING, KotPrintState::forJob((object) ['status' => 'printing'])['key']);
        $this->assertSame(KotPrintState::PRINTING, KotPrintState::forJob((object) ['status' => 'local'])['key']);
        $this->assertSame(KotPrintState::PENDING, KotPrintState::forJob((object) ['status' => 'pending'])['key']);
        $this->assertSame(KotPrintState::PRINTED, KotPrintState::forJob((object) ['status' => 'done'])['key']);
        $this->assertSame(KotPrintState::RECOVERED, KotPrintState::forJob((object) [
            'status' => 'done',
            'error' => 'Superseded: shop PC confirmed this kitchen slip.',
        ])['key']);
        $this->assertSame(KotPrintState::ACTION_REQUIRED, KotPrintState::forJob((object) [
            'status' => 'failed',
            'error' => 'unconfirmed_after_print_content_fetched',
        ])['key']);
    }
}
