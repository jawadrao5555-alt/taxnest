<?php
namespace Tests\Unit\Hr;
use PHPUnit\Framework\TestCase;
use App\Support\Hr\DutyLocationPolicy;
final class DutyLocationPolicyTest extends TestCase {
 public function test_consent_and_shift_end_stop_collection(): void {
  $s=new \DateTimeImmutable('2026-11-23T09:00:00+05:00');$e=$s->modify('+8 hours');
  self::assertTrue(DutyLocationPolicy::canCollect($s,$s,$e,true,true));
  self::assertFalse(DutyLocationPolicy::canCollect($e,$s,$e,true,true));
  self::assertFalse(DutyLocationPolicy::canCollect($s,$s,$e,false,true));
  self::assertSame('unavailable',DutyLocationPolicy::observationStatus(false,null,null,100));
 }
}