<?php

namespace Tests\Unit;

use App\Models\PricingPlan;
use App\Services\PosPublicQuoteService;
use Tests\TestCase;

class PosPublicQuoteTest extends TestCase
{
    public function test_suggestion_never_removes_a_current_feature_or_limit(): void
    {
        $business = (new PricingPlan)->forceFill(['restaurant_enabled' => true, 'hazri_enabled' => true, 'user_limit' => 7, 'invoice_limit' => -1]);
        $unlimited = (new PricingPlan)->forceFill(['restaurant_enabled' => true, 'hazri_enabled' => true, 'user_limit' => 12, 'invoice_limit' => -1]);
        $this->assertTrue(PosPublicQuoteService::preservesPackage($business, $unlimited));
        $unlimited->hazri_enabled = false;
        $this->assertFalse(PosPublicQuoteService::preservesPackage($business, $unlimited));
        $unlimited->hazri_enabled = true;
        $unlimited->user_limit = 6;
        $this->assertFalse(PosPublicQuoteService::preservesPackage($business, $unlimited));
        $unlimited->user_limit = -1;
        $unlimited->invoice_limit = 100000;
        $this->assertFalse(PosPublicQuoteService::preservesPackage($business, $unlimited));
        $unlimited->invoice_limit = -1;
        $this->assertTrue(PosPublicQuoteService::preservesPackage($business, $unlimited));
        $business->max_users = 500; // DI-only column: not a PRA package limit.
        $unlimited->max_users = 1;
        $this->assertTrue(PosPublicQuoteService::preservesPackage($business, $unlimited));
        $business->max_terminals = null;
        $unlimited->max_terminals = -1;
        $this->assertTrue(PosPublicQuoteService::preservesPackage($business, $unlimited));
    }
}
