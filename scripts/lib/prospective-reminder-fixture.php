<?php
// Included only by the already guarded fresh socket-only browser fixture seeder.
$reminderClock=\Carbon\Carbon::now();
try {
 foreach ([1366,390] as $width) foreach (['trial','confirmed','grace'] as $phase) {
  $start=$phase==='grace'?$reminderClock->copy()->subDay()->subMonthsNoOverflow(12)->subHours(72):$reminderClock->copy()->subDay();
  \Carbon\Carbon::setTestNow($start);
  $c=$company('Synthetic reminder '.$phase.' '.$width,'reminder-'.$phase.'-'.$width.'@rc-browser.invalid','RCR'.$phase.$width,'fbrpos',[
   'business_category'=>'education','pos_type'=>'education','fbr_pos_enabled'=>true,'fbr_reporting_enabled'=>false,'fbr_pos_environment'=>'sandbox','pos_setup_completed'=>true,'is_internal_account'=>false,'onboarding_completed'=>true,'feature_flags'=>\App\Services\PosFeatureService::defaultsForCategory('education')]);
  $u=$user($c,'Synthetic reminder owner','reminder-owner-'.$phase.'-'.$width.'@rc-browser.invalid','company_admin','pos_admin');
  $p=\App\Models\PricingPlan::create(['name'=>'Business','product_type'=>'fbrpos','price'=>1000,'invoice_limit'=>25,'is_trial'=>false,'is_public'=>true]);
  $s=\App\Services\TrialSubscriptionService::ensureSelectedTrial($c->id,'fbrpos',$p->id);
  if($phase!=='trial') {
   $q=\App\Services\SubscriptionAssignmentService::computePrice($p,'annual',$c);
   $s=\App\Services\SubscriptionAssignmentService::assign($c->id,$p->id,'annual',paidRenewal:true,verifiedReceived:$q['final_price']);
  }
  \Carbon\Carbon::setTestNow($reminderClock);
  $view=\App\Services\ProspectiveReminderView::forCompany($c);
  if(($view['kind']??null)!==$phase)throw new \RuntimeException('Synthetic reminder phase is not the intended frozen phase.');
  $policyCases[$width]['reminders'][$phase]=['login'=>$u->email,'until'=>$view['until'],'texts'=>[]];
  foreach(['en','ur','rur'] as $locale) {
   app()->setLocale($locale);
   $policyCases[$width]['reminders'][$phase]['texts'][$locale]=\App\Services\ProspectiveReminderView::forCompany($c)['text'];
  }
 }
} finally { \Carbon\Carbon::setTestNow();app()->setLocale('en'); }
