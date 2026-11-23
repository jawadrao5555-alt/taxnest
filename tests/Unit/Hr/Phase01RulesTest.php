<?php
namespace Tests\Unit\Hr;
use PHPUnit\Framework\TestCase;
use App\Support\Hr\TenantAccess;
final class TenantAccessTest extends TestCase {
 public function test_tenant_isolation_and_role_grants(): void {
  self::assertTrue(TenantAccess::allows(10,10,'owner','payroll.approve'));
  self::assertFalse(TenantAccess::allows(10,11,'owner','payroll.approve'));
  self::assertFalse(TenantAccess::allows(10,10,'employee','payroll.read'));
  self::assertFalse(TenantAccess::allows(10,10,'owner','payroll.read',false));
 }
}