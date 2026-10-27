<?php
namespace Tests\Unit;
use App\Services\PosCustomerPhoneIdentity;
use PHPUnit\Framework\TestCase;
class PosCustomerPhoneIdentityTest extends TestCase
{
    public function test_equivalent_pk_formats_share_identity_and_existing_create_mutex_keys(): void
    {
        $formats = ['0300-0000001', '+92 300 0000001', '00923000000001', '3000000001', '+92 03000000001'];
        $keys = PosCustomerPhoneIdentity::creationLockKeys(41, $formats[0]);
        foreach ($formats as $format) {
            $this->assertSame('923000000001', PosCustomerPhoneIdentity::canonical($format));
            $this->assertContains('poscust:41:'.preg_replace('/\D+/', '', $format), $keys);
        }
        $this->assertSame([], array_intersect($keys, PosCustomerPhoneIdentity::creationLockKeys(42, $formats[0])));
    }
}
