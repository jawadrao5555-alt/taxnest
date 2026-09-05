<?php

use App\Services\HealthModuleService;
use App\Support\HealthPanel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1547 — the two healthcare packages the foundation has to support:
 * a small CLINIC package and a full HOSPITAL package, plus the trial row every
 * product line needs so a fresh signup is never left subscription-less.
 *
 * What a package really decides here is which MODULES it sells
 * (pricing_plans.health_modules) and how many departments it allows. The
 * owner's own module choice is masked against this at read time, so a package
 * that does not sell IPD can never have IPD switched on — however the
 * company row was edited.
 *
 * Idempotent ensure-rows: production runs `migrate --force` and never
 * `db:seed`, and an admin may well have adjusted a price by hand, so each row
 * is inserted ONLY when a healthcare package of that name does not exist yet.
 * A re-run clobbers nothing.
 *
 * Pricing follows the 23 Aug 2026 annual-only rule: `price` holds the ANNUAL
 * total (same convention as the two POS lines), and the monthly/quarterly
 * columns are left null because nothing may sell those cycles.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        foreach ($this->packages() as $package) {
            $this->ensure($package);
        }
    }

    /**
     * The healthcare shelf.
     *
     * @return array<int,array<string,mixed>>
     */
    private function packages(): array
    {
        return [
            [
                'name' => 'Trial',
                'is_trial' => true,
                'is_public' => false,
                'price' => 0,
                // A trial runs the small-clinic shape: enough to see the panel
                // work end to end without handing out the hospital modules.
                'health_modules' => HealthModuleService::ORG_TYPE_DEFAULTS['clinic'],
                'health_department_limit' => 3,
                'user_limit' => 3,
                'branch_limit' => 1,
                'features' => [
                    'Outpatients & accounts modules',
                    'Up to 3 departments',
                    'Up to 3 staff accounts',
                    'Single branch',
                ],
            ],
            [
                'name' => 'Clinic',
                'is_trial' => false,
                'is_public' => true,
                'price' => 24999,
                'health_modules' => HealthModuleService::ORG_TYPE_DEFAULTS['clinic'],
                'health_department_limit' => 8,
                'user_limit' => 10,
                'branch_limit' => 1,
                'features' => [
                    'Outpatients (OPD) & appointments',
                    'Patient billing & accounts',
                    'Up to 8 departments',
                    'Up to 10 staff accounts with separated roles',
                    'Single branch',
                ],
            ],
            [
                'name' => 'Hospital',
                'is_trial' => false,
                'is_public' => true,
                'price' => 59999,
                // Everything: OPD, pharmacy, inpatients, laboratory, accounts, HR.
                'health_modules' => HealthModuleService::MODULES,
                'health_department_limit' => -1,
                'user_limit' => -1,
                'branch_limit' => -1,
                'features' => [
                    'Every module — OPD, pharmacy, inpatients, laboratory, accounts & HR',
                    'Unlimited departments',
                    'Unlimited staff accounts with per-member access delegation',
                    'Unlimited branches with branch & department boundaries',
                ],
            ],
        ];
    }

    /** Insert one package unless a healthcare package of that name exists. */
    private function ensure(array $package): void
    {
        $exists = DB::table('pricing_plans')
            ->where('product_type', HealthPanel::PRODUCT_TYPE)
            ->where('name', $package['name'])
            ->exists();

        if ($exists) {
            return;
        }

        $row = [
            'name' => $package['name'],
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'is_trial' => $package['is_trial'],
            // Healthcare does not meter invoices — the limit that matters is
            // departments, and that has its own column.
            'invoice_limit' => -1,
            'price' => $package['price'],
            'features' => json_encode($package['features']),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Every optional column stays behind hasColumn: production has drifted
        // before, and a package row is worth more than a perfect one.
        $optional = [
            'is_public' => $package['is_public'],
            'user_limit' => $package['user_limit'],
            'branch_limit' => $package['branch_limit'],
            'max_users' => $package['user_limit'],
            'health_modules' => json_encode(array_values($package['health_modules'])),
            'health_department_limit' => $package['health_department_limit'],
            // Healthcare has its own modules; the POS/DI feature gates are not
            // its switches, so they stay off rather than accidentally granting
            // a retail capability to a hospital.
            'inventory_enabled' => false,
            'restaurant_enabled' => false,
            'reports_enabled' => true,
        ];

        foreach ($optional as $column => $value) {
            if (Schema::hasColumn('pricing_plans', $column)) {
                $row[$column] = $value;
            }
        }

        DB::table('pricing_plans')->insert($row);
    }

    /**
     * Deliberately empty.
     *
     * Deleting a package row would orphan any subscription, payment proof or
     * receipt that points at it — the same reason no other product line's plan
     * seed rolls back. Retire a package through the sellability allowlist.
     */
    public function down(): void
    {
        // no-op
    }
};
