<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use Illuminate\Console\Command;

/**
 * Seed the tobacco product catalogue for a Digital Invoice distributor.
 *
 * WHY A COMMAND: the DI product catalogue (/products) has no bulk import, and
 * `tinker` is disabled on the production host, so this is the only reviewable,
 * repeatable way to put a known rate card into a live company.  It is
 * idempotent — re-running it updates the existing rows instead of duplicating
 * them, so the rate card can be corrected and re-applied at any time.
 *
 * THE NUMBERS
 * -----------
 * The distributor's DMS quotes a rate per Thousand Unit, and that rate is the
 * value FBR is filed on:
 *
 *   line value = quantity(Ms) x rate(per Ms)
 *   line tax   = 18% x line value
 *
 * Cigarettes (HS 2402.2000) sit on the 3rd Schedule, so each line must also
 * carry an MRP — but the notified value equals the invoice value.  That is
 * exactly how the distributor's own Annex-A reports the purchase side: for
 * every 3rd Schedule row `Value of Purchases == Value of Fixed/notified` and
 * `Sales Tax == 18% of it`.  So the seeded MRP per Ms is the rate itself, not
 * an uplift on it.
 *
 * A previous revision of this command derived the price and MRP from two
 * per-invoice factors (~0.822 ex-tax, then ~1.0384 MRP uplift) reverse
 * engineered from the one FBR-verified invoice.  Those are gone: that invoice
 * is the known-bad one (quantities filed 50x over) and its value base does not
 * reconcile with Annex-A.  Deriving a rate card from a single mis-filed
 * invoice was the error.
 *
 * QUANTITY: cigarettes are declared in FBR's "Thousand Unit" (1 = 1000 sticks
 * = 50 packs = 5 outers), so every rate below is per Thousand Unit, and a shop
 * buying 2 packs is quantity 0.04.
 *
 * WHAT THIS COMMAND OWNS: the tax classification (heading, schedule, UoM, MRP,
 * SRO).  Getting those wrong mis-files an invoice silently, so they are always
 * normalised.  A selling price the distributor typed in is his data, and is
 * only overwritten where the rate card is the whole point (the cigarettes).
 */
class SeedCigaretteCatalogue extends Command
{
    protected $signature = 'di:cigarette-catalogue
                            {company : Company id to seed the catalogue for}
                            {--dry-run : Show what would change without writing}';

    protected $description = 'Create/update the tobacco product catalogue (HS 2402.2000 cigarettes + HS 2404.9100 pouches) for a DI company';

    /** FBR heading for cigarettes containing tobacco — 3rd Schedule. */
    private const HS_CIGARETTES = '2402.2000';

    /**
     * FBR heading for nicotine pouches / oral tobacco substitutes.  One digit
     * away from the cigarette heading but a completely different treatment:
     * standard rate, no MRP, and Annex-A reports its notified value as 0.
     */
    private const HS_NICOTINE_POUCHES = '2404.9100';

    /**
     * DMS rate per Thousand Unit, as published in the distributor's own
     * "Sale Area" export (its last row carries the rate the sheet's Amount
     * formula multiplies by).  Divide by 5 for an outer, by 50 for a pack.
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

    /**
     * FBR UoM for goods sold by the piece.  The pouch rates below are per CAN,
     * so the unit has to say so.  Annex-A reports pouches by weight, but a
     * per-can rate filed against "Kilograms" would understate the line by the
     * ~100 cans that make up a kilo — the same silent unit trap as the 50x
     * cigarette bug, and FBR would not warn about this one either.
     */
    private const UOM_PIECES = 'Numbers, pieces, units';

    /**
     * Standard-rate tobacco lines that are NOT on the 3rd Schedule, so they
     * carry no MRP and no SRO.  None of them appear in the volume export —
     * these are invoiced by hand.
     *
     * Rates are per can, supplied by the distributor (25 Aug 2026).  A price
     * he later corrects in the UI is still preserved on every re-run.
     */
    private const STANDARD_RATE_PRODUCTS = [
        'ZYN Cool Mint 6mg' => [
            'hs_code' => self::HS_NICOTINE_POUCHES,
            'uom' => self::UOM_PIECES,
            'price' => 137.00,
        ],
        'ZYN Cool Mint 11mg' => [
            'hs_code' => self::HS_NICOTINE_POUCHES,
            'uom' => self::UOM_PIECES,
            'price' => 183.80,
        ],
        'ZYN Cool Mint 13.5mg' => [
            'hs_code' => self::HS_NICOTINE_POUCHES,
            'uom' => self::UOM_PIECES,
            'price' => 230.00,
        ],
    ];

    /**
     * The unpriced "ZYN" placeholder that stood in before the three variants
     * had rates.  Deactivated rather than deleted, so an invoice already
     * written against it keeps its product row while nobody can pick a
     * zero-rate line for a new sale.
     */
    private const RETIRED_PRODUCTS = ['ZYN'];

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

        foreach (self::RATE_CARD as $name => $ratePerThousand) {
            // 3rd Schedule: the notified/MRP value IS the invoice value, so the
            // MRP per unit is the same rate the line is priced at.
            $price = round($ratePerThousand, 2);

            $action = $this->persist($companyId, $name, [
                'hs_code' => self::HS_CIGARETTES,
                'pct_code' => self::HS_CIGARETTES,
                'uom' => 'Thousand Unit',
                'default_tax_rate' => 18,
                'tax_type' => 'taxable',
                'schedule_type' => '3rd_schedule',
                'is_third_schedule' => true,
                'default_price' => $price,
                'mrp' => $price,
                'is_active' => true,
            ], $dryRun, $created, $updated);

            $rows[] = [$action, $name, '3rd Schedule', 'Thousand Unit', number_format($price, 2), number_format($price, 2)];
        }

        foreach (self::STANDARD_RATE_PRODUCTS as $name => $spec) {
            $action = $this->persist($companyId, $name, [
                'hs_code' => $spec['hs_code'],
                'pct_code' => $spec['hs_code'],
                'uom' => $spec['uom'],
                'default_tax_rate' => 18,
                'tax_type' => 'taxable',
                'schedule_type' => 'standard',
                'is_third_schedule' => false,
                'default_price' => $spec['price'],
                // Standard rate carries no MRP. Null these explicitly so a row
                // previously classified as 3rd Schedule cannot keep a stale
                // MRP or SRO that would mis-file the line.
                'mrp' => null,
                'sro_reference' => null,
                'serial_number' => null,
                'is_active' => true,
            ], $dryRun, $created, $updated, preservePrice: true);

            $stored = $dryRun ? $spec['price'] : (float) Product::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)->where('name', $name)->value('default_price');

            $rows[] = [
                $action,
                $name,
                'Standard Rate',
                $spec['uom'],
                $stored > 0 ? number_format($stored, 2) : 'set on first sale',
                '—',
            ];
        }

        foreach (self::RETIRED_PRODUCTS as $name) {
            $stale = Product::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->where('is_active', true)
                ->get();

            if ($stale->isEmpty()) {
                continue;
            }
            if (!$dryRun) {
                foreach ($stale as $row) {
                    $row->fill(['is_active' => false])->save();
                }
            }

            $rows[] = ['retire', $name, 'superseded by the priced variants', '—', '—', '—'];
        }

        $this->table(['', 'Product', 'Schedule', 'UoM', 'Rate', 'MRP'], $rows);

        $this->info(($dryRun ? 'Would create' : 'Created') . " {$created}, "
            . ($dryRun ? 'would update' : 'updated') . " {$updated}.");

        $total = Product::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->count();
        $this->line("Catalogue now holds {$total} product(s) for this company.");

        return self::SUCCESS;
    }

    /**
     * Write one catalogue row, counting it as a create or an update.
     *
     * There is no unique index on (company_id, name), so a catalogue can
     * already hold more than one row under the same name — from a hand entry
     * plus an import, say.  Every matching row is normalised rather than just
     * the first, otherwise a duplicate keeps its wrong tax classification and
     * quietly mis-files whichever copy the cashier happens to pick.
     *
     * @param  int   $created        running total, by reference
     * @param  int   $updated        running total, by reference
     * @param  bool  $preservePrice  keep a non-zero price already on the row
     */
    private function persist(
        int $companyId,
        string $name,
        array $attributes,
        bool $dryRun,
        int &$created,
        int &$updated,
        bool $preservePrice = false
    ): string {
        $existing = Product::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->get();

        $exists = $existing->isNotEmpty();
        $exists ? $updated++ : $created++;

        if ($dryRun) {
            return $exists ? 'update' : 'create';
        }

        if (!$exists) {
            Product::withoutGlobalScope(CompanyScope::class)
                ->create($attributes + ['company_id' => $companyId, 'name' => $name]);

            return 'create';
        }

        foreach ($existing as $row) {
            $payload = $attributes;

            // A price the distributor typed in is his data — never replace it
            // with a placeholder.
            if ($preservePrice && (float) $row->default_price > 0) {
                unset($payload['default_price']);
            }

            $row->fill($payload)->save();
        }

        return 'update';
    }
}
