<?php

namespace Tests\Unit;

use App\Models\FbrPosDeal;
use App\Models\PosDeal;
use App\Services\PosDealQuotaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PosDealQuotaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        foreach (['pos_deals', 'fbr_pos_deals'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();
                $blueprint->unsignedBigInteger('company_id');
                $blueprint->string('name');
                $blueprint->decimal('price', 10, 2)->default(0);
                $blueprint->boolean('is_active')->default(true);
                $blueprint->json('active_days')->nullable();
                $blueprint->date('starts_on')->nullable();
                $blueprint->date('ends_on')->nullable();
                $blueprint->string('deal_type', 20)->default('regular');
                $blueprint->time('special_start_time')->nullable();
                $blueprint->time('special_end_time')->nullable();
                $blueprint->unsignedInteger('total_deal_units_limit')->nullable();
                $blueprint->unsignedInteger('daily_deal_units_limit')->nullable();
                $blueprint->timestamps();
            });
        }

        foreach (['pos_deal_usages', 'fbr_pos_deal_usages'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();
                $blueprint->unsignedBigInteger('company_id');
                $blueprint->unsignedBigInteger('deal_id');
                $blueprint->date('usage_date');
                $blueprint->unsignedInteger('units_used')->default(0);
                $blueprint->timestamps();
                $blueprint->unique(['company_id', 'deal_id', 'usage_date']);
            });
        }
    }

    public function test_special_window_is_inclusive_and_same_day(): void
    {
        $deal = PosDeal::create([
            'company_id' => 10,
            'name' => 'Lunch bundle',
            'price' => 500,
            'is_active' => true,
            'deal_type' => 'special',
            'starts_on' => '2026-08-27',
            'ends_on' => '2026-08-27',
            'special_start_time' => '10:00',
            'special_end_time' => '12:00',
        ]);

        $tz = config('app.timezone');
        self::assertTrue($deal->isAvailableAt(Carbon::parse('2026-08-27 10:00:00', $tz)));
        self::assertTrue($deal->isAvailableAt(Carbon::parse('2026-08-27 12:00:00', $tz)));
        self::assertFalse($deal->isAvailableAt(Carbon::parse('2026-08-27 09:59:59', $tz)));
        self::assertFalse($deal->isAvailableAt(Carbon::parse('2026-08-27 12:00:01', $tz)));
    }

    public function test_regular_deals_keep_weekday_recurrence(): void
    {
        $deal = PosDeal::create([
            'company_id' => 10,
            'name' => 'Weekly bundle',
            'price' => 500,
            'is_active' => true,
            'active_days' => [4], // Thursday
        ]);

        $tz = config('app.timezone');
        self::assertTrue($deal->isAvailableAt(Carbon::parse('2026-08-27 10:00:00', $tz)));
        self::assertFalse($deal->isAvailableAt(Carbon::parse('2026-08-28 10:00:00', $tz)));
    }

    public function test_limits_count_bundles_and_are_company_scoped_for_both_pos_models(): void
    {
        $cases = [
            [PosDeal::class, 'pos_deal_usages'],
            [FbrPosDeal::class, 'fbr_pos_deal_usages'],
        ];

        foreach ($cases as [$model, $usageTable]) {
            $deal = $model::create([
                'company_id' => 10,
                'name' => 'Limited bundle',
                'price' => 500,
                'is_active' => true,
                'deal_type' => 'special',
                'starts_on' => '2026-08-27',
                'ends_on' => '2026-08-27',
                'special_start_time' => '09:00',
                'special_end_time' => '18:00',
                'total_deal_units_limit' => 3,
                'daily_deal_units_limit' => 2,
            ]);
            $otherCompanyDeal = $model::create([
                'company_id' => 11,
                'name' => 'Other company bundle',
                'price' => 500,
                'is_active' => true,
                'deal_type' => 'special',
                'starts_on' => '2026-08-27',
                'ends_on' => '2026-08-27',
                'special_start_time' => '09:00',
                'special_end_time' => '18:00',
                'total_deal_units_limit' => 3,
                'daily_deal_units_limit' => 2,
            ]);

            $at = Carbon::parse('2026-08-27 10:00:00', config('app.timezone'));
            PosDealQuotaService::reserve($deal, 2, $at);
            self::assertSame(1, PosDealQuotaService::remainingTotal($deal));
            self::assertSame(0, PosDealQuotaService::remainingDaily($deal, '2026-08-27'));
            self::assertFalse($deal->isAvailableAt($at));

            // Usage is keyed by company + deal, not just the deal's numeric id.
            self::assertSame(2, (int) DB::table($usageTable)
                ->where('company_id', 10)->where('deal_id', $deal->id)->sum('units_used'));
            self::assertTrue($otherCompanyDeal->isAvailableAt($at));

            try {
                PosDealQuotaService::reserve($deal, 1, $at);
                self::fail('A limit-exceeding reservation should be rejected.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('no remaining quantity', $exception->getMessage());
            }
        }
    }

    public function test_failed_transaction_rolls_back_special_deal_usage(): void
    {
        $deal = PosDeal::create([
            'company_id' => 10,
            'name' => 'Rollback bundle',
            'price' => 500,
            'is_active' => true,
            'deal_type' => 'special',
            'starts_on' => '2026-08-27',
            'ends_on' => '2026-08-27',
            'special_start_time' => '09:00',
            'special_end_time' => '18:00',
            'total_deal_units_limit' => 2,
        ]);
        $at = Carbon::parse('2026-08-27 10:00:00', config('app.timezone'));

        try {
            DB::transaction(function () use ($deal, $at) {
                PosDealQuotaService::reserve($deal, 2, $at);
                throw new RuntimeException('simulated stock failure');
            });
        } catch (RuntimeException $e) {
            self::assertSame('simulated stock failure', $e->getMessage());
        }

        self::assertSame(2, PosDealQuotaService::remainingTotal($deal));
        self::assertDatabaseCount('pos_deal_usages', 0);
    }
}