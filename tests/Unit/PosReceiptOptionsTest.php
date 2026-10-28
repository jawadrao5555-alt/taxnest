<?php

namespace Tests\Unit;

use App\Support\PosReceiptOptions;
use PHPUnit\Framework\TestCase;

class PosReceiptOptionsTest extends TestCase
{
    public function test_absent_and_inherit_values_leave_legacy_css_and_visibility_intact(): void
    {
        $this->assertSame('', PosReceiptOptions::css([], '80mm'));
        $this->assertSame('', PosReceiptOptions::css(['font_family' => 'inherit', 'spacing' => 'inherit'], '58mm'));
        $this->assertFalse(PosReceiptOptions::visible([], 'show_logo', false));
        $this->assertTrue(PosReceiptOptions::visible([], 'show_terminal'));
        $this->assertFalse(PosReceiptOptions::visible([], 'show_item_serial', false));
        $this->assertTrue(PosReceiptOptions::visible(['show_logo' => 'show'], 'show_logo', false));
    }

    public function test_css_only_accepts_allowlisted_values_and_extra_qr_does_not_target_fiscal(): void
    {
        $this->assertSame('', PosReceiptOptions::css(['logo_size' => '1;bad', 'font_family' => 'x</style>', 'total_size' => '9999'], '58mm'));
        $css = PosReceiptOptions::css(['extra_qr_size' => 'large', 'total_bold' => 'bold', 'total_size' => 'large'], '58mm');
        $this->assertStringContainsString('.receipt-extra-qr img', $css);
        $this->assertStringNotContainsString('.qr-code img', $css);
        $this->assertStringContainsString('font-size:17px', $css);
        $this->assertSame('Y-m-d H:i', PosReceiptOptions::dateFormat(['date_format' => 'iso']));
    }
}
