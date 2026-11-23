<?php
namespace Tests\Unit\Hr;
use PHPUnit\Framework\TestCase;
use App\Support\Hr\ProductEntitlement;
final class Phase07RulesTest extends TestCase {
 public function test_hr_expiry_does_not_grant_other_products(): void {
  $s=new \DateTimeImmutable('2026-11-01T00:00:00+05:00');$e=$s->modify('+1 month');
  self::assertTrue(ProductEntitlement::active('hr',$s,$s,$e,true));
  self::assertFalse(ProductEntitlement::active('pos',$s,$s,$e,true));
  self::assertFalse(ProductEntitlement::active('hr',$e,$s,$e,true));
 }
}