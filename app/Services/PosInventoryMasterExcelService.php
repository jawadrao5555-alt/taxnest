<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * NestPOS Inventory Master Excel (Phase 1) — master data only.
 *
 * One sheet ("Master"), Row Type PRODUCT | INGREDIENT | RECIPE.
 * Processing order is always INGREDIENT → PRODUCT → RECIPE.
 * This importer never posts, adjusts, transfers, or otherwise changes stock
 * or inventory/ingredient ledgers. See docs/architecture/38-final-inventory-architecture-ux-spec.txt §3.
 */
class PosInventoryMasterExcelService
{
    public const SAMPLE_MARKER = 'Misal:';
    public const SHEET_NAME = 'Master';
    public const FILENAME = 'nestpos_inventory_master.xlsx';
    public const MAX_DATA_ROWS = 5000;
    public const MAX_RECIPE_ROWS = 2000;

    public const HEADERS = [
        'Row Type',
        'Product Name',
        'Product Code',
        'Price',
        'Category',
        'Description',
        'Tax Rate %',
        'Unit (UOM)',
        'Tax Exempt',
        'Third Schedule',
        'Ingredient Name',
        'Ingredient Code',
        'Ingredient Unit',
        'Cost per Unit',
        'Min Stock',
        'Quantity Needed',
        'Active',
    ];

    /**
     * Build the owner-facing template (frozen header, dropdown, TEXT codes, Misal: samples).
     */
    public function buildTemplateSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NAME);

        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:Q1')->getFont()->setBold(true);
        $sheet->getStyle('A1:Q1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E9D5FF');
        $sheet->freezePane('A2');
        $sheet->getRowDimension(1)->setRowHeight(22);

        $widths = [
            'A' => 14, 'B' => 22, 'C' => 16, 'D' => 10, 'E' => 12, 'F' => 18,
            'G' => 12, 'H' => 12, 'I' => 12, 'J' => 14, 'K' => 18, 'L' => 14,
            'M' => 16, 'N' => 14, 'O' => 12, 'P' => 16, 'Q' => 10,
        ];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $sheet->getStyle('C:C')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('L:L')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $comments = [
            'A' => "Required on every row.\nPRODUCT = sellable item (name + price).\nINGREDIENT = kitchen item (name + unit).\nRECIPE = how much ingredient one sold product uses.\nThis file never changes stock quantity.",
            'B' => 'Required for PRODUCT and RECIPE (or use Product Code).',
            'C' => 'Optional. SKU / barcode. Stored as TEXT so long codes stay intact.',
            'D' => 'Required for PRODUCT. Sale price. Leave blank on INGREDIENT / RECIPE.',
            'E' => 'Optional PRODUCT category.',
            'F' => 'Optional PRODUCT description.',
            'G' => 'Optional PRODUCT tax rate. Example: 16',
            'H' => 'Optional PRODUCT unit (NOS, PCS, KG…). Not the kitchen unit.',
            'I' => 'Optional PRODUCT. Yes / No.',
            'J' => 'Optional PRODUCT. Yes / No. Yes also means tax exempt and 0% tax.',
            'K' => 'Required for INGREDIENT and RECIPE.',
            'L' => 'Optional. Stored as TEXT.',
            'M' => 'Required for INGREDIENT. Kitchen units: kg, g, ltr, ml, pcs, dozen, pack. RECIPE unit must match.',
            'N' => 'INGREDIENT only. Cost of one kitchen unit. RECIPE rows must leave this blank.',
            'O' => 'Optional INGREDIENT low-stock level. Not on-hand quantity.',
            'P' => 'Required for RECIPE. Quantity of the ingredient used per 1 sold product. Example: 0.25 kg rice per Biryani.',
            'Q' => 'Optional. Yes / No. Blank keeps the current value (new rows default Yes).',
        ];
        foreach ($comments as $col => $text) {
            $sheet->getComment($col . '1')->getText()->createTextRun($text);
            $sheet->getComment($col . '1')->setWidth('240px');
            $sheet->getComment($col . '1')->setHeight('110px');
        }

        $validation = $sheet->getCell('A2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setFormula1('"PRODUCT,INGREDIENT,RECIPE"');
        $validation->setPromptTitle('Row Type');
        $validation->setPrompt('PRODUCT = menu item. INGREDIENT = kitchen item. RECIPE = BOM line. Never put stock qty here.');
        $validation->setErrorTitle('Invalid Row Type');
        $validation->setError('Use PRODUCT, INGREDIENT, or RECIPE.');
        $validation->setSqref('A2:A2000');

        $samples = $this->templateSamples();
        $rowNum = 2;
        foreach ($samples as $sample) {
            $this->writeMasterRow($sheet, $rowNum, $sample);
            $sheet->getStyle("A{$rowNum}:Q{$rowNum}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FEF3C7');
            $rowNum++;
        }

        return $spreadsheet;
    }

    public function streamTemplate()
    {
        $spreadsheet = $this->buildTemplateSpreadsheet();
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, self::FILENAME, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Import a Master workbook. Never writes stock or ledger rows.
     *
     * @return array{ok:bool,fatal:?string,message:string,errors:array<int,string>,counts:array<string,int>}
     */
    public function import(string $path, int $companyId, ?Company $company = null): array
    {
        $company = $company ?? Company::find($companyId);
        $excelOn = $this->excelAllowed($company);
        $recipesOn = $this->recipesAllowed($company);

        try {
            $parsed = $this->readMasterRows($path);
        } catch (\Throwable $e) {
            Log::error('POS inventory master import parse failed: ' . $e->getMessage());
            return $this->result(false, __('pos.inventory_master_file_unreadable'), [], fatal: __('pos.inventory_master_file_unreadable'));
        }

        if (!empty($parsed['fatal'])) {
            return $this->result(false, $parsed['fatal'], [], fatal: $parsed['fatal']);
        }

        $rows = $parsed['rows'];
        $headerIdx = $parsed['header_idx'];
        $header = $parsed['header'];
        $map = $this->columnMap($header);

        if ($map['row_type'] === false) {
            $msg = __('pos.inventory_master_missing_row_type');
            return $this->result(false, $msg, [], fatal: $msg);
        }

        $productFamily = 0;
        $recipeCount = 0;
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $type = $this->normalizeRowType($this->cell($rows[$i], $map['row_type']));
            if ($type === 'recipe') {
                $recipeCount++;
            }
            if (in_array($type, ['product', 'ingredient', 'recipe'], true)) {
                $productFamily++;
            }
        }
        if ($productFamily > self::MAX_DATA_ROWS) {
            $msg = __('pos.inventory_master_too_many_rows', ['max' => self::MAX_DATA_ROWS]);
            return $this->result(false, $msg, [], fatal: $msg);
        }
        if ($recipeCount > self::MAX_RECIPE_ROWS) {
            $msg = __('pos.inventory_master_too_many_recipes', ['max' => self::MAX_RECIPE_ROWS]);
            return $this->result(false, $msg, [], fatal: $msg);
        }

        $catalog = $this->preloadCatalog($companyId);

        $errors = [];
        $skipped = 0;
        $samplesSkipped = 0;
        $gated = 0;
        $ingredientOps = [];
        $productOps = [];
        $recipeOps = [];

        $workRows = [];
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $data = $rows[$i];
            $rowNo = $i + 1;
            if ($this->rowEmpty($data)) {
                continue;
            }
            $parsedRow = $this->extractRow($data, $map);
            if ($this->isSampleRow($parsedRow)) {
                $samplesSkipped++;
                continue;
            }
            $workRows[] = ['row' => $rowNo, 'parsed' => $parsedRow];
        }

        // Pass 1 — INGREDIENT (prepare only)
        foreach ($workRows as $item) {
            if ($item['parsed']['row_type'] !== 'ingredient') {
                continue;
            }
            if (!$recipesOn) {
                $gated++;
                $errors[] = $this->rowError($item['row'], 'INGREDIENT', $item['parsed']['ingredient_name'] ?: $item['parsed']['ingredient_code'], __('pos.inventory_master_skip_recipes_off'));
                continue;
            }
            $op = $this->prepareIngredient($item['row'], $item['parsed'], $catalog, $companyId, $errors);
            if ($op) {
                $ingredientOps[] = $op;
                $this->rememberPendingIngredient($catalog, $op);
            } else {
                $skipped++;
            }
        }

        // Pass 2 — PRODUCT (prepare only)
        foreach ($workRows as $item) {
            if ($item['parsed']['row_type'] !== 'product') {
                continue;
            }
            if (!$excelOn) {
                $gated++;
                $errors[] = $this->rowError($item['row'], 'PRODUCT', $item['parsed']['product_name'] ?: $item['parsed']['product_code'], __('pos.inventory_master_skip_excel_off'));
                continue;
            }
            $op = $this->prepareProduct($item['row'], $item['parsed'], $catalog, $companyId, $company, $errors);
            if ($op) {
                $productOps[] = $op;
                $this->rememberPendingProduct($catalog, $op);
            } else {
                $skipped++;
            }
        }

        // Pass 3 — RECIPE (prepare only; ingredients/products from earlier passes are already indexed)
        foreach ($workRows as $item) {
            if ($item['parsed']['row_type'] !== 'recipe') {
                continue;
            }
            if (!$recipesOn) {
                $gated++;
                $errors[] = $this->rowError($item['row'], 'RECIPE', $item['parsed']['product_name'] ?: $item['parsed']['ingredient_name'], __('pos.inventory_master_skip_recipes_off'));
                continue;
            }
            $op = $this->prepareRecipe($item['row'], $item['parsed'], $catalog, $companyId, $errors);
            if ($op) {
                $recipeOps[] = $op;
            } else {
                $skipped++;
            }
        }

        foreach ($workRows as $item) {
            $type = $item['parsed']['row_type'];
            if ($type === null) {
                $skipped++;
                $errors[] = $this->rowError($item['row'], 'UNKNOWN', $item['parsed']['product_name'] ?: $item['parsed']['ingredient_name'], __('pos.inventory_master_invalid_row_type'));
            }
        }

        $counts = [
            'ingredients_added' => 0,
            'ingredients_updated' => 0,
            'products_added' => 0,
            'products_updated' => 0,
            'recipes_added' => 0,
            'recipes_updated' => 0,
            'samples_skipped' => $samplesSkipped,
            'rows_skipped' => $skipped,
            'gated_skipped' => $gated,
            'plan_skipped' => 0,
        ];

        $hasWrites = $ingredientOps || $productOps || $recipeOps;
        if (!$hasWrites) {
            return $this->result(false, $this->flashMessage($counts, $errors), $errors, $counts);
        }

        $planRemaining = $excelOn
            ? PlanLimitService::remainingProductAllowance($companyId, 'pos')
            : null;

        DB::beginTransaction();
        try {
            Company::where('id', $companyId)->lockForUpdate()->get();

            foreach ($ingredientOps as $op) {
                $this->applyIngredient($op, $catalog, $counts);
            }
            foreach ($productOps as $op) {
                if ($op['action'] === 'create' && $planRemaining !== null && $planRemaining <= 0) {
                    $counts['plan_skipped']++;
                    continue;
                }
                $this->applyProduct($op, $catalog, $counts);
                if ($op['action'] === 'create' && $planRemaining !== null) {
                    $planRemaining--;
                }
            }
            foreach ($recipeOps as $op) {
                $this->applyRecipe($op, $catalog, $companyId, $counts, $errors);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS inventory master import write failed: ' . $e->getMessage());
            throw $e;
        }

        $wrote = ($counts['ingredients_added'] + $counts['ingredients_updated']
            + $counts['products_added'] + $counts['products_updated']
            + $counts['recipes_added'] + $counts['recipes_updated']) > 0;

        return $this->result($wrote, $this->flashMessage($counts, $errors), $errors, $counts);
    }

    public function excelAllowed(?Company $company): bool
    {
        return PosFeatureService::planAllows($company, 'excel_enabled');
    }

    public function recipesAllowed(?Company $company): bool
    {
        return PosFeatureService::moduleAvailable($company, 'recipes');
    }

    // ── Template samples (Misal: prefix — importer skips by marker only) ──

    private function templateSamples(): array
    {
        $m = self::SAMPLE_MARKER . ' ';
        // PRODUCT samples leave Quantity Needed blank. INGREDIENT samples leave product/price blank.
        return [
            ['PRODUCT', $m . 'Large Pizza', 'PZ-001', 1200, 'Pizza', '', 0, 'NOS', 'No', 'No', '', '', '', '', '', '', 'Yes'],
            ['PRODUCT', $m . 'Chicken Burger', 'BG-001', 450, 'Burger', '', 0, 'NOS', 'No', 'No', '', '', '', '', '', '', 'Yes'],
            ['PRODUCT', $m . 'Hot Wings 10pc', 'WG-010', 650, 'Wings', '', 0, 'NOS', 'No', 'No', '', '', '', '', '', '', 'Yes'],
            ['PRODUCT', $m . 'Coke 500ml', 'DK-500', 80, 'Drinks', '', 0, 'NOS', 'No', 'No', '', '', '', '', '', '', 'Yes'],
            ['PRODUCT', $m . 'Chicken Biryani', 'BRY-001', 450, 'Rice', '', 0, 'NOS', 'No', 'No', '', '', '', '', '', '', 'Yes'],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', $m . 'Pizza Dough', 'ING-DGH', 'g', 0.15, 2000, '', 'Yes'],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', $m . 'Mozzarella', 'ING-MOZ', 'g', 1.20, 1000, '', 'Yes'],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', $m . 'Burger Bun', 'ING-BUN', 'pcs', 15, 50, '', 'Yes'],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', $m . 'Chicken Patty', 'ING-PAT', 'pcs', 60, 40, '', 'Yes'],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', $m . 'Chicken Wings', 'ING-WNG', 'kg', 650, 10, '', 'Yes'],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', $m . 'Wing Sauce', 'ING-SAU', 'g', 0.40, 500, '', 'Yes'],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', $m . 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', 'Yes'],
            ['RECIPE', $m . 'Large Pizza', 'PZ-001', '', '', '', '', '', '', '', $m . 'Pizza Dough', 'ING-DGH', 'g', '', '', 350, 'Yes'],
            ['RECIPE', $m . 'Large Pizza', 'PZ-001', '', '', '', '', '', '', '', $m . 'Mozzarella', 'ING-MOZ', 'g', '', '', 120, 'Yes'],
            ['RECIPE', $m . 'Chicken Burger', 'BG-001', '', '', '', '', '', '', '', $m . 'Burger Bun', 'ING-BUN', 'pcs', '', '', 1, 'Yes'],
            ['RECIPE', $m . 'Chicken Burger', 'BG-001', '', '', '', '', '', '', '', $m . 'Chicken Patty', 'ING-PAT', 'pcs', '', '', 1, 'Yes'],
            ['RECIPE', $m . 'Hot Wings 10pc', 'WG-010', '', '', '', '', '', '', '', $m . 'Chicken Wings', 'ING-WNG', 'kg', '', '', 0.45, 'Yes'],
            ['RECIPE', $m . 'Hot Wings 10pc', 'WG-010', '', '', '', '', '', '', '', $m . 'Wing Sauce', 'ING-SAU', 'g', '', '', 40, 'Yes'],
            ['RECIPE', $m . 'Chicken Biryani', 'BRY-001', '', '', '', '', '', '', '', $m . 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.25, 'Yes'],
        ];
    }

    private function writeMasterRow($sheet, int $rowNum, array $vals): void
    {
        $sheet->setCellValue('A' . $rowNum, $vals[0]);
        $sheet->setCellValue('B' . $rowNum, $vals[1]);
        $sheet->setCellValueExplicit('C' . $rowNum, (string) $vals[2], DataType::TYPE_STRING);
        $sheet->setCellValue('D' . $rowNum, $vals[3]);
        $sheet->setCellValue('E' . $rowNum, $vals[4]);
        $sheet->setCellValue('F' . $rowNum, $vals[5]);
        $sheet->setCellValue('G' . $rowNum, $vals[6]);
        $sheet->setCellValue('H' . $rowNum, $vals[7]);
        $sheet->setCellValue('I' . $rowNum, $vals[8]);
        $sheet->setCellValue('J' . $rowNum, $vals[9]);
        $sheet->setCellValue('K' . $rowNum, $vals[10]);
        $sheet->setCellValueExplicit('L' . $rowNum, (string) $vals[11], DataType::TYPE_STRING);
        $sheet->setCellValue('M' . $rowNum, $vals[12]);
        $sheet->setCellValue('N' . $rowNum, $vals[13]);
        $sheet->setCellValue('O' . $rowNum, $vals[14]);
        $sheet->setCellValue('P' . $rowNum, $vals[15]);
        $sheet->setCellValue('Q' . $rowNum, $vals[16]);
    }

    // ── Parse ────────────────────────────────────────────────────────────

    /**
     * @return array{rows:array,header:array,header_idx:int,fatal:?string}
     */
    public function readMasterRows(string $path): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if (!$sheet) {
            $spreadsheet->disconnectWorksheets();
            return ['rows' => [], 'header' => [], 'header_idx' => 0, 'fatal' => __('pos.inventory_master_missing_sheet')];
        }
        if ($sheet->getHighestDataRow() > self::MAX_DATA_ROWS + 15) {
            $spreadsheet->disconnectWorksheets();
            return ['rows' => [], 'header' => [], 'header_idx' => 0, 'fatal' => __('pos.inventory_master_too_many_rows', ['max' => self::MAX_DATA_ROWS])];
        }
        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        $headerIdx = $this->findHeaderRow($rows);
        if ($headerIdx === null) {
            return ['rows' => $rows, 'header' => [], 'header_idx' => 0, 'fatal' => __('pos.inventory_master_missing_row_type')];
        }
        $header = array_map(function ($h) {
            return strtolower(trim(preg_replace('/[\x{FEFF}]/u', '', (string) $h)));
        }, $rows[$headerIdx]);

        return ['rows' => $rows, 'header' => $header, 'header_idx' => $headerIdx, 'fatal' => null];
    }

    private function findHeaderRow(array $rows): ?int
    {
        $max = min(10, count($rows));
        for ($i = 0; $i < $max; $i++) {
            $header = array_map(function ($h) {
                return strtolower(trim(preg_replace('/[\x{FEFF}]/u', '', (string) $h)));
            }, $rows[$i]);
            if ($this->findColumn($header, ['row type', 'row_type', 'type']) !== false) {
                return $i;
            }
        }
        return null;
    }

    private function columnMap(array $header): array
    {
        return [
            'row_type' => $this->findColumn($header, ['row type', 'row_type', 'type']),
            'product_name' => $this->findColumn($header, ['product name', 'product', 'item name', 'item', 'name']),
            'product_code' => $this->findColumn($header, ['product code', 'product code (sku/barcode)', 'product code (sku / barcode)', 'sku', 'barcode', 'item code', 'code']),
            'price' => $this->findColumn($header, ['price', 'sale price', 'rate', 'unit price', 'price (rs)', 'price rs']),
            'category' => $this->findColumn($header, ['category', 'group']),
            'description' => $this->findColumn($header, ['description', 'details']),
            'tax_rate' => $this->findColumn($header, ['tax rate %', 'tax rate', 'tax_rate', 'tax', 'tax %']),
            'uom' => $this->findColumn($header, ['unit (uom)', 'product unit', 'uom']),
            'tax_exempt' => $this->findColumn($header, ['tax exempt', 'tax exempt (yes/no)', 'exempt (yes/no)', 'exempt', 'tax_exempt', 'is_tax_exempt']),
            'third_schedule' => $this->findColumn($header, ['third schedule', 'third schedule (yes/no)', 'third_schedule', 'is_third_schedule', 'third']),
            'ingredient_name' => $this->findColumn($header, ['ingredient name', 'ingredient']),
            'ingredient_code' => $this->findColumn($header, ['ingredient code', 'ing code', 'ing. code']),
            'ingredient_unit' => $this->findColumn($header, ['ingredient unit', 'ing unit', 'ing. unit', 'kitchen unit']),
            'cost' => $this->findColumn($header, ['cost per unit', 'cost per unit (optional)', 'cost', 'kharid']),
            'min_stock' => $this->findColumn($header, ['min stock', 'min stock level', 'minimum stock', 'min_stock_level']),
            'qty_needed' => $this->findColumn($header, ['quantity needed', 'qty needed', 'quantity', 'qty', 'miqdaar']),
            'active' => $this->findColumn($header, ['active', 'is_active', 'status']),
        ];
    }

    private function extractRow(array $data, array $map): array
    {
        $productName = $this->cell($data, $map['product_name']);
        $ingName = $this->cell($data, $map['ingredient_name']);
        return [
            'row_type' => $this->normalizeRowType($this->cell($data, $map['row_type'])),
            'product_name' => $productName,
            'product_code' => $this->cleanImportCode($this->raw($data, $map['product_code'])),
            'price_raw' => $this->raw($data, $map['price']),
            'category' => $this->cell($data, $map['category']),
            'description' => $this->cell($data, $map['description']),
            'tax_raw' => $this->raw($data, $map['tax_rate']),
            'uom_raw' => $this->cell($data, $map['uom']),
            'exempt_raw' => $this->cell($data, $map['tax_exempt']),
            'third_raw' => $this->cell($data, $map['third_schedule']),
            'ingredient_name' => $ingName,
            'ingredient_code' => $this->cleanImportCode($this->raw($data, $map['ingredient_code'])),
            'ingredient_unit' => strtolower($this->cell($data, $map['ingredient_unit'])),
            'cost_raw' => $this->raw($data, $map['cost']),
            'min_stock_raw' => $this->raw($data, $map['min_stock']),
            'qty_raw' => $this->raw($data, $map['qty_needed']),
            'active_raw' => $this->cell($data, $map['active']),
        ];
    }

    private function isSampleRow(array $parsed): bool
    {
        foreach (['product_name', 'ingredient_name'] as $field) {
            $v = (string) ($parsed[$field] ?? '');
            if ($v !== '' && stripos($v, self::SAMPLE_MARKER) === 0) {
                return true;
            }
        }
        return false;
    }

    private function normalizeRowType(?string $raw): ?string
    {
        $t = strtolower(trim((string) $raw));
        if ($t === '') {
            return null;
        }
        if (in_array($t, ['product', 'ingredient', 'recipe'], true)) {
            return $t;
        }
        return null;
    }

    // ── Prepare / apply INGREDIENT ───────────────────────────────────────

    private function prepareIngredient(int $rowNo, array $p, array &$catalog, int $companyId, array &$errors): ?array
    {
        $name = $p['ingredient_name'];
        $code = $p['ingredient_code'];
        $unit = $p['ingredient_unit'];
        $who = $name !== '' ? $name : (string) $code;

        if ($name === '') {
            $errors[] = $this->rowError($rowNo, 'INGREDIENT', $who, __('pos.inventory_master_ing_name_required'));
            return null;
        }

        $existing = $this->matchIngredient($catalog, $code, $name, $unit, $errors, $rowNo, $who, 'INGREDIENT');
        if ($existing === 'ambiguous') {
            return null;
        }

        if (!$existing && $unit === '') {
            $errors[] = $this->rowError($rowNo, 'INGREDIENT', $who, __('pos.inventory_master_ing_unit_required'));
            return null;
        }
        if ($unit !== '' && !in_array($unit, RecipeInventoryService::UNITS, true)) {
            $errors[] = $this->rowError($rowNo, 'INGREDIENT', $who, __('pos.inventory_master_ing_unit_invalid', [
                'unit' => $unit,
                'allowed' => implode(', ', RecipeInventoryService::UNITS),
            ]));
            return null;
        }

        if ($existing && $unit !== '' && strtolower((string) $existing->unit) !== $unit) {
            $blocked = $this->ingredientUnitChangeBlocked($existing);
            if ($blocked) {
                $errors[] = $this->rowError($rowNo, 'INGREDIENT', $who, $blocked);
                return null;
            }
        }

        if ($code !== null && Schema::hasColumn('ingredients', 'code')) {
            $clash = $this->ingredientCodeTaken($catalog, $code, $existing?->id);
            if ($clash) {
                $errors[] = $this->rowError($rowNo, 'INGREDIENT', $who, __('pos.inventory_master_ing_code_taken', ['code' => $code]));
                return null;
            }
        }

        $cost = $this->cleanImportNumber($p['cost_raw']);
        if ($p['cost_raw'] !== null && trim((string) $p['cost_raw']) !== '' && ($cost === null || $cost < 0)) {
            $errors[] = $this->rowError($rowNo, 'INGREDIENT', $who, __('pos.inventory_master_ing_cost_invalid'));
            return null;
        }
        $min = $this->cleanImportNumber($p['min_stock_raw']);
        if ($p['min_stock_raw'] !== null && trim((string) $p['min_stock_raw']) !== '' && ($min === null || $min < 0)) {
            $errors[] = $this->rowError($rowNo, 'INGREDIENT', $who, __('pos.inventory_master_ing_min_invalid'));
            return null;
        }
        $active = $this->parseYesNo($p['active_raw']);
        if ($p['active_raw'] !== '' && $active === null) {
            $errors[] = $this->rowError($rowNo, 'INGREDIENT', $who, __('pos.inventory_master_active_invalid'));
            return null;
        }

        $attrs = [
            'name' => $name,
            'unit' => $existing ? ($unit !== '' ? $unit : $existing->unit) : $unit,
        ];
        if (Schema::hasColumn('ingredients', 'code')) {
            $attrs['code'] = $code !== null ? $code : ($existing->code ?? null);
        }
        if (Schema::hasColumn('ingredients', 'base_unit')) {
            $attrs['base_unit'] = $attrs['unit'];
        }
        if (Schema::hasColumn('ingredients', 'conversion_factor') && !$existing) {
            $attrs['conversion_factor'] = 1;
        }
        $attrs['cost_per_unit'] = $cost !== null ? $cost : ($existing->cost_per_unit ?? 0);
        $attrs['min_stock_level'] = $min !== null ? $min : ($existing->min_stock_level ?? 0);
        $attrs['is_active'] = $active !== null ? $active : ($existing ? (bool) $existing->is_active : true);
        // Master Excel never writes stock. Creates start at 0; updates leave current_stock alone.
        if (!$existing) {
            $attrs['current_stock'] = 0;
            $attrs['company_id'] = $companyId;
        }

        return [
            'action' => $existing ? 'update' : 'create',
            'existing' => $existing,
            'attrs' => $attrs,
            'row' => $rowNo,
        ];
    }

    private function applyIngredient(array $op, array &$catalog, array &$counts): void
    {
        $attrs = $op['attrs'];
        $ing = $this->persistedIngredientForOp($op, $catalog);
        if ($ing) {
            // In-file duplicates prepare against an id=0 pending clone. Re-resolve
            // the real row and never write stock/company on that update.
            unset($attrs['current_stock'], $attrs['company_id']);
            $ing->update($attrs);
            $counts['ingredients_updated']++;
        } else {
            $ing = Ingredient::create($attrs);
            $counts['ingredients_added']++;
        }
        $this->indexIngredient($catalog, $ing);
    }

    private function ingredientUnitChangeBlocked(Ingredient $ing): ?string
    {
        $bom = ProductRecipe::where('ingredient_id', $ing->id)->exists();
        if ($bom) {
            return __('pos.inventory_master_unit_blocked_bom');
        }
        $companyStock = abs((float) $ing->current_stock) > 0.00001;
        $branchStock = Schema::hasTable('ingredient_stocks')
            && IngredientStock::where('ingredient_id', $ing->id)->where('quantity', '!=', 0)->exists();
        if ($companyStock || $branchStock) {
            return __('pos.inventory_master_unit_blocked_stock');
        }
        return null;
    }

    private function matchIngredient(array $catalog, ?string $code, string $name, string $unit, array &$errors, int $rowNo, string $who, string $rowType = 'INGREDIENT'): Ingredient|string|null
    {
        if ($code !== null && $code !== '') {
            $hits = $catalog['ingByCode'][strtolower($code)] ?? [];
            if (count($hits) > 1) {
                $errors[] = $this->rowError($rowNo, $rowType, $who, __('pos.inventory_master_ing_code_ambiguous', ['code' => $code]));
                return 'ambiguous';
            }
            if (count($hits) === 1) {
                return $hits[0];
            }
        }
        if ($name !== '' && $unit !== '') {
            $key = strtolower($name) . '|' . strtolower($unit);
            if (isset($catalog['ingByNameUnit'][$key])) {
                return $catalog['ingByNameUnit'][$key];
            }
        }
        return null;
    }

    private function ingredientCodeTaken(array $catalog, string $code, ?int $exceptId): bool
    {
        $hits = $catalog['ingByCode'][strtolower($code)] ?? [];
        foreach ($hits as $ing) {
            if ((int) $ing->id !== (int) $exceptId) {
                return true;
            }
        }
        return false;
    }

    // ── Prepare / apply PRODUCT ──────────────────────────────────────────

    private function prepareProduct(int $rowNo, array $p, array &$catalog, int $companyId, ?Company $company, array &$errors): ?array
    {
        $name = $p['product_name'];
        $code = $p['product_code'];
        $who = $name !== '' ? $name : (string) $code;

        if ($name === '') {
            $errors[] = $this->rowError($rowNo, 'PRODUCT', $who, __('pos.inventory_master_product_name_required'));
            return null;
        }
        $price = $this->cleanImportNumber($p['price_raw']);
        if ($price === null || $price < 0) {
            $errors[] = $this->rowError($rowNo, 'PRODUCT', $who, __('pos.inventory_master_product_price_invalid'));
            return null;
        }

        $uom = $p['uom_raw'] !== '' ? (PosUnitCatalog::resolve($p['uom_raw']) ?? '') : '';
        $tax = $this->cleanImportNumber($p['tax_raw']);
        $third = $this->parseYesNo($p['third_raw']);
        $exempt = $this->parseYesNo($p['exempt_raw']);
        if ($p['exempt_raw'] !== '' && $exempt === null) {
            $errors[] = $this->rowError($rowNo, 'PRODUCT', $who, __('pos.inventory_master_exempt_invalid'));
            return null;
        }
        if ($p['third_raw'] !== '' && $third === null) {
            $errors[] = $this->rowError($rowNo, 'PRODUCT', $who, __('pos.inventory_master_third_invalid'));
            return null;
        }
        if ($p['tax_raw'] !== null && trim((string) $p['tax_raw']) !== '' && $tax === null) {
            if (strcasecmp(trim((string) $p['tax_raw']), 'exempt') === 0) {
                $exempt = $exempt ?? true;
                $tax = 0.0;
            } else {
                $errors[] = $this->rowError($rowNo, 'PRODUCT', $who, __('pos.inventory_master_tax_invalid'));
                return null;
            }
        }
        if ($third === true) {
            $exempt = true;
        }
        $active = $this->parseYesNo($p['active_raw']);
        if ($p['active_raw'] !== '' && $active === null) {
            $errors[] = $this->rowError($rowNo, 'PRODUCT', $who, __('pos.inventory_master_active_invalid'));
            return null;
        }

        $existing = $this->matchProduct($catalog, $code, $name);
        $attrs = [
            'name' => $name,
            'price' => $price,
        ];
        if ($p['description'] !== '') {
            $attrs['description'] = $p['description'];
        } elseif ($existing) {
            $attrs['description'] = $existing->description;
        }
        if ($p['category'] !== '') {
            $attrs['category'] = $p['category'];
        } elseif ($existing) {
            $attrs['category'] = $existing->category;
        }
        if ($code !== null) {
            $attrs['sku'] = $code;
            if (!$existing || trim((string) $existing->barcode) === '' || strcasecmp((string) $existing->barcode, (string) $code) === 0) {
                $attrs['barcode'] = $code;
            }
        } elseif ($existing) {
            $attrs['sku'] = $existing->sku;
            $attrs['barcode'] = $existing->barcode;
        }
        $attrs['tax_rate'] = ($exempt === true) ? 0 : ($tax !== null ? $tax : ($existing->tax_rate ?? 0));
        $attrs['uom'] = $uom !== '' ? $uom : ($existing->uom ?? PosUnitCatalog::defaultFor($company));
        $attrs['is_tax_exempt'] = $exempt !== null ? $exempt : (bool) ($existing->is_tax_exempt ?? false);
        $attrs['is_active'] = $active !== null ? $active : ($existing ? (bool) $existing->is_active : true);
        if (Schema::hasColumn('pos_products', 'is_third_schedule')) {
            $attrs['is_third_schedule'] = $third !== null ? $third : (bool) ($existing->is_third_schedule ?? false);
        }
        if (!$existing) {
            $attrs['company_id'] = $companyId;
            $attrs['show_on_sale'] = true;
            // Never seed stock from Master Excel.
        }

        return [
            'action' => $existing ? 'update' : 'create',
            'existing' => $existing,
            'attrs' => $attrs,
            'row' => $rowNo,
        ];
    }

    private function applyProduct(array $op, array &$catalog, array &$counts): void
    {
        $attrs = $op['attrs'];
        $product = $this->persistedProductForOp($op, $catalog);
        if ($product) {
            // In-file duplicates prepare against an id=0 pending clone. Re-resolve
            // the real row; Master Excel never writes stock_quantity.
            unset($attrs['stock_quantity'], $attrs['company_id']);
            $product->update($attrs);
            $counts['products_updated']++;
        } else {
            $product = PosProduct::create($attrs);
            $counts['products_added']++;
        }
        $this->indexProduct($catalog, $product);
    }

    private function matchProduct(array $catalog, ?string $code, string $name): ?PosProduct
    {
        if ($code !== null && $code !== '') {
            $k = strtolower($code);
            if (isset($catalog['byBarcode'][$k])) {
                return $catalog['byBarcode'][$k];
            }
            if (isset($catalog['bySku'][$k])) {
                return $catalog['bySku'][$k];
            }
        }
        if ($name !== '' && isset($catalog['byName'][strtolower($name)])) {
            return $catalog['byName'][strtolower($name)];
        }
        return null;
    }

    /**
     * Real catalog row for an apply op. Pending prepare clones use id=0 and must
     * not be UPDATE'd; after the first create, later in-file duplicates resolve here.
     */
    private function persistedIngredientForOp(array $op, array $catalog): ?Ingredient
    {
        $existing = $op['existing'] ?? null;
        if ($existing instanceof Ingredient && (int) $existing->id > 0) {
            return $existing;
        }
        $attrs = $op['attrs'] ?? [];
        $code = isset($attrs['code']) ? trim((string) $attrs['code']) : '';
        $name = (string) ($attrs['name'] ?? '');
        $unit = strtolower((string) ($attrs['unit'] ?? ''));
        $errors = [];
        $match = $this->matchIngredient(
            $catalog,
            $code !== '' ? $code : null,
            $name,
            $unit,
            $errors,
            0,
            $name,
            'INGREDIENT'
        );
        if ($match instanceof Ingredient && (int) $match->id > 0) {
            return $match;
        }
        return null;
    }

    private function persistedProductForOp(array $op, array $catalog): ?PosProduct
    {
        $existing = $op['existing'] ?? null;
        if ($existing instanceof PosProduct && (int) $existing->id > 0) {
            return $existing;
        }
        $attrs = $op['attrs'] ?? [];
        $code = isset($attrs['sku']) ? trim((string) $attrs['sku']) : '';
        if ($code === '' && isset($attrs['barcode'])) {
            $code = trim((string) $attrs['barcode']);
        }
        $name = (string) ($attrs['name'] ?? '');
        $match = $this->matchProduct($catalog, $code !== '' ? $code : null, $name);
        if ($match instanceof PosProduct && (int) $match->id > 0) {
            return $match;
        }
        return null;
    }

    // ── Prepare / apply RECIPE ───────────────────────────────────────────

    private function prepareRecipe(int $rowNo, array $p, array &$catalog, int $companyId, array &$errors): ?array
    {
        $productName = $p['product_name'];
        $code = $p['product_code'];
        $ingName = $p['ingredient_name'];
        $ingCode = $p['ingredient_code'];
        $unit = $p['ingredient_unit'];
        $who = trim(($productName !== '' ? $productName : (string) $code) . ' / ' . ($ingName !== '' ? $ingName : (string) $ingCode), ' /');

        if ($productName === '' && $code === null) {
            $errors[] = $this->rowError($rowNo, 'RECIPE', $who, __('pos.inventory_master_recipe_product_required'));
            return null;
        }
        if ($ingName === '' && $ingCode === null) {
            $errors[] = $this->rowError($rowNo, 'RECIPE', $who, __('pos.inventory_master_recipe_ing_required'));
            return null;
        }
        $qty = $this->cleanImportNumber($p['qty_raw']);
        if ($qty === null || $qty <= 0) {
            $errors[] = $this->rowError($rowNo, 'RECIPE', $who, __('pos.inventory_master_recipe_qty_invalid'));
            return null;
        }

        $product = $this->matchProduct($catalog, $code, $productName);
        if (!$product) {
            $errors[] = $this->rowError($rowNo, 'RECIPE', $who, __('pos.inventory_master_recipe_product_missing'));
            return null;
        }

        $ingMatchErrors = [];
        $ingredient = $this->matchIngredient($catalog, $ingCode, $ingName, $unit, $ingMatchErrors, $rowNo, $who, 'RECIPE');
        if ($ingredient === 'ambiguous') {
            $errors = array_merge($errors, $ingMatchErrors);
            return null;
        }
        if (!$ingredient && $ingName !== '' && $unit === '') {
            $byName = $catalog['ingByName'][strtolower($ingName)] ?? [];
            if (count($byName) === 1) {
                $ingredient = $byName[0];
            } elseif (count($byName) > 1) {
                $errors[] = $this->rowError($rowNo, 'RECIPE', $who, __('pos.inventory_master_ing_name_ambiguous', ['name' => $ingName]));
                return null;
            }
        }

        $createIngredient = null;
        if (!$ingredient) {
            $createUnit = $unit !== '' ? $unit : 'pcs';
            if (!in_array($createUnit, RecipeInventoryService::UNITS, true)) {
                $errors[] = $this->rowError($rowNo, 'RECIPE', $who, __('pos.inventory_master_ing_unit_invalid', [
                    'unit' => $createUnit,
                    'allowed' => implode(', ', RecipeInventoryService::UNITS),
                ]));
                return null;
            }
            if ($ingName === '') {
                $errors[] = $this->rowError($rowNo, 'RECIPE', $who, __('pos.inventory_master_recipe_ing_required'));
                return null;
            }
            $createIngredient = [
                'company_id' => $companyId,
                'name' => $ingName,
                'unit' => $createUnit,
                'cost_per_unit' => 0,
                'current_stock' => 0,
                'min_stock_level' => 0,
                'is_active' => true,
            ];
            if (Schema::hasColumn('ingredients', 'code') && $ingCode !== null) {
                $createIngredient['code'] = $ingCode;
            }
            if (Schema::hasColumn('ingredients', 'base_unit')) {
                $createIngredient['base_unit'] = $createUnit;
            }
            if (Schema::hasColumn('ingredients', 'conversion_factor')) {
                $createIngredient['conversion_factor'] = 1;
            }
        } else {
            if ($unit !== '' && strtolower((string) $ingredient->unit) !== $unit) {
                $errors[] = $this->rowError($rowNo, 'RECIPE', $who, __('pos.inventory_master_recipe_unit_mismatch', [
                    'file' => $unit,
                    'existing' => $ingredient->unit,
                ]));
                return null;
            }
        }

        $active = $this->parseYesNo($p['active_raw']);
        if ($p['active_raw'] !== '' && $active === null) {
            $errors[] = $this->rowError($rowNo, 'RECIPE', $who, __('pos.inventory_master_active_invalid'));
            return null;
        }

        return [
            'product_name' => $productName,
            'product_code' => $code,
            'ingredient_name' => $ingName,
            'ingredient_code' => $ingCode,
            'ingredient_unit' => $unit,
            'create_ingredient' => $createIngredient,
            'qty' => $qty,
            'active' => $active,
            'row' => $rowNo,
            'who' => $who,
        ];
    }

    private function applyRecipe(array $op, array &$catalog, int $companyId, array &$counts, array &$errors): void
    {
        $product = $this->matchProduct($catalog, $op['product_code'], $op['product_name']);
        if (!$product || (int) $product->id <= 0) {
            $errors[] = $this->rowError($op['row'], 'RECIPE', $op['who'], __('pos.inventory_master_recipe_product_missing'));
            return;
        }

        $lookupErrors = [];
        $ingredient = $this->matchIngredient(
            $catalog,
            $op['ingredient_code'],
            $op['ingredient_name'],
            $op['ingredient_unit'],
            $lookupErrors,
            $op['row'],
            $op['who'],
            'RECIPE'
        );
        if ($ingredient === 'ambiguous') {
            $errors = array_merge($errors, $lookupErrors);
            return;
        }
        if (!$ingredient && $op['ingredient_name'] !== '' && $op['ingredient_unit'] === '') {
            $byName = $catalog['ingByName'][strtolower($op['ingredient_name'])] ?? [];
            if (count($byName) === 1) {
                $ingredient = $byName[0];
            }
        }
        if (!$ingredient && $op['create_ingredient']) {
            $ingredient = Ingredient::create($op['create_ingredient']);
            $this->indexIngredient($catalog, $ingredient);
        }
        if (!$ingredient || (int) $ingredient->id <= 0) {
            $errors[] = $this->rowError($op['row'], 'RECIPE', $op['who'], __('pos.inventory_master_recipe_ing_required'));
            return;
        }

        $key = $product->id . '|' . $ingredient->id;
        $existing = $catalog['recipeByKey'][$key] ?? null;
        $data = ['quantity_needed' => $op['qty']];
        if (Schema::hasColumn('product_recipes', 'is_active') && $op['active'] !== null) {
            $data['is_active'] = $op['active'];
        }
        if ($existing) {
            if (Schema::hasColumn('product_recipes', 'recipe_version')) {
                $data['recipe_version'] = ((int) ($existing->recipe_version ?? 1)) + 1;
            }
            $existing->update($data);
            $catalog['recipeByKey'][$key] = $existing;
            $counts['recipes_updated']++;
        } else {
            $create = $data + [
                'company_id' => $companyId,
                'product_id' => $product->id,
                'ingredient_id' => $ingredient->id,
            ];
            if (Schema::hasColumn('product_recipes', 'recipe_version')) {
                $create['recipe_version'] = 1;
            }
            $catalog['recipeByKey'][$key] = ProductRecipe::create($create);
            $counts['recipes_added']++;
        }
    }

    // ── Catalog indexes ──────────────────────────────────────────────────

    private function preloadCatalog(int $companyId): array
    {
        $catalog = [
            'byBarcode' => [],
            'bySku' => [],
            'byName' => [],
            'ingByCode' => [],
            'ingByNameUnit' => [],
            'ingByName' => [],
            'recipeByKey' => [],
            'pendingProduct' => true,
        ];
        foreach (PosProduct::where('company_id', $companyId)->get() as $p) {
            $this->indexProduct($catalog, $p);
        }
        foreach (Ingredient::where('company_id', $companyId)->get() as $ing) {
            $this->indexIngredient($catalog, $ing);
        }
        foreach (ProductRecipe::where('company_id', $companyId)->get() as $r) {
            $catalog['recipeByKey'][$r->product_id . '|' . $r->ingredient_id] = $r;
        }
        return $catalog;
    }

    private function indexProduct(array &$catalog, PosProduct $p): void
    {
        if (trim((string) $p->barcode) !== '') {
            $catalog['byBarcode'][strtolower(trim($p->barcode))] = $p;
        }
        if (trim((string) $p->sku) !== '') {
            $catalog['bySku'][strtolower(trim($p->sku))] = $p;
        }
        $catalog['byName'][strtolower(trim($p->name))] = $p;
    }

    private function indexIngredient(array &$catalog, Ingredient $ing): void
    {
        if (Schema::hasColumn('ingredients', 'code') && trim((string) $ing->code) !== '') {
            $k = strtolower(trim($ing->code));
            $catalog['ingByCode'][$k] = $catalog['ingByCode'][$k] ?? [];
            $replaced = false;
            foreach ($catalog['ingByCode'][$k] as $i => $existing) {
                if ((int) $existing->id === (int) $ing->id || (int) $existing->id <= 0) {
                    $catalog['ingByCode'][$k][$i] = $ing;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $catalog['ingByCode'][$k][] = $ing;
            }
        }
        $catalog['ingByNameUnit'][strtolower(trim($ing->name)) . '|' . strtolower(trim((string) $ing->unit))] = $ing;
        $n = strtolower(trim($ing->name));
        $catalog['ingByName'][$n] = $catalog['ingByName'][$n] ?? [];
        $replaced = false;
        foreach ($catalog['ingByName'][$n] as $i => $existing) {
            if ((int) $existing->id === (int) $ing->id
                || ((int) $existing->id <= 0 && strtolower((string) $existing->unit) === strtolower((string) $ing->unit))) {
                $catalog['ingByName'][$n][$i] = $ing;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $catalog['ingByName'][$n][] = $ing;
        }
    }

    private function rememberPendingIngredient(array &$catalog, array $op): void
    {
        $ing = $op['existing'] ? clone $op['existing'] : new Ingredient($op['attrs']);
        foreach ($op['attrs'] as $k => $v) {
            $ing->{$k} = $v;
        }
        if (!$ing->id) {
            $ing->id = 0;
        }
        $this->indexIngredient($catalog, $ing);
    }

    private function rememberPendingProduct(array &$catalog, array $op): void
    {
        $p = $op['existing'] ? clone $op['existing'] : new PosProduct($op['attrs']);
        foreach ($op['attrs'] as $k => $v) {
            $p->{$k} = $v;
        }
        if (!$p->id) {
            $p->id = 0;
        }
        $this->indexProduct($catalog, $p);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function result(bool $ok, string $message, array $errors, array $counts = [], ?string $fatal = null): array
    {
        return [
            'ok' => $ok,
            'fatal' => $fatal,
            'message' => $message,
            'errors' => $errors,
            'counts' => $counts + [
                'ingredients_added' => 0,
                'ingredients_updated' => 0,
                'products_added' => 0,
                'products_updated' => 0,
                'recipes_added' => 0,
                'recipes_updated' => 0,
                'samples_skipped' => 0,
                'rows_skipped' => 0,
                'gated_skipped' => 0,
                'plan_skipped' => 0,
            ],
        ];
    }

    private function flashMessage(array $counts, array $errors): string
    {
        $parts = [];
        if ($counts['products_added'] > 0) {
            $parts[] = __('pos.inventory_master_products_added', ['count' => $counts['products_added']]);
        }
        if ($counts['products_updated'] > 0) {
            $parts[] = __('pos.inventory_master_products_updated', ['count' => $counts['products_updated']]);
        }
        if ($counts['ingredients_added'] > 0) {
            $parts[] = __('pos.inventory_master_ingredients_added', ['count' => $counts['ingredients_added']]);
        }
        if ($counts['ingredients_updated'] > 0) {
            $parts[] = __('pos.inventory_master_ingredients_updated', ['count' => $counts['ingredients_updated']]);
        }
        if ($counts['recipes_added'] > 0) {
            $parts[] = __('pos.inventory_master_recipes_added', ['count' => $counts['recipes_added']]);
        }
        if ($counts['recipes_updated'] > 0) {
            $parts[] = __('pos.inventory_master_recipes_updated', ['count' => $counts['recipes_updated']]);
        }
        if ($counts['samples_skipped'] > 0) {
            $parts[] = __('pos.inventory_master_samples_skipped', ['count' => $counts['samples_skipped']]);
        }
        if ($counts['rows_skipped'] > 0) {
            $parts[] = __('pos.inventory_master_rows_skipped', ['count' => $counts['rows_skipped']]);
        }
        if ($counts['gated_skipped'] > 0) {
            $parts[] = __('pos.inventory_master_gated_skipped', ['count' => $counts['gated_skipped']]);
        }
        if ($counts['plan_skipped'] > 0) {
            $parts[] = __('pos.import_plan_limit_skipped', ['count' => $counts['plan_skipped']]);
        }
        $msg = $parts ? implode(', ', $parts) . '.' : __('pos.inventory_master_no_rows');
        if ($errors) {
            $shown = array_slice($errors, 0, 8);
            $msg .= ' ' . __('pos.inventory_master_issues', ['issues' => implode('; ', $shown)]);
            if (count($errors) > 8) {
                $msg .= __('pos.import_more_suffix', ['count' => count($errors) - 8]);
            }
        }
        return $msg;
    }

    private function rowError(int $row, string $type, string $who, string $reason): string
    {
        $who = trim($who);
        return __('pos.inventory_master_row_error', [
            'row' => $row,
            'type' => $type,
            'who' => $who !== '' ? $who : '—',
            'reason' => $reason,
        ]);
    }

    private function findColumn(array $header, array $names): int|false
    {
        foreach ($names as $name) {
            $idx = array_search($name, $header, true);
            if ($idx !== false) {
                return $idx;
            }
        }
        return false;
    }

    private function cell(array $data, int|false $idx): string
    {
        if ($idx === false) {
            return '';
        }
        return trim((string) ($data[$idx] ?? ''));
    }

    private function raw(array $data, int|false $idx)
    {
        if ($idx === false) {
            return null;
        }
        return $data[$idx] ?? null;
    }

    private function rowEmpty(array $data): bool
    {
        foreach ($data as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    private function cleanImportNumber($raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        $s = str_ireplace(['rs.', 'rs', 'pkr', '%'], '', $s);
        $s = str_replace([',', ' '], '', $s);
        if (!is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }

    private function cleanImportCode($raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return sprintf('%.0f', (float) $raw);
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $s)) {
            return sprintf('%.0f', (float) $s);
        }
        if (preg_match('/^\d+\.0+$/', $s)) {
            return preg_replace('/\.0+$/', '', $s);
        }
        return $s;
    }

    private function parseYesNo(string $raw): ?bool
    {
        $v = strtolower(trim($raw));
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['yes', 'y', '1', 'true', 'haan', 'han', 'active'], true)) {
            return true;
        }
        if (in_array($v, ['no', 'n', '0', 'false', 'nahi', 'inactive'], true)) {
            return false;
        }
        return null;
    }
}
