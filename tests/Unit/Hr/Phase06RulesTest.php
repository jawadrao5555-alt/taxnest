<?php
namespace Tests\Unit\Hr;
use PHPUnit\Framework\TestCase;
use App\Support\Hr\BiometricEventKey;
final class BiometricEventKeyTest extends TestCase {
 public function test_replays_are_stable_and_tenants_are_distinct(): void {
  self::assertSame(BiometricEventKey::make(1,'dev','evt'),BiometricEventKey::make(1,'dev','evt'));
  self::assertNotSame(BiometricEventKey::make(1,'dev','evt'),BiometricEventKey::make(2,'dev','evt'));
 }
}