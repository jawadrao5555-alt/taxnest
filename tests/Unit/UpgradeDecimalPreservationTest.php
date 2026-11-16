<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UpgradeDecimalPreservationTest extends TestCase
{
    public function test_only_trailing_decimal_zeroes_are_normalized_without_losing_precision(): void
    {
        require_once __DIR__.'/../native/lib/upgrade_decimal.php';
        foreach ([['17.00', '17.0000'], ['29.00', '29.0000'], ['-0.1200', '-0.12'], ['99999999999999.9999', '99999999999999.9999']] as [$before, $after]) {
            self::assertSame(rcUpgradeDecimal($before), rcUpgradeDecimal($after));
        }
        foreach ([['17.0000', '17.0001'], ['99999999999999.9998', '99999999999999.9999'], ['0.0000', '0.0001'], ['17', '-17']] as [$before, $after]) {
            self::assertNotSame(rcUpgradeDecimal($before), rcUpgradeDecimal($after));
        }
        $this->expectException(\InvalidArgumentException::class);
        rcUpgradeDecimal('1e3');
    }
}
