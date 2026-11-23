<?php
namespace Tests\Unit\Hr;
use PHPUnit\Framework\TestCase;
use App\Support\Hr\LeaveAndIncidentRules;
final class LeaveAndIncidentRulesTest extends TestCase {
 public function test_fines_need_explanation_approval_and_legal_permission(): void {
  self::assertSame(2.0,LeaveAndIncidentRules::remaining(3,1));
  self::assertFalse(LeaveAndIncidentRules::canFinalizeFine(false,true,true,500));
  self::assertFalse(LeaveAndIncidentRules::canFinalizeFine(true,true,false,500));
  self::assertTrue(LeaveAndIncidentRules::canFinalizeFine(true,true,true,500));
 }
}