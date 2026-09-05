<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Services\DistributorDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributorDiscountServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(string $product): array
    {
        $agent=Agent::create(['name'=>'D','email'=>uniqid().'@example.test','status'=>'active','is_active'=>true,'discount_percent'=>10]);
        $company=Company::create(['name'=>'Shop','ntn'=>'NTN-'.uniqid(),'product_type'=>$product,'agent_id'=>$agent->id]);
        $plan=PricingPlan::create([
            'name'=>'Annual '.uniqid(),
            'product_type'=>$product,
            'price'=>10000,
            'price_yearly'=>12000,
            'is_trial'=>false,
            'invoice_limit'=>-1,
        ]);
        return [$agent,$company,$plan];
    }

    public function test_shared_quote_discounts_first_annual_package_for_all_products(): void
    {
        foreach (['di','pos','fbrpos'] as $product) {
            [, $company,$plan]=$this->fixtures($product);
            $q=DistributorDiscountService::quote($company,$plan,'annual');
            $this->assertSame(10.0,(float)$q['discount_percent']);
            $this->assertSame(round($q['gross_package_price']*.9+$q['undiscounted_addon_amount'],2),(float)$q['net_quote']);
            $this->assertSame($product,$q['product_type']);
        }
    }

    public function test_direct_renewal_and_existing_pending_discount_receive_no_new_discount(): void
    {
        [, $company,$plan]=$this->fixtures('pos');
        $direct=Company::create(['name'=>'Direct','ntn'=>'NTN-'.uniqid(),'product_type'=>'pos']);
        $this->assertSame(0.0,(float)DistributorDiscountService::quote($direct,$plan,'annual')['discount_percent']);

        $first=DistributorDiscountService::quote($company,$plan,'annual');
        PaymentProof::create(['company_id'=>$company->id,'pricing_plan_id'=>$plan->id,'billing_cycle'=>'annual','amount'=>$first['net_quote'],'proof_path'=>'test-proof.jpg','status'=>'pending','distributor_quote_snapshot'=>$first,'distributor_net_amount'=>$first['net_quote']]);
        $this->assertSame(0.0,(float)DistributorDiscountService::quote($company,$plan,'annual')['discount_percent']);

        PaymentProof::where('company_id',$company->id)->update(['status'=>'verified','verified_at'=>now()]);
        $this->assertSame(0.0,(float)DistributorDiscountService::quote($company,$plan,'annual')['discount_percent']);
    }

    public function test_snapshot_math_is_server_owned_and_remains_valid_after_allowance_change(): void
    {
        [$agent,$company,$plan]=$this->fixtures('di');
        $snapshot=DistributorDiscountService::quote($company,$plan,'annual');
        $proof=PaymentProof::create(['company_id'=>$company->id,'pricing_plan_id'=>$plan->id,'billing_cycle'=>'annual','amount'=>$snapshot['net_quote'],'proof_path'=>'test-proof.jpg','status'=>'pending','distributor_quote_snapshot'=>$snapshot,'distributor_net_amount'=>$snapshot['net_quote']]);
        $agent->update(['discount_percent'=>0]);
        $this->assertEquals($snapshot,$proof->fresh()->distributor_quote_snapshot);
        $this->assertNull(DistributorDiscountService::validateSnapshot($snapshot,$company,$plan,'annual'));
    }
}