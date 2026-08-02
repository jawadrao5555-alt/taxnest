<?php

namespace Tests\Unit;

use App\Services\PkPhone;
use PHPUnit\Framework\TestCase;

/**
 * PK phone normalization for wa.me deep links (Task 136).
 * wa.me needs full international digits, no '+', '00' or leading local zero.
 */
class PkPhoneTest extends TestCase
{
    public function test_local_mobile_formats_normalize_to_92(): void
    {
        $this->assertSame('923001234567', PkPhone::normalize('0300-1234567'));
        $this->assertSame('923001234567', PkPhone::normalize('03001234567'));
        $this->assertSame('923001234567', PkPhone::normalize('0300 123 4567'));
        $this->assertSame('923001234567', PkPhone::normalize('(0300) 1234567'));
    }

    public function test_international_formats_normalize(): void
    {
        $this->assertSame('923001234567', PkPhone::normalize('+92 300 1234567'));
        $this->assertSame('923001234567', PkPhone::normalize('923001234567'));
        $this->assertSame('923001234567', PkPhone::normalize('0092 300 1234567'));
    }

    public function test_92_plus_stray_zero_typo_is_fixed(): void
    {
        // "+92 0300 1234567" → 9203001234567 (13 digits) → stray zero dropped
        $this->assertSame('923001234567', PkPhone::normalize('+92 0300 1234567'));
    }

    public function test_bare_mobile_without_zero(): void
    {
        $this->assertSame('923001234567', PkPhone::normalize('3001234567'));
    }

    public function test_landline_formats(): void
    {
        // Lahore landline: 042-35761234 → 924235761234
        $this->assertSame('924235761234', PkPhone::normalize('042-35761234'));
        $this->assertSame('92213456789', PkPhone::normalize('021-3456789'));
    }

    public function test_foreign_numbers_pass_through(): void
    {
        $this->assertSame('971501234567', PkPhone::normalize('971 50 123 4567'));
        $this->assertSame('447700900123', PkPhone::normalize('+44 7700 900123'));
    }

    public function test_unroutable_inputs_return_null(): void
    {
        $this->assertNull(PkPhone::normalize(null));
        $this->assertNull(PkPhone::normalize(''));
        $this->assertNull(PkPhone::normalize('abc'));
        $this->assertNull(PkPhone::normalize('12345'));
        $this->assertNull(PkPhone::normalize('0300'));              // too short
        $this->assertNull(PkPhone::normalize('92123456'));          // 92 + too few
        $this->assertNull(PkPhone::normalize('9212345678901234'));  // 16 digits — too long
    }

    public function test_wa_url_encodes_message(): void
    {
        $url = PkPhone::waUrl('923001234567', "Invoice INV-1\nView: https://x.test/share");

        $this->assertStringStartsWith('https://wa.me/923001234567?text=', $url);
        $this->assertStringContainsString('Invoice%20INV-1%0AView%3A%20https%3A%2F%2Fx.test%2Fshare', $url);
    }
}
