<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FbrProspectiveQuotaRetention as Retention;
use App\Services\PlanLimitService;
use App\Services\ProspectiveSubscriptionPolicy as Policy;
use App\Services\SubscriptionAssignmentService as Assignment;
use App\Services\TrialSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FbrProspectiveQuotaRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(bool $prospective = true): array
    {
        $this->travelTo(now()->setDate(2028, 4, 10)->setTime(10, 0));
        $c = Company::create(['name' => 'Synthetic FBR quota', 'ntn' => 'FBR-Q-'.uniqid(), 'product_type' => 'fbrpos', 'status' => 'approved', 'company_status' => 'active', 'fbr_pos_enabled' => true, 'fbr_reporting_enabled' => false]);
        $p = PricingPlan::create(['name' => 'Business', 'product_type' => 'fbrpos', 'price' => 1000, 'invoice_limit' => 2, 'user_limit' => 3, 'branch_limit' => 1]);
        if ($prospective) {
            $trial = TrialSubscriptionService::ensureSelectedTrial($c->id, 'fbrpos', $p->id);
            $q = Assignment::computePrice($p, 'annual', $c);
            $sub = Assignment::assign($c->id, $p->id, 'annual', paidRenewal: true, verifiedReceived: $q['final_price']);
            $this->travelTo(\Carbon\Carbon::parse(Policy::display($sub)['paid_starts_at'])->addSecond());
        } else {
            $sub = Subscription::create(['company_id' => $c->id, 'pricing_plan_id' => $p->id, 'active' => true, 'start_date' => now(), 'end_date' => now()->addYear(), 'billing_cycle' => 'annual', 'final_price' => 1000]);
        }
        $u = User::factory()->create(['company_id' => $c->id, 'role' => 'company_admin', 'is_active' => true]);
        $this->actingAs($u, 'fbrpos');
        app()->instance('currentCompanyId', $c->id);

        return [$c, $p, $sub, $u];
    }

    private function bill(Company $c, array $extra = []): FbrPosTransaction
    {
        $bill = FbrPosTransaction::create($extra + ['company_id' => $c->id, 'invoice_number' => 'SYN-Q-'.uniqid(), 'status' => 'completed', 'transaction_type' => 'sale', 'invoice_mode' => 'local', 'fbr_status' => 'local', 'total_amount' => 100]);
        if (isset($extra['created_at'])) {
            $bill->forceFill(['created_at' => $extra['created_at']])->save();
        }

        return $bill->refresh();
    }

    public function test_actual_manual_delete_keeps_paid_slot_and_retry_cannot_duplicate(): void
    {
        [$c] = $this->buyer();
        $b = $this->bill($c);
        self::assertSame(1, PlanLimitService::canCreateFbrPosBill($c->id)['remaining']);
        $this->postJson('/fbr-pos/api/provisional-bills/'.$b->id.'/delete')->assertOk()->assertJsonPath('success', true);
        self::assertSame(1, PlanLimitService::canCreateFbrPosBill($c->id)['remaining']);
        $this->postJson('/fbr-pos/api/provisional-bills/'.$b->id.'/delete')->assertNotFound();
        self::assertSame(1, DB::table('prospective_fbr_quota_retention')->count());
        Http::assertNothingSent();
    }

    public function test_actual_dayclose_wash_keeps_paid_slot(): void
    {
        [$c,$p,$s,$u] = $this->buyer();
        $b = $this->bill($c, ['business_date' => now()->toDateString()]);
        $controller = app(\App\Http\Controllers\FbrPosController::class);
        $report = $controller->performDayClose($c->id, now()->toDateString(), $u->id, allowEmpty: true, actionOverride: ['provisional' => 'delete']);
        self::assertNotNull($report);
        self::assertNull($b->fresh());
        self::assertSame(1, PlanLimitService::canCreateFbrPosBill($c->id)['remaining']);
        self::assertSame(1, Retention::deletedCount($c->id));
        Http::assertNothingSent();
    }

    public function test_trial_and_old_month_excluded_early_renewal_does_not_reset_retained_usage(): void
    {
        [$c,$p,$s] = $this->buyer();
        $start = \Carbon\Carbon::parse($s->lifecycle_snapshot['first_paid_starts_at']);
        foreach ([$start->copy()->subSecond(), $start->copy()->subMonth(), now()] as $at) {
            $b = $this->bill($c, ['created_at' => $at]);
            DB::transaction(function () use ($b) {
                Retention::capture(collect([$b]), 'single-delete');
                $b->delete();
            });
        }
        self::assertSame(1, Retention::deletedCount($c->id));
        $q = Assignment::computePrice($p, 'annual', $c);
        Assignment::assign($c->id, $p->id, 'annual', paidRenewal: true, verifiedReceived: $q['final_price']);
        self::assertSame(1, PlanLimitService::canCreateFbrPosBill($c->id)['remaining']);
    }

    public function test_live_copy_antijoin_rollback_and_conflict_are_safe(): void
    {
        [$c] = $this->buyer();
        $b = $this->bill($c);
        try {
            DB::transaction(function () use ($b, $c) {
                Retention::capture(collect([$b]), 'single-delete');
                Retention::capture(collect([$b]), 'single-delete');
                self::assertSame(0, Retention::deletedCount($c->id));
                throw new \RuntimeException('Synthetic rollback');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, DB::table('prospective_fbr_quota_retention')->count());
        self::assertNotNull($b->fresh());
        DB::transaction(fn () => Retention::capture(collect([$b]), 'single-delete'));
        $b->created_at = now()->subDay();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        DB::transaction(fn () => Retention::capture(collect([$b]), 'single-delete'));
    }

    public function test_legacy_is_unchanged_and_no_backfill_is_performed(): void
    {
        [$c] = $this->buyer(false);
        $b = $this->bill($c);
        $this->postJson('/fbr-pos/api/provisional-bills/'.$b->id.'/delete')->assertOk()->assertJsonPath('success', true);
        self::assertSame(0, DB::table('prospective_fbr_quota_retention')->count());
        self::assertSame(2, PlanLimitService::canCreateFbrPosBill($c->id)['remaining']);
    }

    public function test_missing_schema_stops_deletion(): void
    {
        [$c] = $this->buyer();
        $b = $this->bill($c);
        Schema::drop('prospective_fbr_quota_retention');
        try {
            DB::transaction(function () use ($b) {
                Retention::capture(collect([$b]), 'single-delete');
                $b->delete();
            });
            $this->fail('Missing retention table allowed delete');
        } catch (\Illuminate\Validation\ValidationException) {
        }
        self::assertNotNull($b->fresh());
        Http::assertNothingSent();
    }

    public function test_official_rows_are_never_captured(): void
    {
        [$c] = $this->buyer();
        $b = $this->bill($c, ['invoice_mode' => 'fiscal', 'fbr_status' => 'submitted', 'fbr_invoice_number' => 'SYNTHETIC-OFFICIAL']);
        try {
            DB::transaction(fn () => Retention::capture(collect([$b]), 'single-delete'));
            $this->fail('Official row accepted');
        } catch (\Illuminate\Validation\ValidationException) {
        }
        self::assertSame(0, DB::table('prospective_fbr_quota_retention')->count());
        self::assertNotNull($b->fresh());
        Http::assertNothingSent();
    }

    public function test_forward_retention_and_authorized_purge_are_tenant_scoped(): void
    {
        [$c] = $this->buyer();
        $b = $this->bill($c);
        DB::transaction(function () use ($b) {
            Retention::capture(collect([$b]), 'single-delete');
            $b->delete();
        });
        $m = require database_path('migrations/2026_12_27_040000_add_fbr_prospective_quota_retention.php');
        $m->down();
        $m->up();
        DB::transaction(fn () => (new \App\Services\LocalCollectionLifecycle)->purge($c->id + 100));
        self::assertSame(1, Retention::deletedCount($c->id));
        DB::transaction(fn () => (new \App\Services\LocalCollectionLifecycle)->purge($c->id));
        self::assertSame(0,DB::table('prospective_fbr_quota_retention')->count());
    }
}
