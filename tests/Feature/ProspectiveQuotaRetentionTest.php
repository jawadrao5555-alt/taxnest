<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PricingPlan;
use App\Services\PlanLimitService;
use App\Services\ProspectiveQuotaRetention as Retention;
use App\Services\ProspectiveSubscriptionPolicy as Policy;
use App\Services\SubscriptionAssignmentService as Assignment;
use App\Services\TrialSubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProspectiveQuotaRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(string $product = 'pos'): array
    {
        $this->travelTo(Carbon::parse('2028-04-10 10:00:00', config('app.timezone')));
        $c = Company::create(['name' => 'Synthetic quota', 'ntn' => 'QUOTA-'.uniqid(), 'product_type' => $product, 'status' => 'approved', 'company_status' => 'active']);
        $p = PricingPlan::create(['name' => 'Business', 'product_type' => $product, 'price' => 1000, 'invoice_limit' => 2, 'user_limit' => 3, 'branch_limit' => 1]);
        $s = TrialSubscriptionService::ensureSelectedTrial($c->id, $product, $p->id);

        return [$c, $p, $s];
    }

    private function bill(Company $c, array $extra = []): PosTransaction
    {
        $bill = new PosTransaction();
        $bill->forceFill($extra + ['company_id' => $c->id, 'invoice_number' => 'Q-'.uniqid(), 'status' => 'completed', 'invoice_mode' => 'pra', 'transaction_type' => 'sale', 'created_at' => now(), 'updated_at' => now()])->save();
        return $bill->fresh();
    }

    private function pay($c, $p)
    {
        $quote = Assignment::computePrice($p, 'annual', $c);

        return Assignment::assign($c->id, $p->id, 'annual', paidRenewal: true, verifiedReceived: $quote['final_price']);
    }

    public function test_trial_bills_do_not_consume_paid_allowance_and_renewal_does_not_reset_usage(): void
    {
        [$c,$p,$s] = $this->buyer();
        for ($i = 0; $i < 5; $i++) {
            $this->bill($c);
        }
        $paid = $this->pay($c, $p);
        $this->travelTo(Carbon::parse(Policy::display($paid)['paid_starts_at'])->addSecond());
        self::assertSame(2, PlanLimitService::canCreatePosBill($c->id)['remaining']);
        $this->bill($c);
        self::assertSame(1, PlanLimitService::canCreatePosBill($c->id)['remaining']);
        $renew = $this->pay($c, $p);
        self::assertSame($paid->lifecycle_snapshot['first_paid_starts_at'], $renew->lifecycle_snapshot['first_paid_starts_at']);
        self::assertSame(1, PlanLimitService::canCreatePosBill($c->id)['remaining']);
    }

    public function test_di_submission_stamp_not_draft_creation_defines_paid_consumption(): void
    {
        [$c,$p,$s] = $this->buyer('di');
        $trial = now()->copy();
        $paid = $this->pay($c, $p);
        $this->travelTo(Carbon::parse(Policy::display($paid)['paid_starts_at'])->addSecond());
        foreach ([$trial, now()] as $submitted) {
            DB::table('invoices')->insert(['company_id' => $c->id, 'buyer_name' => 'Synthetic', 'created_at' => $trial, 'updated_at' => now(), 'submitted_at' => $submitted, 'fbr_status' => 'production']);
        }
        self::assertSame(1, PlanLimitService::monthlyInvoiceCount($c->id));
        self::assertSame(1, PlanLimitService::canCreateInvoice($c->id)['remaining']);
    }

    public function test_deleted_paid_bill_counts_once_retry_rollback_and_nonbill_exclusions(): void
    {
        [$c,$p,$s] = $this->buyer();
        $paid = $this->pay($c, $p);
        $this->travelTo(Carbon::parse(Policy::display($paid)['paid_starts_at'])->addSecond());
        $bill = $this->bill($c);
        DB::transaction(function () use ($bill) {
            Retention::capture(collect([$bill]), 'single-delete', $bill->id);
            Retention::capture(collect([$bill]), 'single-delete', $bill->id);
            self::assertSame(0, Retention::deletedCount($bill->company_id));
            $bill->delete();
        });
        self::assertSame(1, Retention::deletedCount($c->id));
        self::assertSame(1, PlanLimitService::canCreatePosBill($c->id)['remaining']);
        foreach ([['transaction_type' => 'return'], ['invoice_mode' => 'local'], ['status' => 'draft']] as $extra) {
            $b = $this->bill($c, $extra);
            DB::transaction(function () use ($b) {
                Retention::capture(collect([$b]), 'single-delete', $b->id);
                $b->delete();
            });
        }
        self::assertSame(1, Retention::deletedCount($c->id));
        $b = $this->bill($c);
        try {
            DB::transaction(function () use ($b) {
                Retention::capture(collect([$b]), 'single-delete', $b->id);
                throw new \RuntimeException('synthetic rollback');
            });
        } catch (\RuntimeException) {
        }
        self::assertDatabaseMissing('prospective_pos_quota_retention', ['transaction_id' => $b->id]);
    }

    public function test_actual_owner_delete_keeps_paid_slot_and_repeated_delete_cannot_double_count(): void
    {
        [$c,$p,$s]=$this->buyer(); $paid=$this->pay($c,$p);
        $this->travelTo(Carbon::parse(Policy::display($paid)['paid_starts_at'])->addSecond());
        $bill=$this->bill($c);
        $owner=\App\Models\User::factory()->create(['company_id'=>$c->id,'role'=>'company_admin','is_active'=>true]);
        $this->actingAs($owner,'pos')->delete('/pos/transaction/'.$bill->id)->assertRedirect('/pos/transactions')->assertSessionHas('success');
        self::assertSame(1,Retention::deletedCount($c->id));
        self::assertSame(1,PlanLimitService::canCreatePosBill($c->id)['remaining']);
        $this->deleteJson('/pos/transaction/'.$bill->id)->assertNotFound()->assertJson(['error'=>'Resource not found.']);
        self::assertSame(1,Retention::deletedCount($c->id));
    }

    public function test_exact_archive_source_spanning_trial_and_paid_excludes_only_trial(): void
    {
        [$c,$p,$s]=$this->buyer(); $trial=$this->bill($c);$paid=$this->pay($c,$p);
        $this->travelTo(Carbon::parse(Policy::display($paid)['paid_starts_at'])->addSecond());
        $paidBill=$this->bill($c);
        DB::transaction(function()use($c,$trial,$paidBill){
            $reset=DB::table('pos_local_series_resets')->insertGetId(['company_id'=>$c->id,'reset_date'=>now()->toDateString(),'deleted_final_count'=>2,'deleted_provisional_count'=>0,'total_deleted'=>2,'created_at'=>now(),'updated_at'=>now()]);
            Retention::capture(collect([$trial,$paidBill]),'archive-clear',$reset);
            $trial->delete();$paidBill->delete();
        });
        self::assertSame(1,Retention::deletedCount($c->id));
        self::assertSame(1,PlanLimitService::canCreatePosBill($c->id)['remaining']);
    }

    public function test_day_close_scope_uses_frozen_business_date_not_created_at_and_excludes_backlog(): void
    {
        [$c,$p,$s]=$this->buyer();$paid=$this->pay($c,$p);
        $this->travelTo(Carbon::parse('2028-05-01 00:30:00',config('app.timezone')));
        $backlog=$this->bill($c,['created_at'=>Carbon::parse('2028-03-31 12:00:00',config('app.timezone')),'business_date'=>'2028-03-31']);
        $april=$this->bill($c,['created_at'=>Carbon::parse('2028-04-20 12:00:00',config('app.timezone')),'business_date'=>'2028-04-20']);
        // Created in May, but belongs to April's report month under the existing cutoff.
        $midnight=$this->bill($c,['business_date'=>'2028-04-30']);
        $may=$this->bill($c,['business_date'=>'2028-05-01']);
        DB::transaction(function()use($c,$backlog,$april,$midnight,$may){
            $id=DB::table('pos_day_close_reports')->insertGetId(['company_id'=>$c->id,'report_date'=>'2028-04-30','report_number'=>'SYNTHETIC-APRIL','deleted_final_count'=>2,'created_at'=>now(),'updated_at'=>now()]);
            $rows=collect([$backlog,$april,$midnight,$may]);
            Retention::capture($rows,'day-close',$id);
            foreach($rows as $row)$row->delete();
        });
        // Quota retains its independent creation-time calendar definition.
        self::assertSame(2,Retention::deletedCount($c->id));
        self::assertDatabaseHas('prospective_pos_quota_retention',['transaction_id'=>$midnight->id,'business_date'=>'2028-04-30','sold_at'=>'2028-05-01 00:30:00']);
        $this->travelTo(Carbon::parse('2028-04-30 23:00:00',config('app.timezone')));
        self::assertSame(1,Retention::deletedCount($c->id));
    }

    public function test_unknown_aggregate_requires_review_instead_of_zero_or_inferred_allowance(): void
    {
        [$c,$p,$s] = $this->buyer();
        DB::table('pos_local_series_resets')->insert(['company_id' => $c->id, 'reset_date' => now()->toDateString(), 'deleted_final_count' => 1, 'deleted_provisional_count' => 0, 'total_deleted' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        Retention::deletedCount($c->id);
    }

    public function test_forward_migration_and_authorized_purge_preserve_other_company(): void
    {
        [$c,$p,$s] = $this->buyer();
        $b = $this->bill($c);
        DB::transaction(fn () => Retention::capture(collect([$b]), 'single-delete', $b->id));
        $before = DB::table('prospective_pos_quota_retention')->first();
        (require database_path('migrations/2026_12_27_020000_add_prospective_quota_retention.php'))->down();
        self::assertEquals($before, DB::table('prospective_pos_quota_retention')->first());
        DB::transaction(fn () => (new \App\Services\LocalCollectionLifecycle)->purge($c->id + 100));
        self::assertSame(1,DB::table('prospective_pos_quota_retention')->count());
    }
}
