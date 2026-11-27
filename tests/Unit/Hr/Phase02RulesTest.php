<?php
namespace Tests\Unit\Hr;
use PHPUnit\Framework\TestCase;
use App\Support\Hr\AttendanceRules;
final class Phase02RulesTest extends TestCase {
 public function test_cutoffs_are_inclusive_at_five_and_thirty_minutes(): void {
  $s=new \DateTimeImmutable('2026-11-23T09:00:00+05:00');
  self::assertSame('on_time',AttendanceRules::classify($s->modify('+5 minutes'),$s)['status']);
  self::assertSame('late',AttendanceRules::classify($s->modify('+5 minutes +1 second'),$s)['status']);
  self::assertSame('late',AttendanceRules::classify($s->modify('+30 minutes'),$s)['status']);
  self::assertSame('absent_cutoff',AttendanceRules::classify($s->modify('+30 minutes +1 second'),$s)['status']);
 }
}