<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use Illuminate\Console\Command;

/**
 * Seed the cigarette product catalogue for a Digital Invoice distributor.
 *
 * WHY A COMMAND: the DI product catalogue (/products) has no bulk import, and
 * `tinker` is disabled on the production host, so this is the only reviewable,
 * repeatable way to put a known rate card into a live company.  It is
 * idempotent — re-running it updates the existing rows instead of duplicating
 * them, so the rate card can be corrected and re-applied at any time.
 *
 * THE NUMBERS
 * -----------
 * The distributor's DMS quotes a GROSS rate (everything included).  FBR wants
 * three different figures per line, and both conversions are per-invoice:
 *
 *   ex-tax price = DMS gross rate x (invoice Value-Exclusive / invoice Sub Total)
 *   MRP          = ex-tax price   x (invoice Sales Tax / (18% x Value-Exclusive))
 *   sales tax    = 18% x MRP x quantity          <- 3rd Schedule: tax rides MRP
 *
 * Both factors drift slightly from invoice to invoice, so the values stored
 * here are STARTING POINTS for manual entry.  An invoice import must always
 * recompute them from that invoice's own totals — never from this table.
 *
 * QUANTITY: declared in FBR's "Thousand Unit" (1 = 1000 sticks = 50 packs
 * = 5 outers), so every rate below is per Thousand Unit, and a shop buying
 * 2 packs is quantity 0.04.
 */
class SeedCigaretteCatalogue extends Command
{
    protected $signature = 'di:cigarette-catalogue
                            {company : Company id to seed the catalogue for}
                            {--dry-run : Show what would change without writing}';

    protected $description = 'Create/update the cigarette product catalogue (HS 2402.2000, Thousand Unit) for a DI company';

    /** FBR heading for cigarettes containing tobacco. */
    private const HS_CODE = '2402.2000';

    /**
     * Mean ex-tax factor observed across the distributor's DMS invoices
     * (0.821957-0.822872). Used only to seed a starting price.
     */
    private const EX_TAX_FACTOR = 0.821969;

    /**
     * Mean MRP uplift observed on the FBR-verified invoice, i.e.
     * Sales Tax / (18% x Value-Exclusive). Used only to seed a starting MRP.
     */
    private const MRP_FACTOR = 1.038378;

    /**
     * DMS gross rate per Thousand Unit, as supplied by the distributor.
     * Divide by 5 for an outer, by 50 for a pack.
     */
    private const RATE_CARD = [
        'Marlboro Gold' => 26865.00,
        'Red & White Special' => 12880.00,
        'Morven' => 12048.85,
        'Parliament Night Blue' => 10491.20,
        'Red & White Firm Filter' => 9526.62,
        'Diplomat' => 9020.17,
        'Morven Classic' => 8523.65,
        'Crafted By Marlboro' => 8498.50,
    ];

    public function handle(): int
    {
        $companyId = (int) $this->argument('company');
        $dryRun = (bool) $this->option('dry-run');

        // Drop only the tenant scope — soft-delete filtering must stay on, so a
        // deleted company is never resurrected by seeding a catalogue into it.
        $company = Company::withoutGlobalScope(CompanyScope::class)->find($companyId);
        if (!$company) {
            $this->error("Company {$companyId} not found.");
            return self::FAILURE;
        }

        $this->info("Company {$companyId}: {$company->name}");
        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        $rows = [];
        $created = 0;
        $updated = 0;

        foreach (self::RATE_CARD as $name => $grossPerThousand) {
            $price = round($grossPerThousand * self::EX_TAX_FACTOR, 2);
            $mrp = round($price * self::MRP_FACTOR, 2);

            $attributes = [
                'hs_code' => self::HS_CODE,
                'pct_code' => self::HS_CODE,
                'uom' => 'Thousand Unit',
                'default_tax_rate' => 18,
                'tax_type' => 'taxable',
                'schedule_type' => '3rd_schedule',
                'is_third_schedule' => true,
                'default_price' => $price,
                'mrp' => $mrp,
                'is_active' => true,
            ];

            $existing = Product::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->first();

            $action = $existing ? 'update' : 'create';
            $existing ? $updated++ : $created++;

            if (!$dryRun) {
                Product::withoutGlobalScope(CompanyScope::class)->updateOrCreate(
                    ['company_id' => $companyId, 'name' => $name],
                    $attributes
                );
            }

            $rows[] = [
                $action,
                $name,
                number_format($grossPerThousand, 2),
                number_format($price, 2),
                number_format($mrp, 2),
                number_format($grossPerThousand / 50, 2),
            ];
        }

        $this->table(
            ['', 'Product', 'DMS gross /Ms', 'Ex-tax price /Ms', 'MRP /Ms', 'gross /pack'],
            $rows
        );

        $this->info(($dryRun ? 'Would create' : 'Created') . " {$created}, "
            . ($dryRun ? 'would update' : 'updated') . " {$updated}.");

        $total = Product::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->count();
        $this->line("Catalogue now holds {$total} product(s) for this company.");

        return self::SUCCESS;
    }
}
