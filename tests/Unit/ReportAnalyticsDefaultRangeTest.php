<?php

namespace Tests\Unit;

use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\PosController;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReportAnalyticsDefaultRangeTest extends TestCase
{
    /**
     * A fresh analytics visit must show today in both isolated POS products.
     * Explicit date ranges remain caller-controlled.
     */
    public function test_pra_and_fbr_analytics_default_to_today(): void
    {
        foreach ([PosController::class => 'resolveReportRange', FbrPosController::class => 'resolveFbrReportRange'] as $controllerClass => $methodName) {
            $method = new \ReflectionMethod($controllerClass, $methodName);
            $method->setAccessible(true);
            $range = $method->invoke(new $controllerClass(), Request::create('/reports'));

            $this->assertTrue($range[0]->isStartOfDay(), $controllerClass . ' must start at today');
            $this->assertTrue($range[1]->isEndOfDay(), $controllerClass . ' must end at today');
            $this->assertSame(now()->toDateString(), $range[0]->toDateString());
            $this->assertSame(now()->toDateString(), $range[1]->toDateString());

            $explicit = $method->invoke(
                new $controllerClass(),
                Request::create('/reports', 'GET', ['from' => '2026-08-01', 'to' => '2026-08-10'])
            );
            $this->assertSame('2026-08-01', $explicit[0]->toDateString());
            $this->assertSame('2026-08-10', $explicit[1]->toDateString());
        }
    }
}