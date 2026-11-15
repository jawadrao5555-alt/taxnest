<?php
namespace Tests\Feature;
use App\Models\{Company,PricingPlan,Subscription,User};
use App\Services\{ProspectiveSubscriptionPolicy as Policy,ProspectiveReminderView as Reminder,TrialSubscriptionService,SubscriptionAssignmentService,SubscriptionAccessService};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Mail,Notification,Blade,Http};
use Tests\TestCase;
class ProspectiveReminderTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();Mail::fake();Notification::fake();Http::preventStrayRequests();$this->travelTo(Carbon::parse('2028-02-27 18:30:00','UTC'));}
 private function fixture():array {
  $c=Company::create(['name'=>'Synthetic reminder','email'=>'reminder@example.invalid','ntn'=>'REM-'.uniqid(),'product_type'=>'di','status'=>'approved','company_status'=>'active']);
  $p=PricingPlan::create(['name'=>'Selected reminder','product_type'=>'di','price'=>1000,'is_trial'=>false,'invoice_limit'=>2]);
  return [$c,$p,TrialSubscriptionService::ensureSelectedTrial($c->id,'di',$p->id)];
 }
 private function pay($c,$p):Subscription {$q=SubscriptionAssignmentService::computePrice($p,'annual',$c);return SubscriptionAssignmentService::assign($c->id,$p->id,'annual',paidRenewal:true,verifiedReceived:$q['final_price']);}
 public function test_unpaid_selected_trial_uses_trial_mail_once_never_paid_renewal_mail():void {
  [$c,$p,$s]=$this->fixture();$this->travelTo(Carbon::parse(Policy::display($s)['trial_ends_at'])->subDay());
  $this->artisan('trial:reminders')->assertSuccessful();$this->artisan('trial:reminders')->assertSuccessful();
  Mail::assertSent(\App\Mail\TrialReminderMail::class,1);
  $this->assertDatabaseHas('notifications',['company_id'=>$c->id,'type'=>'trial_reminder_day_1']);
  self::assertSame(0,\App\Models\Notification::where('company_id',$c->id)->where('type','like','sub_renewal_reminder_%')->count());
 }
 public function test_early_paid_trial_keeps_exact_deadline_without_payment_demand():void {
  [$c,$p,$trial]=$this->fixture();$paid=$this->pay($c,$p);$end=Policy::display($paid)['trial_ends_at'];$this->travelTo(Carbon::parse($end)->subDay());
  self::assertNotNull(SubscriptionAccessService::trialStatus($c)['days_left']);
  $v=Reminder::forCompany($c);self::assertSame('confirmed',$v['kind']);self::assertSame($end,$v['until']);self::assertFalse($v['show_cta']);
  $u=User::factory()->create(['company_id'=>$c->id]);$this->actingAs($u);
  $html=Blade::render('<x-trial-reminder-banner /><x-subscription-expiry-popup />');self::assertStringContainsString('No additional payment',$html);self::assertStringNotContainsString('Subscribe Now',$html);
  $this->artisan('trial:reminders')->assertSuccessful();Mail::assertNothingSent();Notification::assertNothingSent();Http::assertNothingSent();
 }
 public function test_paid_grace_repeats_in_app_with_frozen_deadline_without_new_email_cadence():void {
  [$c,$p,$trial]=$this->fixture();$paid=$this->pay($c,$p);$d=Policy::display($paid);$this->travelTo(Carbon::parse($d['paid_ends_at'])->addSecond());
  $a=Reminder::forCompany($c);self::assertSame('grace',$a['kind']);self::assertSame($d['grace_ends_at'],$a['until']);self::assertStringContainsString('receipt reprint',$a['text']);
  $this->travel(1)->days();$b=Reminder::forCompany($c);self::assertNotSame($a['key'],$b['key']);self::assertSame($a['until'],$b['until']);
  $u=User::factory()->create(['company_id'=>$c->id]);$this->actingAs($u);self::assertStringContainsString('data-prospective-reminder="grace"',Blade::render('<x-prospective-policy-reminder />'));
  $this->artisan('trial:reminders')->assertSuccessful();Mail::assertNothingSent();
  $this->travelTo(Carbon::parse($d['grace_ends_at']));self::assertNull(Reminder::forCompany($c));self::assertFalse(SubscriptionAccessService::hasAccess($c)['allowed']);
 }
 public function test_reminder_does_not_infer_dates_for_legacy_or_malformed_policy():void {
  [$c,$p,$s]=$this->fixture();\Illuminate\Support\Facades\DB::table('subscriptions')->where('id',$s->id)->update(['lifecycle_snapshot'=>null]);self::assertNull(Reminder::forCompany($c));
  \Illuminate\Support\Facades\DB::table('subscriptions')->where('id',$s->id)->update(['lifecycle_snapshot'=>'null']);self::assertNull(Reminder::forCompany($c));$this->artisan('trial:reminders')->assertSuccessful();Mail::assertNothingSent();
 }
 public function test_explicit_panel_guard_never_uses_other_signed_in_company():void {
  [$c,$p,$s]=$this->fixture();$this->travelTo(Carbon::parse(Policy::display($s)['trial_ends_at'])->subDay());
  $this->actingAs(User::factory()->create(['company_id'=>$c->id]),'web');
  $other=Company::create(['name'=>'Other panel','ntn'=>'OTHER-'.uniqid(),'product_type'=>'fbrpos']);
  $this->actingAs(User::factory()->create(['company_id'=>$other->id]),'fbrpos');
  self::assertStringNotContainsString('data-prospective-reminder',Blade::render('<x-prospective-policy-reminder guard="fbrpos" />'));
  self::assertStringContainsString('data-prospective-reminder="trial"',Blade::render('<x-prospective-policy-reminder guard="web" />'));
 }

}
