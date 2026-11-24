<?php

namespace App\Console\Commands;

use App\Models\PricingPlan;
use App\Models\SystemSetting;
use App\Services\AiPageCreditService;
use App\Services\PosAddonPricingService;
use App\Support\NestErps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ApplyApprovedBillingRateCard extends Command
{
    protected $signature = 'billing:approved-rate-card {--apply : Save the approved catalogue prices}';

    protected $description = 'Preview or apply the November 2026 owner-approved TaxNest prices';

    /** Product, package, annual, six months, quarterly, monthly. */
    private const PLANS = [
        ['di', 'Asaan', 17990, 10394, 5299, 1799],
        ['di', 'Kaarobar', 24990, 14489, 7399, 2499],
        ['di', 'Unlimited', 39990, 23099, 11799, 3999],
        ['pos', 'Starter', 19999, 10499, 5499, 1999],
        ['pos', 'Business', 29999, 15749, 8299, 2999],
        ['pos', 'Unlimited', 37999, 19949, 10499, 3799],
        ['fbrpos', 'Starter', 17999, 9449, 4899, 1799],
        ['fbrpos', 'Business', 27999, 14699, 7699, 2799],
        ['erps', 'Clinic', 24999, 13124, 6899, 2499],
        ['erps', 'Hospital', 59999, 31499, 16499, 5999],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        return DB::transaction(function () use ($apply): int {
            $updates = [];
            $rows = [];

            foreach (self::PLANS as [$product, $name, $annual, $sixMonths, $quarterly, $monthly]) {
                $query = PricingPlan::query()->where('name', $name)->where('is_trial', false);
                if ($product === 'erps') {
                    $query->whereIn('product_type', NestErps::PRODUCT_TYPES)
                        ->where(fn ($vertical) => $vertical->whereNull(NestErps::VERTICAL_COLUMN)
                            ->orWhere(NestErps::VERTICAL_COLUMN, NestErps::HEALTH));
                } else {
                    $query->where('product_type', $product);
                }

                $matches = $query->when($apply, fn ($plans) => $plans->lockForUpdate())->get();
                if ($matches->count() !== 1) {
                    $this->error("Expected exactly one paid {$product} {$name} plan. Found {$matches->count()}.");

                    return self::FAILURE;
                }

                $plan = $matches->sole();
                $rates = [
                    'price' => $product === 'di' ? $monthly : $annual,
                    'price_yearly' => $annual,
                    'price_semi_annual' => $sixMonths,
                    'price_quarterly' => $quarterly,
                    'price_monthly' => $monthly,
                ];
                $updates[] = [$plan, $rates];
                foreach ($rates as $field => $rate) {
                    $previous = $plan->getAttribute($field);
                    if ($previous === null || (float) $previous !== (float) $rate) {
                        $rows[] = ["{$product} {$name}", $field, $previous ?? 'Not set', $rate];
                    }
                }
            }

            $addonRates = ['caller_id' => ['annual' => 4999], 'rider_tracking' => ['annual' => 7999]];
            foreach ($addonRates as $code => $rates) {
                $rows[] = [$code, 'annual', PosAddonPricingService::price($code), $rates['annual']];
            }
            $rows[] = ['AI Reader', '500 pages', AiPageCreditService::packPrice(500), 2099];
            $this->table(['Plan or add-on', 'Price field', 'Current PKR', 'Approved PKR'], $rows);

            if ($apply) {
                foreach ($updates as [$plan, $rates]) {
                    $plan->fill($rates);
                    if ($plan->isDirty()) {
                        $plan->save();
                    }
                }
                PosAddonPricingService::save($addonRates);
                SystemSetting::set('di_ai_500_page_pack_price', '2099', 'DI AI Reader price per 500 purchased pages');
            }

            $this->info($apply ? 'Approved catalogue saved.' : 'Preview only. Use --apply to save prices.');

            return self::SUCCESS;
        });
    }
}
