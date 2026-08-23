<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Digital Invoice package restructure (Sep 2026, owner-approved).
 *
 * Six DI plans collapse into THREE public packages:
 *   Asaan      700 invoices/mo   200 AI pages/mo   2 users   1 branch    1,799/mo
 *   Kaarobar 2,500 invoices/mo   400 AI pages/mo   4 users   3 branches  2,499/mo
 *   Unlimited  unlimited         700 AI pages/mo   unlimited            3,999/mo
 *
 * Everything else changes shape with them:
 *  - Quota becomes a PER-MONTH allowance (the counter rewrite lives in
 *    PlanLimitService) instead of the old lifetime invoice count.
 *  - AI Reader pages get their own monthly allowance + a purchased balance
 *    that never expires (ai_page_ledgers + companies.ai_page_balance).
 *  - Cycle prices are stored EXPLICITLY per plan. The global cycle-discount
 *    ladder in Subscription::getDiscountForCycle stays untouched so FBR POS
 *    and PRA POS pricing does not move.
 *  - Legacy plans are hidden (is_public = 0), never deleted — live
 *    subscriptions point at those rows and history must stay readable.
 *  - Every DI company on a legacy plan EXCEPT Premium is shifted to
 *    Kaarobar, keeping its grant/override/expiry exactly as it was.
 */
return new class extends Migration
{
    /** name => [invoice_limit, ai_page_limit, users, branches, monthly, quarterly, semi, yearly, fair_use] */
    private const PACKAGES = [
        'Asaan'     => [700,  200, 2,  1,  1799.00,  5099.00,  9899.00, 17990.00, null],
        'Kaarobar'  => [2500, 400, 4,  3,  2499.00,  6999.00, 13799.00, 24990.00, null],
        'Unlimited' => [-1,   700, -1, -1, 3999.00, 11299.00, 21999.00, 39990.00, 25000],
    ];

    private const FEATURES = [
        'Asaan' => [
            '700 FBR invoices per month',
            '200 AI Reader pages per month',
            '2 users · 1 branch',
            'Excel / CSV bulk import',
            'FBR submission, PDF & WhatsApp sharing',
            'Customers, products & MIS reports',
            'FBR Audit Pack (6-year archive)',
        ],
        'Kaarobar' => [
            '2,500 FBR invoices per month',
            '400 AI Reader pages per month',
            '4 users · 3 branches',
            'Everything in Asaan',
            'White-label branding on PDFs & share pages',
            'Public REST API for ERP integration',
            'Priority support',
        ],
        'Unlimited' => [
            'Unlimited FBR invoices (fair use 25,000/month)',
            '700 AI Reader pages per month',
            'Unlimited users & branches',
            'Everything in Kaarobar',
            'White-label branding & public REST API',
            'Priority support & dedicated account manager',
            'FBR Audit Pack (6-year archive)',
        ],
    ];

    /** Legacy DI plans that stop being sold. Premium keeps its own row+customer. */
    private const RETIRED = ['Retail', 'Business', 'Industrial', 'Enterprise'];

    public function up(): void
    {
        Schema::table('pricing_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('pricing_plans', 'ai_page_limit')) {
                $table->integer('ai_page_limit')->default(0)->after('invoice_limit');
            }
            if (!Schema::hasColumn('pricing_plans', 'price_semi_annual')) {
                $table->decimal('price_semi_annual', 10, 2)->nullable()->after('price_quarterly');
            }
            if (!Schema::hasColumn('pricing_plans', 'price_yearly')) {
                $table->decimal('price_yearly', 10, 2)->nullable()->after('price_semi_annual');
            }
            if (!Schema::hasColumn('pricing_plans', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('is_trial');
            }
            if (!Schema::hasColumn('pricing_plans', 'fair_use_limit')) {
                $table->integer('fair_use_limit')->nullable()->after('invoice_limit');
            }
        });

        if (!Schema::hasColumn('companies', 'ai_page_balance')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->integer('ai_page_balance')->default(0);
            });
        }

        if (!Schema::hasTable('ai_page_ledgers')) {
            Schema::create('ai_page_ledgers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id')->nullable();
                // consume | refund | topup | admin_grant
                $table->string('kind', 20);
                // Which pocket the pages came from / went back to.
                $table->integer('from_allowance')->default(0);
                $table->integer('from_balance')->default(0);
                // single_parse | bulk_batch | import_assist | topup | admin
                $table->string('source', 30)->nullable();
                $table->string('ref_type', 40)->nullable();
                $table->unsignedBigInteger('ref_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'created_at']);
                $table->index(['company_id', 'kind']);
                $table->index(['ref_type', 'ref_id']);
            });
        }

        $now = now();

        foreach (self::PACKAGES as $name => [$invoices, $aiPages, $users, $branches, $monthly, $quarterly, $semi, $yearly, $fairUse]) {
            $row = [
                'invoice_limit'     => $invoices,
                'ai_page_limit'     => $aiPages,
                'fair_use_limit'    => $fairUse,
                'user_limit'        => $users,
                'max_users'         => $users,
                'branch_limit'      => $branches,
                'price'             => $monthly,
                'price_monthly'     => $monthly,
                'price_quarterly'   => $quarterly,
                'price_semi_annual' => $semi,
                'price_yearly'      => $yearly,
                'is_trial'          => 0,
                'is_public'         => 1,
                'product_type'      => 'di',
                'features'          => json_encode(self::FEATURES[$name]),
                'updated_at'        => $now,
            ];

            $existing = DB::table('pricing_plans')
                ->where('name', $name)
                ->where('product_type', 'di')
                ->first();

            if ($existing) {
                DB::table('pricing_plans')->where('id', $existing->id)->update($row);
            } else {
                DB::table('pricing_plans')->insert($row + ['name' => $name, 'created_at' => $now]);
            }
        }

        // Legacy DI plans stop appearing on pricing surfaces (rows stay for history).
        DB::table('pricing_plans')
            ->where('product_type', 'di')
            ->whereNotIn('name', array_keys(self::PACKAGES))
            ->update(['is_public' => 0, 'updated_at' => $now]);

        // A trial has to be able to TRY the AI Reader — the feature gate opens
        // for active trials, so without a few pages it would open onto an
        // empty balance and look broken.
        DB::table('pricing_plans')
            ->where('product_type', 'di')
            ->where('is_trial', 1)
            ->update(['ai_page_limit' => 5, 'updated_at' => $now]);

        $kaarobarId = DB::table('pricing_plans')
            ->where('name', 'Kaarobar')->where('product_type', 'di')->value('id');

        if (!$kaarobarId) {
            return;
        }

        $retiredIds = DB::table('pricing_plans')
            ->where('product_type', 'di')
            ->whereIn('name', self::RETIRED)
            ->pluck('id')
            ->all();

        if (empty($retiredIds)) {
            return;
        }

        // Move every subscription (active or not) off the retired plans so a
        // renewal or a reactivated grant cannot resurrect a dead package.
        $movedCompanies = DB::table('subscriptions')
            ->whereIn('pricing_plan_id', $retiredIds)
            ->pluck('company_id')
            ->unique()
            ->all();

        DB::table('subscriptions')
            ->whereIn('pricing_plan_id', $retiredIds)
            ->update(['pricing_plan_id' => $kaarobarId, 'updated_at' => $now]);

        // A hand-set numeric invoice cap was a workaround for the old LIFETIME
        // counter. With a monthly quota it would silently override the package,
        // so it goes. A -1 (deliberate unlimited grant) is left alone.
        if (!empty($movedCompanies) && Schema::hasColumn('companies', 'invoice_limit_override')) {
            DB::table('companies')
                ->whereIn('id', $movedCompanies)
                ->where('invoice_limit_override', '>=', 0)
                ->update(['invoice_limit_override' => null, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // Package data is not restored: the retired rows still exist and can be
        // re-pointed by hand. Only the additive schema is reversed.
        Schema::dropIfExists('ai_page_ledgers');

        if (Schema::hasColumn('companies', 'ai_page_balance')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('ai_page_balance'));
        }

        Schema::table('pricing_plans', function (Blueprint $table) {
            foreach (['ai_page_limit', 'price_semi_annual', 'price_yearly', 'is_public', 'fair_use_limit'] as $col) {
                if (Schema::hasColumn('pricing_plans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
