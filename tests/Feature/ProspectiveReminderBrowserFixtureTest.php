<?php
namespace Tests\Feature;
use App\Models\{Company,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Mail,Notification,Http};
use Tests\TestCase;
class ProspectiveReminderBrowserFixtureTest extends TestCase {
 use RefreshDatabase;
 public function test_all_disposable_browser_phase_fixtures_use_frozen_policy_without_delivery():void {
  Mail::fake();Notification::fake();Http::preventStrayRequests();$policyCases=[];
  $company=fn($name,$email,$ntn,$product,$extra)=>Company::create(array_merge(['name'=>$name,'email'=>$email,'ntn'=>$ntn,'product_type'=>$product,'status'=>'approved','company_status'=>'active'],$extra));
  $user=fn($company,$name,$email,$role,$posRole)=>User::factory()->create(['company_id'=>$company->id,'name'=>$name,'email'=>$email,'role'=>$role,'pos_role'=>$posRole]);
  require base_path('scripts/lib/prospective-reminder-fixture.php');
  foreach([1366,390] as $width) foreach(['trial','confirmed','grace'] as $phase) {
   self::assertNotEmpty($policyCases[$width]['reminders'][$phase]['until']);
   foreach(['en','ur','rur'] as $locale)self::assertNotEmpty($policyCases[$width]['reminders'][$phase]['texts'][$locale]);
  }
  self::assertSame(6,Company::where('ntn','like','RCR%')->count());Mail::assertNothingSent();Notification::assertNothingSent();Http::assertNothingSent();
 }
}
