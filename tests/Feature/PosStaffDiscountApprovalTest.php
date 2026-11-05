<?php

namespace Tests\Feature;

use App\Models\{Company, User, PosTransaction};
use App\Services\{PosFeatureService, PosStaffDiscount};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class PosStaffDiscountApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $company = Company::create(['name'=>'Synthetic staff shop', 'ntn'=>uniqid(), 'email'=>uniqid().'@example.test',
            'product_type'=>'pos', 'status'=>'approved', 'company_status'=>'active', 'is_internal_account'=>true,
            'pos_integration_mode'=>'pra', 'pra_reporting_enabled'=>false, 'pos_tax_rate_cash'=>16, 'pos_tax_rate_card'=>5]);
        $company->forceFill(['pos_staff_discount_enabled'=>true])->save();
        $plan=\App\Models\PricingPlan::create(['name'=>'Synthetic staff plan','product_type'=>'pos','price'=>100,'invoice_limit'=>100]);
        \App\Models\Subscription::create(['company_id'=>$company->id,'pricing_plan_id'=>$plan->id,'active'=>true,
            'start_date'=>now()->subDay()->toDateString(),'end_date'=>now()->addDays(30)->toDateString()]);
        $owner = User::create(['name'=>'Owner', 'email'=>uniqid().'@example.test', 'password'=>bcrypt('Synthetic123!'),
            'company_id'=>$company->id, 'role'=>'company_admin', 'pos_role'=>'pos_admin', 'is_active'=>true]);
        $cashier = User::create(['name'=>'Employee', 'email'=>uniqid().'@example.test', 'password'=>bcrypt('Synthetic123!'),
            'company_id'=>$company->id, 'role'=>'staff', 'pos_role'=>'pos_cashier', 'is_active'=>true]);
        PosFeatureService::flushGateCaches(); Http::preventStrayRequests();
        return [$company, $owner, $cashier];
    }

    private function payload(User $employee): array
    {
        $uuid=(string) Str::uuid();
        return ['items'=>[['name'=>'Fictional meal', 'quantity'=>1, 'unit_price'=>1000, '_manual'=>true]],
            'payment_method'=>'cash', 'discount_type'=>'percentage', 'discount_value'=>10, 'order_type'=>'takeaway',
            'offline_uuid'=>$uuid, 'staff_discount'=>['employee_id'=>$employee->id, 'kind'=>'family', 'reason'=>'Family meal', 'request_uuid'=>$uuid]];
    }

    private function propose(User $cashier, array $payload): int
    {
        $response=$this->actingAs($cashier, 'pos')->postJson('/pos/invoice/store', $payload);
        $this->assertSame(202,$response->status(),$response->getContent());
        return (int) $response->assertJsonPath('approval_pending', true)->json('request_id');
    }

    private function approve(User $owner, int $id, string $type='percentage', float $value=20): void
    {
        $this->actingAs($owner, 'pos')->postJson('/pos/staff-discounts/'.$id.'/review',
            ['revision'=>1, 'action'=>'approve', 'discount_type'=>$type, 'discount_value'=>$value])->assertOk();
    }

    public function test_pending_proposal_is_not_a_sale_and_owner_revision_is_applied_once_on_retry(): void
    {
        [$company,$owner,$cashier]=$this->shop(); $payload=$this->payload($cashier);
        $id=$this->propose($cashier,$payload); $this->assertSame($id,$this->propose($cashier,$payload));
        $this->assertSame(0,PosTransaction::count()); $this->assertSame(1,DB::table('pos_staff_discount_requests')->count());
        $this->approve($owner,$id,'amount',125);
        unset($payload['staff_discount']); $payload+=['staff_discount_request_id'=>$id,'staff_discount_revision'=>2];
        $first=$this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$payload)->assertOk();
        $bill=PosTransaction::firstOrFail();
        $this->assertEquals(125,$bill->discount_amount); $this->assertEquals(1015,$bill->total_amount);
        $this->assertSame('pra',$bill->invoice_mode); $this->assertNull($bill->pra_status); $this->assertSame($cashier->id,$bill->created_by);
        $this->postJson('/pos/invoice/store',$payload)->assertOk()->assertJsonPath('transaction_id',$first->json('transaction_id'));
        $this->assertSame(1,PosTransaction::count()); $this->assertSame('finalized',DB::table('pos_staff_discount_requests')->value('status'));
        $this->assertSame(3,DB::table('pos_staff_discount_events')->count());
        $this->assertSame('finalized',json_decode(DB::table('pos_staff_discount_events')->where('action','finalized')->value('snapshot'),true)['status']);
        Http::assertNothingSent();
    }

    public function test_pending_rejected_changed_cart_and_stale_revision_cannot_finalize(): void
    {
        [$company,$owner,$cashier]=$this->shop(); $payload=$this->payload($cashier); $id=$this->propose($cashier,$payload);
        unset($payload['staff_discount']); $payload+=['staff_discount_request_id'=>$id,'staff_discount_revision'=>1];
        $this->postJson('/pos/invoice/store',$payload)->assertUnprocessable();
        $this->approve($owner,$id); $payload['staff_discount_revision']=2;
        $payload['items'][0]['quantity']=2;
        $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$payload)->assertUnprocessable();
        $payload['items'][0]['quantity']=1; $payload['staff_discount_revision']=1;
        $this->postJson('/pos/invoice/store',$payload)->assertUnprocessable();
        $this->actingAs($owner,'pos')->postJson('/pos/staff-discounts/'.$id.'/review',['action'=>'reject','revision'=>2])->assertOk();
        $payload['staff_discount_revision']=3;
        $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$payload)->assertUnprocessable();
        $this->assertSame(0,PosTransaction::count());
    }

    public function test_role_and_tenant_checks_protect_review_employee_and_request_lists(): void
    {
        [$company,$owner,$cashier]=$this->shop(); [$foreign,$foreignOwner,$foreignEmployee]=$this->shop();
        $payload=$this->payload($cashier); $id=$this->propose($cashier,$payload);
        $review=['revision'=>1,'action'=>'approve','discount_type'=>'percentage','discount_value'=>10];
        $this->actingAs($cashier,'pos')->postJson('/pos/staff-discounts/'.$id.'/review',$review)->assertForbidden();
        $this->actingAs($foreignOwner,'pos')->postJson('/pos/staff-discounts/'.$id.'/review',$review)->assertNotFound();
        $this->getJson('/pos/staff-discounts')->assertOk()->assertJsonCount(0,'requests');
        $payload['staff_discount']['employee_id']=$foreignEmployee->id;
        $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$payload)->assertUnprocessable();
        $this->assertSame('pending',DB::table('pos_staff_discount_requests')->value('status'));
    }

    public function test_review_uses_revision_and_records_all_changes_without_duplicate_approval(): void
    {
        [$company,$owner,$cashier]=$this->shop(); $id=$this->propose($cashier,$this->payload($cashier));
        $this->approve($owner,$id);
        $this->postJson('/pos/staff-discounts/'.$id.'/review',['revision'=>1,'action'=>'approve','discount_type'=>'amount','discount_value'=>200])->assertStatus(409);
        $this->postJson('/pos/staff-discounts/'.$id.'/review',['revision'=>2,'action'=>'approve','discount_type'=>'amount','discount_value'=>150])->assertOk();
        $this->assertSame(3,DB::table('pos_staff_discount_events')->count());
        $this->assertEquals(10,DB::table('pos_staff_discount_requests')->value('proposed_value'));
        $this->assertEquals(150,DB::table('pos_staff_discount_requests')->value('approved_value'));
    }

    public function test_feature_off_ordinary_sales_and_offline_staff_guards_preserve_existing_behavior(): void
    {
        [$company,$owner,$cashier]=$this->shop(); $payload=$this->payload($cashier);
        $company->forceFill(['pos_staff_discount_enabled'=>false])->save();
        $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$payload)->assertUnprocessable();
        unset($payload['staff_discount']); $this->postJson('/pos/invoice/store',$payload)->assertOk();
        $company->forceFill(['pos_staff_discount_enabled'=>true])->save(); $payload=$this->payload($cashier); $payload['offline_queued_at']=now()->toIso8601String();
        $this->postJson('/pos/invoice/store',$payload)->assertUnprocessable();
        $this->assertSame(1,PosTransaction::count()); $this->assertSame(0,DB::table('pos_staff_discount_requests')->count());
    }

    public function test_invalid_discount_bounds_and_line_discount_eligible_amount_are_enforced(): void
    {
        [$company,$owner,$cashier]=$this->shop();
        foreach ([['percentage',0],['percentage',100],['amount',0],['amount',1001]] as [$type,$value]) {
            $p=$this->payload($cashier); $p['discount_type']=$type; $p['discount_value']=$value;
            $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$p)->assertUnprocessable();
        }
        $p=$this->payload($cashier); $p['items'][0]['item_discount_type']='amount'; $p['items'][0]['item_discount_value']=100;
        $p['discount_type']='amount'; $p['discount_value']=950;
        $this->postJson('/pos/invoice/store',$p)->assertUnprocessable();
        $this->assertSame(0,PosTransaction::count());
    }

    public function test_request_key_cannot_bypass_approval_by_omitting_staff_fields(): void
    {
        [$company,$owner,$cashier]=$this->shop(); $p=$this->payload($cashier); $id=$this->propose($cashier,$p);
        unset($p['staff_discount']);
        $this->postJson('/pos/invoice/store',$p)->assertUnprocessable();
        $this->assertSame(0,PosTransaction::count());
        $p+=['staff_discount_request_id'=>$id,'staff_discount_revision'=>2]; $p['offline_uuid']=(string)Str::uuid();
        $this->postJson('/pos/invoice/store',$p)->assertUnprocessable();
    }

    public function test_full_supported_percentage_range_is_reviewable_and_feature_toggle_is_owner_only(): void
    {
        [$company,$owner,$cashier]=$this->shop();
        $this->actingAs($cashier,'pos')->post('/pos/staff-discounts/settings',['enabled'=>0])->assertForbidden();
        foreach([1,99] as $value) {
            $p=$this->payload($cashier); $p['discount_value']=$value; $id=$this->propose($cashier,$p);
            $this->approve($owner,$id,'percentage',$value);
            unset($p['staff_discount']); $p+=['staff_discount_request_id'=>$id,'staff_discount_revision'=>2];
            $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$p)->assertOk();
        }
        $this->assertSame(2,PosTransaction::count());
        $this->assertEquals(990,PosTransaction::orderByDesc('id')->first()->discount_amount);
    }

    public function test_owner_view_and_cashier_resume_are_reachable_and_request_detail_is_private(): void
    {
        [$company,$owner,$cashier]=$this->shop(); [$foreign,$foreignOwner]=$this->shop();
        $id=$this->propose($cashier,$this->payload($cashier));
        $this->actingAs($owner,'pos')->get('/pos/staff-discounts')->assertOk()->assertSee('Fictional meal')->assertSee('Approve / revise');
        $this->actingAs($cashier,'pos')->get('/pos/staff-discounts')->assertOk()->assertSee('Resume original cart')->assertDontSee('Approve / revise');
        $this->getJson('/pos/staff-discounts/'.$id)->assertOk()->assertJsonPath('request.id',$id)->assertHeader('Cache-Control','no-store, private');
        $this->actingAs($foreignOwner,'pos')->getJson('/pos/staff-discounts/'.$id)->assertNotFound();
    }

    public function test_cashier_reporting_policy_is_preserved_and_no_fiscal_call_precedes_approval(): void
    {
        [$company,$owner,$cashier]=$this->shop();
        $company->forceFill(['agent_enabled'=>true,'pra_connection_mode'=>'fiscal_device'])->save();
        $cashier->forceFill(['pra_reporting_enabled'=>true])->save();
        $p=$this->payload($cashier); $id=$this->propose($cashier,$p); Http::assertNothingSent();
        $this->approve($owner,$id,'percentage',10);
        unset($p['staff_discount']); $p+=['staff_discount_request_id'=>$id,'staff_discount_revision'=>2];
        $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$p)->assertOk();
        $bill=PosTransaction::firstOrFail(); $this->assertSame('pending',$bill->pra_status); $this->assertSame($cashier->id,$bill->created_by);
        Http::assertNothingSent();
    }

    public function test_inclusive_and_card_save_follow_canonical_tax_math_without_a_second_discount(): void
    {
        [$company,$owner,$cashier]=$this->shop();
        foreach(['inclusive','inclusive_card_save'] as $mode) {
            $company->forceFill(['pos_tax_pricing_mode'=>$mode,'pos_tax_inclusive'=>true])->save();
            $p=$this->payload($cashier); $p['payment_method']='card'; $id=$this->propose($cashier,$p);
            $this->approve($owner,$id,'amount',125);
            unset($p['staff_discount']); $p+=['staff_discount_request_id'=>$id,'staff_discount_revision'=>2];
            $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$p)->assertOk();
            $bill=PosTransaction::orderByDesc('id')->firstOrFail();
            $expected=\App\Services\PosTaxMath::inclusiveHeader(1000,1000,125,5,$mode==='inclusive_card_save'?16:null);
            $this->assertEquals($expected['total_amount'],$bill->total_amount); $this->assertEquals(125,$bill->discount_amount);
            $this->assertEquals($expected['tax_amount'],$bill->tax_amount);
        }
    }

    public function test_pending_held_order_cannot_be_paid_elsewhere_and_changed_held_items_invalidate_approval(): void
    {
        [$company,$owner,$cashier]=$this->shop();
        $company->forceFill(['restaurant_mode'=>true,'feature_flags'=>PosFeatureService::defaultsForCategory('restaurant')])->save();
        PosFeatureService::flushGateCaches();
        $order=\App\Models\RestaurantOrder::create(['company_id'=>$company->id,'created_by'=>$cashier->id,'order_number'=>'SYN-STAFF-HOLD',
            'status'=>'held','order_type'=>'takeaway','subtotal'=>1000,'total_amount'=>1160,'source'=>'cashier']);
        $item=\App\Models\RestaurantOrderItem::create(['order_id'=>$order->id,'item_type'=>'product','item_name'=>'Fictional meal',
            'quantity'=>1,'unit_price'=>1000,'subtotal'=>1000,'is_tax_exempt'=>false]);
        $p=$this->payload($cashier); $p['recalled_order_id']=$order->id; $id=$this->propose($cashier,$p);
        $this->actingAs($owner,'pos')->postJson('/pos/restaurant/orders/'.$order->id.'/pay',['payment_method'=>'cash'])->assertUnprocessable();
        $this->approve($owner,$id); $item->update(['quantity'=>2,'subtotal'=>2000]);
        unset($p['staff_discount']); $p+=['staff_discount_request_id'=>$id,'staff_discount_revision'=>2];
        $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$p)->assertUnprocessable();
        $this->assertSame('held',$order->fresh()->status); $this->assertSame(0,PosTransaction::count());
        $this->postJson('/pos/staff-discounts/'.$id.'/review',['action'=>'cancel','revision'=>2])->assertOk();
        $this->assertSame('cancelled',DB::table('pos_staff_discount_requests')->value('status'));
    }

    public function test_claim_rechecks_current_company_policy_after_preparation(): void
    {
        [$company,$owner,$cashier]=$this->shop(); $p=$this->payload($cashier); $id=$this->propose($cashier,$p); $this->approve($owner,$id);
        $items=json_decode(DB::table('pos_staff_discount_requests')->where('id',$id)->value('order_snapshot'),true)['items'];
        unset($p['staff_discount']); $p+=['staff_discount_request_id'=>$id,'staff_discount_revision'=>2];
        $this->actingAs($cashier,'pos');
        $request=\Illuminate\Http\Request::create('/pos/invoice/store','POST',$p);
        $request->setLaravelSession(app('session')->driver());
        $approval=app(PosStaffDiscount::class)->prepare($request,$company,$items,1000);
        $company->forceFill(['pos_tax_pricing_mode'=>'inclusive'])->save();
        try {PosStaffDiscount::claim($approval,$company->id); $this->fail('Changed policy must invalidate the approval');}
        catch(\Illuminate\Validation\ValidationException $e) {$this->assertArrayHasKey('staff_discount',$e->errors());}
        $this->assertSame('approved',DB::table('pos_staff_discount_requests')->where('id',$id)->value('status'));
        $this->assertSame(0,PosTransaction::count());
    }

    public function test_kitchen_progress_does_not_invalidate_unchanged_approved_order(): void
    {
        [$company,$owner,$cashier]=$this->shop();
        $order=\App\Models\RestaurantOrder::create(['company_id'=>$company->id,'created_by'=>$cashier->id,'order_number'=>'SYN-STAFF-READY',
            'status'=>'held','order_type'=>'takeaway','subtotal'=>1000,'total_amount'=>1160,'source'=>'cashier']);
        $item=\App\Models\RestaurantOrderItem::create(['order_id'=>$order->id,'item_type'=>'product','item_name'=>'Fictional meal',
            'quantity'=>1,'unit_price'=>1000,'subtotal'=>1000,'is_tax_exempt'=>false]);
        $p=$this->payload($cashier); $p['recalled_order_id']=$order->id; $id=$this->propose($cashier,$p); $this->approve($owner,$id);
        $order->update(['status'=>'ready','kitchen_ready_at'=>now()]); $item->update(['kot_printed_at'=>now()]);
        unset($p['staff_discount']); $p+=['staff_discount_request_id'=>$id,'staff_discount_revision'=>2];
        $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$p)->assertOk();
        $this->assertSame('completed',$order->fresh()->status); $this->assertSame(1,PosTransaction::count());
    }

    public function test_cashier_cannot_reserve_another_cashiers_assigned_waiter_order(): void
    {
        [$company,$owner,$cashier]=$this->shop();
        $order=\App\Models\RestaurantOrder::create(['company_id'=>$company->id,'created_by'=>$owner->id,'assigned_cashier_id'=>$owner->id,
            'order_number'=>'SYN-STAFF-ASSIGNEE','status'=>'held','order_type'=>'takeaway','subtotal'=>1000,'total_amount'=>1160,'source'=>'waiter']);
        $p=$this->payload($cashier); $p['incoming_order_id']=$order->id;
        $this->actingAs($cashier,'pos')->postJson('/pos/invoice/store',$p)->assertForbidden();
        $this->assertSame(0,DB::table('pos_staff_discount_requests')->count());
    }
}
