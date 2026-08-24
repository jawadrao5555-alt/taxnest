<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Services\InvoiceImportService;
use App\Services\ScheduleEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Turn a tobacco distributor's DMS "Sale Area" export into draft DI invoices.
 *
 * THE EXPORT'S SHAPE (it is a pivot, not a row-per-sale table)
 * -----------------------------------------------------------
 *   row 1  Calendar Date   — an Excel date serial, merged across each day's
 *                            block of columns, so it must be forward-filled.
 *   row 2  Product Description — one column per product per day, plus a
 *                            "Total" and an "Amount" column per block.
 *   row 3  Town Description | Customer Registered Name | Customer Code, then
 *                            "Volume (Ms)" over every data column.
 *   rows 5..n-1             one row per customer; cells are volumes.
 *   last row                the rate per Thousand Unit, referenced by the
 *                            sheet's own Amount formula.
 *
 * Volumes are ALREADY in FBR's "Thousand Unit" (0.04 = 2 packs). Nothing here
 * multiplies or divides them — treating them as packs is the 50x mis-filing
 * bug this whole import exists to avoid.
 *
 * ONE INVOICE PER (CUSTOMER CODE, DELIVERY DATE)
 * ----------------------------------------------
 * Each delivery is its own sale. 87 shops in this export share a registered
 * name with another shop, so the customer CODE — not the name — is the buyer
 * identity. InvoiceImportService groups by buyer NAME + date, which would
 * merge two same-named shops into one invoice, so this command hands the
 * service exactly one group at a time and keeps the boundary itself.
 *
 * WHERE THE MONEY COMES FROM
 * --------------------------
 * Rates are read from the company's own product catalogue, never from the
 * spreadsheet, so `di:cigarette-catalogue` stays the single source of truth
 * and a price the distributor corrects is respected on the next run.
 *
 *   line value = volume(Ms) x rate(per Ms);  tax = 18% of value
 *
 * These are 3rd Schedule goods, so each line also carries an MRP — and per the
 * distributor's Annex-A the notified value equals the invoice value, so the
 * per-unit MRP is the rate itself.
 *
 * SAFETY: this only ever creates DRAFTS (status=draft, fbr_status=null).
 * Nothing is sent to FBR. Re-running skips groups already imported, so a run
 * killed halfway can simply be run again.
 */
class ImportSaleAreaInvoices extends Command
{
    protected $signature = 'di:import-sale-area
                            {company : Company id to import into}
                            {file : Path to the DMS "Sale Area" .xlsx export}
                            {--dry-run : Summarise what would be created, write nothing}
                            {--date= : Only import this delivery date (Y-m-d)}
                            {--limit= : Stop after creating this many invoices}
                            {--user= : User id to attribute the audit trail to}';

    protected $description = 'Create draft DI invoices from a DMS "Sale Area" volume export';

    /** Buyers are small retail shops with no NTN on file. */
    private const DESTINATION_PROVINCE = 'Punjab';

    /** Pivot columns that hold a subtotal rather than a product. */
    private const NON_PRODUCT_COLUMNS = ['Total', 'Amount', 'Product Description', ''];

    public function handle(InvoiceImportService $importer): int
    {
        @ini_set('memory_limit', '2048M');

        $companyId = (int) $this->argument('company');
        $path = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');
        $onlyDate = $this->option('date') ?: null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;

        $company = Company::withoutGlobalScope(CompanyScope::class)->find($companyId);
        if (!$company) {
            $this->error("Company {$companyId} not found.");
            return self::FAILURE;
        }
        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $standardTaxRate = $company->getStandardTaxRateValue();
        $catalogue = $this->loadCatalogue($companyId);
        if ($catalogue === []) {
            $this->error("Company {$companyId} has no products. Run di:cigarette-catalogue first.");
            return self::FAILURE;
        }

        $this->info("Company {$companyId}: {$company->name}");
        $this->line('Reading ' . basename($path) . ' …');

        try {
            [$groups, $missingProducts] = self::parseSaleArea($path, $catalogue);
        } catch (\Throwable $e) {
            $this->error('Could not read the export: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($missingProducts !== []) {
            // Silently dropping a product would under-report the month to FBR.
            $this->error('These products are in the export but not in the catalogue:');
            foreach ($missingProducts as $name) {
                $this->line("  - {$name}");
            }
            $this->line('Add them (di:cigarette-catalogue) and re-run.');
            return self::FAILURE;
        }

        if ($onlyDate !== null) {
            $groups = array_filter($groups, fn ($g) => $g['date'] === $onlyDate);
        }
        if ($groups === []) {
            $this->warn('Nothing to import' . ($onlyDate ? " for {$onlyDate}." : '.'));
            return self::SUCCESS;
        }

        $this->summarise($groups);

        if ($dryRun) {
            $this->warn('DRY RUN — nothing written.');
            return self::SUCCESS;
        }

        // Resumability: a half-finished run must not double-file a shop.
        $alreadyImported = $this->existingKeys($companyId);
        $batchId = $this->openBatch($companyId, $userId, $path, count($groups));

        $created = 0;
        $skipped = 0;
        $failed = [];
        $bar = $this->output->createProgressBar(count($groups));
        $bar->start();

        foreach ($groups as $group) {
            $bar->advance();

            if (isset($alreadyImported[self::addressFor($group) . '|' . $group['date']])) {
                $skipped++;
                continue;
            }
            if ($limit !== null && $created >= $limit) {
                $skipped++;
                continue;
            }

            try {
                $rows = self::rowsFor($group, $catalogue);

                // The same gate the web form uses, so a bad group is reported
                // instead of being written as an unsubmittable draft.
                $errors = ScheduleEngine::validateItems(array_column($rows, 'data'), $standardTaxRate);
                if ($errors !== []) {
                    $failed[] = $group['code'] . ' ' . $group['date'] . ': ' . implode('; ', $errors);
                    continue;
                }

                // One group per call — the service groups by buyer NAME, which
                // would merge two shops that share a name on the same day.
                $result = $importer->createDraftsFromRows(
                    $rows,
                    $company,
                    $userId,
                    'sale_area_import',
                    null,
                    null,
                    $batchId
                );
                $created += $result['created_count'];
                foreach ($result['row_errors'] as $rowError) {
                    $failed[] = $group['code'] . ' ' . $group['date'] . ': ' . implode('; ', $rowError['errors']);
                }
            } catch (\Throwable $e) {
                $failed[] = $group['code'] . ' ' . $group['date'] . ': ' . $e->getMessage();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->closeBatch($batchId, $created, count($failed));

        $this->info("Created {$created} draft invoice(s)." . ($skipped ? " Skipped {$skipped} (already imported or over --limit)." : ''));
        if ($failed !== []) {
            $this->warn(count($failed) . ' group(s) failed:');
            foreach (array_slice($failed, 0, 20) as $line) {
                $this->line('  ' . $line);
            }
        }
        if ($batchId !== null) {
            $this->line("Import batch id: {$batchId}");
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------
    // Parsing
    // ------------------------------------------------------------------

    /**
     * Read the pivot into one group per (customer code, delivery date).
     *
     * @param  array<string, array>  $catalogue  upper-cased product name => catalogue row
     * @return array{0: array<string, array>, 1: array<int, string>}  [groups, product names with no catalogue entry]
     */
    public static function parseSaleArea(string $path, array $catalogue): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0);

        $lastRow = $sheet->getHighestRow();
        $grid = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . $lastRow, null, false, false, false);

        $dateRow = $grid[0] ?? [];
        $productRow = $grid[1] ?? [];
        // The sheet's own rate row is the last row; it is the fallback identity
        // for a product column whose header cell was lost to a merge.
        $rateRow = $grid[$lastRow - 1] ?? [];

        $nameByRate = [];
        foreach ($productRow as $col => $label) {
            $label = trim((string) $label);
            if (in_array($label, self::NON_PRODUCT_COLUMNS, true)) {
                continue;
            }
            $rate = $rateRow[$col] ?? null;
            if ($rate !== null && $rate !== '' && is_numeric($rate)) {
                $nameByRate[self::rateKey((float) $rate)] = $label;
            }
        }

        // Resolve every data column to (product, date). The date is merged
        // across each block, so it carries forward until the next one.
        $columns = [];
        $missing = [];
        $currentDate = null;
        for ($col = 3; $col < count($productRow); $col++) {
            $serial = $dateRow[$col] ?? null;
            if ($serial !== null && $serial !== '' && is_numeric($serial)) {
                $currentDate = ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
            }

            $label = trim((string) ($productRow[$col] ?? ''));
            if (in_array($label, self::NON_PRODUCT_COLUMNS, true) && $label !== '') {
                continue;
            }
            if ($label === '') {
                $rate = $rateRow[$col] ?? null;
                $key = ($rate !== null && $rate !== '' && is_numeric($rate)) ? self::rateKey((float) $rate) : null;
                // No header and no rate = the grand-total column, not a product.
                if ($key === null || !isset($nameByRate[$key])) {
                    continue;
                }
                $label = $nameByRate[$key];
            }
            if ($currentDate === null) {
                continue;
            }

            $upper = mb_strtoupper($label);
            if (!isset($catalogue[$upper])) {
                $missing[$upper] = $upper;
                continue;
            }
            $columns[$col] = ['product' => $upper, 'date' => $currentDate];
        }

        if ($missing !== []) {
            return [[], array_values($missing)];
        }

        $groups = [];
        for ($i = 4; $i < $lastRow - 1; $i++) {
            $row = $grid[$i] ?? null;
            if ($row === null) {
                continue;
            }

            $town = trim((string) ($row[0] ?? ''));
            // DMS exports shop names HTML-escaped ("&#39;-SHAFEEQ KIRYANA").
            $buyer = trim(html_entity_decode((string) ($row[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $code = trim((string) ($row[2] ?? ''));
            if ($buyer === '' || $code === '') {
                continue;
            }

            foreach ($columns as $col => $meta) {
                $volume = $row[$col] ?? null;
                if ($volume === null || $volume === '' || !is_numeric($volume) || (float) $volume == 0.0) {
                    continue;
                }

                $key = $code . '|' . $meta['date'];
                $groups[$key] ??= [
                    'buyer' => $buyer,
                    'town' => $town,
                    'code' => $code,
                    'date' => $meta['date'],
                    'lines' => [],
                ];
                $groups[$key]['lines'][] = [
                    'product' => $meta['product'],
                    'quantity' => (float) $volume,
                ];
            }
        }

        return [$groups, []];
    }

    /** Rates are unique per product, so a rounded rate identifies the column. */
    private static function rateKey(float $rate): string
    {
        return number_format($rate, 2, '.', '');
    }

    // ------------------------------------------------------------------
    // Row building
    // ------------------------------------------------------------------

    /** @return array<int, array{row:int, data:array}> */
    public static function rowsFor(array $group, array $catalogue): array
    {
        $rows = [];
        foreach ($group['lines'] as $index => $line) {
            $product = $catalogue[$line['product']];
            $rate = (float) $product['default_price'];
            $value = round($line['quantity'] * $rate, 2);
            $taxRate = (float) ($product['default_tax_rate'] ?: 18);

            $rows[] = ['row' => $index + 1, 'data' => [
                'buyer_name' => $group['buyer'],
                'buyer_ntn' => '',
                'buyer_cnic' => '',
                'buyer_address' => self::addressFor($group),
                'document_type' => 'Sale Invoice',
                'reference_invoice_number' => '',
                'destination_province' => self::DESTINATION_PROVINCE,
                'invoice_date' => $group['date'],

                'hs_code' => (string) $product['hs_code'],
                'description' => $product['name'],
                'quantity' => $line['quantity'],
                'price' => $rate,
                'tax' => round($value * $taxRate / 100, 2),

                'schedule_type' => (string) $product['schedule_type'],
                'tax_rate' => $taxRate,
                'sro_schedule_no' => (string) ($product['sro_reference'] ?? ''),
                'sro_serial_no' => (string) ($product['serial_number'] ?? ''),
                // 3rd Schedule: the notified value IS the invoice value, so the
                // per-unit MRP is the rate.
                'mrp' => $product['mrp'] !== null ? (float) $product['mrp'] : '',

                '_pct_code' => (string) ($product['pct_code'] ?: $product['hs_code']),
                // Without this the UOM falls back to "Numbers, pieces, units"
                // and the whole point of the import — Thousand Unit — is lost.
                '_default_uom' => (string) $product['uom'],
                '_sale_type' => ScheduleEngine::mapSaleType((string) $product['schedule_type']),
            ]];
        }

        return $rows;
    }

    /** The code disambiguates the 87 shops that share a registered name. */
    private static function addressFor(array $group): string
    {
        return $group['town'] !== '' ? "{$group['town']} ({$group['code']})" : $group['code'];
    }

    // ------------------------------------------------------------------
    // Catalogue, batch bookkeeping and reporting
    // ------------------------------------------------------------------

    /** @return array<string, array> upper-cased product name => row */
    private function loadCatalogue(int $companyId): array
    {
        $catalogue = [];
        $products = Product::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->get();

        foreach ($products as $product) {
            $catalogue[mb_strtoupper($product->name)] = [
                'name' => $product->name,
                'hs_code' => $product->hs_code,
                'pct_code' => $product->pct_code,
                'uom' => $product->uom,
                'schedule_type' => $product->schedule_type,
                'sro_reference' => $product->sro_reference,
                'serial_number' => $product->serial_number,
                'mrp' => $product->mrp,
                'default_price' => $product->default_price,
                'default_tax_rate' => $product->default_tax_rate,
            ];
        }

        return $catalogue;
    }

    /** @return array<string, true> "address|date" of invoices this import already made */
    private function existingKeys(int $companyId): array
    {
        $keys = [];
        Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('buyer_address')
            ->select(['buyer_address', 'invoice_date'])
            ->chunk(2000, function ($chunk) use (&$keys) {
                foreach ($chunk as $invoice) {
                    $date = $invoice->invoice_date instanceof \DateTimeInterface
                        ? $invoice->invoice_date->format('Y-m-d')
                        : substr((string) $invoice->invoice_date, 0, 10);
                    $keys[$invoice->buyer_address . '|' . $date] = true;
                }
            });

        return $keys;
    }

    private function openBatch(int $companyId, ?int $userId, string $path, int $groupCount): ?int
    {
        if (!Schema::hasTable('invoice_import_batches')) {
            return null;
        }

        return (int) DB::table('invoice_import_batches')->insertGetId([
            'company_id' => $companyId,
            'user_id' => $userId,
            'original_filename' => basename($path),
            'source_format' => 'xlsx',
            'status' => 'processing',
            'total_rows' => $groupCount,
            'valid_rows' => $groupCount,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function closeBatch(?int $batchId, int $created, int $failed): void
    {
        if ($batchId === null) {
            return;
        }

        DB::table('invoice_import_batches')->where('id', $batchId)->update([
            'status' => $failed === 0 ? 'completed' : 'failed',
            'created_invoices' => $created,
            'processed_rows' => $created + $failed,
            'failed_rows' => $failed,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function summarise(array $groups): void
    {
        $value = 0.0;
        $lines = 0;
        $shops = [];
        $byDate = [];

        foreach ($groups as $group) {
            $shops[$group['code']] = true;
            $lines += count($group['lines']);
            $byDate[$group['date']] = ($byDate[$group['date']] ?? 0) + 1;
        }
        ksort($byDate);

        $catalogue = $this->loadCatalogue((int) $this->argument('company'));
        foreach ($groups as $group) {
            foreach ($group['lines'] as $line) {
                $value += $line['quantity'] * (float) $catalogue[$line['product']]['default_price'];
            }
        }
        $tax = $value * 0.18;

        $this->table(['', ''], [
            ['Invoices', number_format(count($groups))],
            ['Line items', number_format($lines)],
            ['Shops', number_format(count($shops))],
            ['Delivery days', count($byDate)],
            ['Value (ex-tax)', number_format($value, 2)],
            ['Sales tax @18%', number_format($tax, 2)],
            ['Gross', number_format($value + $tax, 2)],
        ]);

        $this->line('Per day: ' . implode('  ', array_map(
            fn ($d, $n) => substr($d, 5) . '=' . $n,
            array_keys($byDate),
            $byDate
        )));
    }
}
