<?php
namespace Tests\Unit\Hr;
use PHPUnit\Framework\TestCase;
use App\Support\Hr\PayrollRules;
final class Phase05RulesTest extends TestCase {
 public function test_payroll_and_perfect_attendance(): void {
  self::assertSame(55500.0,PayrollRules::net(50000,2000,4000,500,0));
  self::assertTrue(PayrollRules::perfectAttendanceBonusEligible(22,22,0,0,0,0));
  self::assertFalse(PayrollRules::perfectAttendanceBonusEligible(22,22,1,0,0,0));
 }
}