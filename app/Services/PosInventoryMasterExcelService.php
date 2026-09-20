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
use Illuminate\Support\Str;
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
    public const WORKBOOK_FILENAME = 'TaxNest_Menu_Import.xlsx';
    private const PREVIEW_TTL = 1800;

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
        $spreadsheet = $this->buildWorkbook();
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, self::WORKBOOK_FILENAME, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function buildWorkbook(): Spreadsheet
    {
        $ss = new Spreadsheet();
        $start = $ss->getActiveSheet();
        $start->setTitle('Start Here');
        $start->fromArray([
            ['TaxNest menu and inventory import'],
            ['Import order: Ingredients → Products → Recipes. Preview is zero-write; Confirm is required.'],
            ['Create-only skips exact existing rows. Update existing changes only fields shown in the preview.'],
            ['Opening Stock is read for warning only and is never posted to stock or ledgers.'],
            ['Every row beginning with "Misal:" is sample-only and is never imported, even when the unchanged template is uploaded.'],
        ], null, 'A1');
        $start->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $start->getStyle('A2:A5')->getAlignment()->setWrapText(true);
        $start->getColumnDimension('A')->setWidth(105);
        $definitions = [
            'Products' => ['Product Name', 'Category', 'Product Type', 'Sale Price', 'Cost Price', 'SKU', 'Barcode', 'Unit', 'Tax Rate', 'Active', 'Track Stock', 'Opening Stock', 'Low Stock Alert', 'Description'],
            'Ingredients' => ['Ingredient Name', 'Category', 'Purchase Unit', 'Usage Unit', 'Conversion Factor', 'Cost per Purchase Unit', 'Opening Stock', 'Low Stock Alert', 'Supplier', 'Active'],
            'Recipes' => ['Product Name', 'Ingredient Name', 'Quantity Used', 'Usage Unit', 'Waste %', 'Notes'],
            'Lists' => ['List', 'Allowed Value', 'Guidance'],
        ];
        foreach ($definitions as $title => $headers) {
            $sheet = $ss->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray([$headers], null, 'A1');
            $last = chr(ord('A') + count($headers) - 1);
            $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('7C3AED');
            $sheet->freezePane('A2');
            $sheet->getRowDimension(1)->setRowHeight(26);
            for ($column = 1; $column <= count($headers); $column++) {
                $sheet->getColumnDimensionByColumn($column)->setWidth(20);
            }
            $sheet->getStyle("A1:{$last}2000")->getAlignment()->setWrapText(true);
        }
        $lists = $ss->getSheetByName('Lists');
        $lists->fromArray([
            ['Unit', 'pcs', 'Use the same Usage Unit in Recipes.'],
            ['Unit', 'cup', ''],
            ['Unit', 'kg', ''],
            ['Unit', 'g', ''],
            ['Unit', 'ltr', ''],
            ['Unit', 'ml', ''],
            ['Unit', 'dozen', ''],
            ['Product Type', 'Recipe', 'Active recipe lines make this a Recipe item.'],
            ['Product Type', 'Resale', 'Use for products without active recipe lines.'],
            ['Yes/No', 'Yes', 'Enter Yes or No.'],
            ['Yes/No', 'No', 'Enter Yes or No.'],
            ['Tax', '0-100', 'Tax Rate is a percentage; confirm fiscal setup separately.'],
        ], null, 'A2');
        $lists->getProtection()->setSheet(true);
        $lists->getProtection()->setPassword(Str::random(24));
        $lists->getStyle('A2:C13')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('ECFDF5');
        $this->addWorkbookValidation($ss->getSheetByName('Products'), 'J2:J2000', '"Yes,No"');
        $this->addWorkbookValidation($ss->getSheetByName('Products'), 'K2:K2000', '"Yes,No"');
        $this->addWorkbookValidation($ss->getSheetByName('Ingredients'), 'J2:J2000', '"Yes,No"');
        $this->addWorkbookValidation($ss->getSheetByName('Recipes'), 'D2:D2000', '"pcs,cup,kg,g,ltr,ml,dozen"');
        $samples = [
            ['Misal: Plain Tea', 'Beverages', 'Recipe', 100, '', 'TEA-001', '', 'cup', 0, 'Yes', 'No', '', '', 'Sample only'],
        ];
        $ss->getSheetByName('Products')->fromArray($samples, null, 'A2');
        $ss->getSheetByName('Products')->getStyle('A2:N2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCFCE7');
        $ingredientSamples = [
            ['Misal: Tea Leaves', '', 'kg', 'g', 1000, 1000, '', 0, '', 'Yes'],
            ['Misal: Milk', '', 'ltr', 'ml', 1000, 180, '', 0, '', 'Yes'],
            ['Misal: Sugar', '', 'kg', 'g', 1000, 200, '', 0, '', 'Yes'],
            ['Misal: Water', '', 'ltr', 'ml', 1000, 20, '', 0, '', 'Yes'],
        ];
        $ss->getSheetByName('Ingredients')->fromArray($ingredientSamples, null, 'A2');
        $ss->getSheetByName('Ingredients')->getStyle('A2:J5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
        $ss->getSheetByName('Recipes')->fromArray([
            ['Misal: Plain Tea', 'Misal: Tea Leaves', 2, 'g', 0, 'Sample only'],
            ['Misal: Plain Tea', 'Misal: Milk', 100, 'ml', 0, 'Sample only'],
            ['Misal: Plain Tea', 'Misal: Sugar', 8, 'g', 0, 'Sample only'],
            ['Misal: Plain Tea', 'Misal: Water', 100, 'ml', 0, 'Sample only'],
        ], null, 'A2');
        $ss->getSheetByName('Recipes')->getStyle('A2:F5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
        return $ss;
    }

    public function preview(string $path, int $companyId, string $mode='create_only', ?int $userId=null): array
    {
        if ($userId === null) {
            return ['ok'=>false,'errors'=>[['sheet'=>'','row'=>0,'field'=>'user','message'=>'An authenticated user is required.']]];
        }
        if (!in_array($mode,['create_only','update_existing'],true)) {
            return ['ok'=>false,'errors'=>[['sheet'=>'','row'=>0,'field'=>'mode','message'=>'Mode must be create_only or update_existing.']]];
        }
        try { $normalized=$this->readMenuWorkbook($path); } catch (\Throwable $e) {
            Log::warning('Menu workbook preview rejected: '.$e->getMessage());
            return ['ok'=>false,'errors'=>[['sheet'=>'','row'=>0,'field'=>'file','message'=>'Workbook could not be read or failed security validation.']]];
        }
        $plan = $this->planOperations($normalized, $companyId, $mode);
        $recipeCosts = $this->calculatePreviewRecipeCosts($normalized);
        $checksum = hash_file('sha256', $path);
        $payload = ['company_id'=>$companyId, 'user_id'=>$userId, 'mode'=>$mode, 'operations'=>$plan['operations'],
            'warnings'=>$plan['warnings'], 'errors'=>$plan['errors'], 'checksum'=>$checksum,
            'fingerprint'=>$this->catalogFingerprint($companyId), 'created_at'=>time()];
        $dir=storage_path('app/pos-import-previews');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        $token=Str::random(64);
        $target=$dir.'/'.$token.'.json';
        file_put_contents($target,json_encode($payload,JSON_THROW_ON_ERROR),LOCK_EX);
        chmod($target,0600);
        $summary = [
            'rows' => count($plan['operations']),
            'valid' => count(array_filter($plan['operations'], fn ($operation) => ($operation['action'] ?? '') !== 'skip')),
            'issues' => count($plan['errors']) + count($plan['warnings']),
            'updates' => count(array_filter($plan['operations'], fn ($operation) => ($operation['action'] ?? '') === 'update')),
            'creates' => count(array_filter($plan['operations'], fn ($operation) => ($operation['action'] ?? '') === 'create')),
        ];
        return ['ok'=>!$plan['errors'],'token'=>$token,'mode'=>$mode,'warnings'=>$plan['warnings'],'errors'=>$plan['errors'],
            'categories'=>$plan['categories'],'recipe_costs'=>$recipeCosts]+$summary;
    }

    public function confirm(string $token,int $companyId,bool $confirmed=false, ?int $userId=null): array
    {
        if (!$confirmed) return ['ok'=>false,'errors'=>['Explicit confirmation is required.']];
        if ($userId === null) return ['ok'=>false,'errors'=>['An authenticated user is required.']];
        if (!preg_match('/^[A-Za-z0-9]+$/',$token)) return ['ok'=>false,'errors'=>['Preview token is invalid.']];
        $file=storage_path('app/pos-import-previews/'.$token.'.json');
        if (!is_file($file)||filemtime($file)<time()-self::PREVIEW_TTL) return ['ok'=>false,'errors'=>['Preview token is missing or expired.']];
        $payload=json_decode((string)file_get_contents($file),true);
        if (!is_array($payload)||(int)($payload['company_id']??0)!==$companyId) return ['ok'=>false,'errors'=>['Preview does not belong to this company.']];
        if ((int)($payload['user_id'] ?? 0) !== $userId) return ['ok'=>false,'errors'=>['Preview does not belong to this user.']];
        if (!empty($payload['errors'])) return ['ok'=>false,'errors'=>$payload['errors']];
        $processing = $file.'.'.Str::random(16).'.processing';
        if (!@rename($file, $processing)) {
            return ['ok'=>false,'errors'=>['Preview is already being confirmed or has been consumed.']];
        }
        $claimedPayload = json_decode((string) file_get_contents($processing), true);
        if (!is_array($claimedPayload)
            || !hash_equals(hash('sha256', json_encode($payload)), hash('sha256', json_encode($claimedPayload)))) {
            @rename($processing, $file);
            return ['ok'=>false,'errors'=>['Preview changed while it was being confirmed.']];
        }
        DB::beginTransaction();
        try {
            Company::whereKey($companyId)->lockForUpdate()->first();
            if (!hash_equals((string)$payload['fingerprint'], $this->catalogFingerprint($companyId))) {
                throw new \RuntimeException('Catalog changed after preview; create a new preview.');
            }
            $createCount = count(array_filter(
                $payload['operations'],
                fn ($operation) => ($operation['entity'] ?? '') === 'product'
                    && ($operation['action'] ?? '') === 'create'
            ));
            $remaining = PlanLimitService::remainingProductAllowance($companyId, 'pos');
            if ($remaining !== null && $createCount > $remaining) {
                throw new \RuntimeException(__('pos.product_limit_reached_error'));
            }
            $result=$this->applyOperations($payload['operations'], $companyId, $payload['mode']);
            if (Schema::hasTable('audit_logs')) {
                AuditLogService::log(
                    'menu_inventory_import_confirmed',
                    'menu_inventory_workbook',
                    null,
                    null,
                    ['mode'=>$payload['mode'],'checksum'=>$payload['checksum'],'counts'=>$result['counts'] ?? []],
                    $companyId,
                    $userId
                );
            }
            DB::commit();
            @unlink($processing);
            return $result+['checksum'=>$payload['checksum'],'mode'=>$payload['mode']];
        } catch (\Throwable $e) {
            DB::rollBack();
            if (is_file($processing) && !is_file($file)) {
                @rename($processing, $file);
            }
            Log::warning('Menu import confirm rolled back: '.$e->getMessage());
            return ['ok'=>false,'errors'=>[$e->getMessage()]];
        }
    }

    public function errorReport(string $token, int $companyId, ?int $userId = null)
    {
        if (!preg_match('/^[A-Za-z0-9]+$/', $token)) abort(404);
        $file = storage_path('app/pos-import-previews/'.$token.'.json');
        if (!is_file($file) || filemtime($file) < time() - self::PREVIEW_TTL) abort(404);
        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || (int) ($payload['company_id'] ?? 0) !== $companyId) abort(404);
        if ($userId === null || (int) ($payload['user_id'] ?? 0) !== $userId) {
            abort(404);
        }
        $lines = [];
        foreach (array_merge($payload['errors'] ?? [], $payload['warnings'] ?? []) as $error) {
            $lines[] = implode(',', [
                $this->csvValue($error['sheet'] ?? ''),
                $this->csvValue($error['row'] ?? ''),
                $this->csvValue($error['field'] ?? ''),
                $this->csvValue($error['message'] ?? ''),
            ]);
        }
        return response("sheet,row,field,message\n".implode("\n", array_slice($lines, 0, 1000)), 200, [
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="TaxNest_Menu_Import_errors.csv"',
        ]);
    }

    public function exportWorkbook(int $companyId)
    {
        $ss = $this->buildWorkbook();
        $products = $ss->getSheetByName('Products');
        foreach (PosProduct::where('company_id', $companyId)->orderBy('id')->get() as $row) {
            $values = [$row->name, $row->category, $this->productHasRecipe($row->id, $companyId) ? 'Recipe' : 'Resale',
                $row->price, $row->cost_price, $row->sku, $row->barcode, $row->uom, $row->tax_rate,
                $row->is_active ? 'Yes' : 'No', '', '', $row->low_stock_threshold, $row->description];
            $this->safeWriteRow($products, $products->getHighestRow() + 1, $values);
        }
        $ingredients = $ss->getSheetByName('Ingredients');
        foreach (Ingredient::where('company_id', $companyId)->orderBy('id')->get() as $row) {
            $this->safeWriteRow($ingredients, $ingredients->getHighestRow() + 1,
                [$row->name, $row->category ?? '', $row->base_unit ?: $row->unit, $row->unit,
                    $row->conversion_factor ?: 1, (float) $row->cost_per_unit * (float) ($row->conversion_factor ?: 1),
                    '', $row->min_stock_level, $row->supplier ?? '', $row->is_active ? 'Yes' : 'No']);
        }
        $recipes = $ss->getSheetByName('Recipes');
        foreach (ProductRecipe::where('company_id', $companyId)->with(['product', 'ingredient'])->orderBy('id')->get() as $row) {
            $this->safeWriteRow($recipes, $recipes->getHighestRow() + 1,
                [$row->product?->name, $row->ingredient?->name, $row->quantity_needed,
                    $row->ingredient?->unit, $row->waste_percent ?? 0, $row->notes ?? '']);
        }
        $writer = new Xlsx($ss);
        return response()->streamDownload(fn () => $writer->save('php://output'),
            self::WORKBOOK_FILENAME, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
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
        $seenRows = [
            'product' => [],
            'ingredient' => [],
            'recipe' => [],
        ];
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

            $duplicateOf = $this->duplicateRowOf($parsedRow, $rowNo, $seenRows);
            if ($duplicateOf !== null) {
                $skipped++;
                $errors[] = $this->rowError(
                    $rowNo,
                    strtoupper((string) ($parsedRow['row_type'] ?? 'UNKNOWN')),
                    $parsedRow['product_name'] ?: ($parsedRow['ingredient_name'] ?: ($parsedRow['product_code'] ?: $parsedRow['ingredient_code'])),
                    __('Duplicate of row :row. The first row was kept; combine the values into one row and upload again.', ['row' => $duplicateOf])
                );
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

    /**
     * Reject duplicate logical rows inside one workbook instead of silently
     * applying the last price/cost/recipe quantity. A later clean re-upload is
     * still idempotent; this guard only concerns ambiguity within one file.
     *
     * @param array<string,array<string,int>> $seen
     */
    private function duplicateRowOf(array $row, int $rowNo, array &$seen): ?int
    {
        $type = $row['row_type'] ?? null;
        if (!isset($seen[$type])) {
            return null;
        }

        $keys = match ($type) {
            'product' => array_filter([
                $this->identityKey('code', $row['product_code']),
                $this->identityKey('name', $row['product_name']),
            ]),
            'ingredient' => array_filter([
                $this->identityKey('code', $row['ingredient_code']),
                $this->identityKey('name-unit', $row['ingredient_name'], $row['ingredient_unit']),
            ]),
            'recipe' => array_filter([
                $this->identityKey(
                    'recipe-code',
                    $row['product_code'] ?: $row['product_name'],
                    $row['ingredient_code'] ?: $row['ingredient_name'],
                    $row['ingredient_unit']
                ),
            ]),
            default => [],
        };

        foreach ($keys as $key) {
            if (isset($seen[$type][$key])) {
                return $seen[$type][$key];
            }
        }
        foreach ($keys as $key) {
            $seen[$type][$key] = $rowNo;
        }

        return null;
    }

    private function identityKey(string $prefix, mixed ...$parts): ?string
    {
        $normalized = array_map(
            fn ($part) => strtolower(preg_replace('/\s+/u', ' ', trim((string) $part))),
            $parts
        );
        if (implode('', $normalized) === '') {
            return null;
        }

        return $prefix . ':' . implode('|', $normalized);
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

    private function readMenuWorkbook(string $path): array
    {
        $this->preflightXlsx($path);
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false);
        $ss = null;
        try {
            $ss = $reader->load($path);
            $expected = ['Start Here', 'Products', 'Ingredients', 'Recipes', 'Lists'];
            $names = array_map(fn ($s) => (string) $s->getTitle(), $ss->getAllSheets());
            if ($names !== $expected) {
                throw new \RuntimeException('Workbook must contain exactly the five required sheets.');
            }
            foreach ($ss->getAllSheets() as $sheet) {
                if ($sheet->getSheetState() !== \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VISIBLE) {
                    throw new \RuntimeException('Hidden sheets are not permitted.');
                }
                if ($sheet->getHighestDataRow() > self::MAX_DATA_ROWS + 1
                    || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 20) {
                    throw new \RuntimeException('Workbook row or column limit exceeded.');
                }
                foreach ($sheet->getCoordinates() as $coordinate) {
                    $cell = $sheet->getCell($coordinate);
                    if ($cell->isFormula() || preg_match('/^\s*=/', (string) $cell->getValue())) {
                        throw new \RuntimeException('Formula cells are not permitted.');
                    }
                }
            }
            $requiredHeaders = [
                'Products'=>['Product Name','Category','Product Type','Sale Price','Cost Price','SKU','Barcode','Unit','Tax Rate','Active','Track Stock','Opening Stock','Low Stock Alert','Description'],
                'Ingredients'=>['Ingredient Name','Category','Purchase Unit','Usage Unit','Conversion Factor','Cost per Purchase Unit','Opening Stock','Low Stock Alert','Supplier','Active'],
                'Recipes'=>['Product Name','Ingredient Name','Quantity Used','Usage Unit','Waste %','Notes'],
                'Lists'=>['List','Allowed Value','Guidance'],
            ];
            foreach ($requiredHeaders as $name => $headers) {
                $actual = array_map(fn ($value) => trim((string) $value), array_slice($ss->getSheetByName($name)->toArray(null, true, false, false)[0] ?? [], 0, count($headers)));
                if ($actual !== $headers) throw new \RuntimeException("Unexpected {$name} headers.");
            }
            $rows = [];
            $sampleRows = [];
            $warnings = [];
            $products = $ss->getSheetByName('Products')->toArray(null, true, false, false);
            foreach (array_slice($products, 1) as $index => $values) {
                if ($this->rowEmpty($values)) continue;
                if (stripos(trim((string) ($values[0] ?? '')), self::SAMPLE_MARKER) === 0) {
                    $sampleRows[] = ['sheet'=>'Products', 'row'=>$index + 2, 'type'=>'product', 'values'=>$values];
                    continue;
                }
                $rows[] = ['sheet'=>'Products', 'row'=>$index + 2, 'type'=>'product', 'values'=>$values];
            }
            $ingredients = $ss->getSheetByName('Ingredients')->toArray(null, true, false, false);
            foreach (array_slice($ingredients, 1) as $index => $values) {
                if ($this->rowEmpty($values)) continue;
                if (stripos(trim((string) ($values[0] ?? '')), self::SAMPLE_MARKER) === 0) {
                    $sampleRows[] = ['sheet'=>'Ingredients', 'row'=>$index + 2, 'type'=>'ingredient', 'values'=>$values];
                    continue;
                }
                if (trim((string) ($values[6] ?? '')) !== '') {
                    $warnings[] = ['sheet'=>'Ingredients','row'=>$index + 2,'field'=>'Opening Stock','message'=>'Opening Stock is informational and will be skipped.'];
                }
                $rows[] = ['sheet'=>'Ingredients', 'row'=>$index + 2, 'type'=>'ingredient', 'values'=>$values];
            }
            $recipes = $ss->getSheetByName('Recipes')->toArray(null, true, false, false);
            foreach (array_slice($recipes, 1) as $index => $values) {
                if ($this->rowEmpty($values)) continue;
                if (stripos(trim((string) ($values[0] ?? '')), self::SAMPLE_MARKER) === 0
                    || stripos(trim((string) ($values[1] ?? '')), self::SAMPLE_MARKER) === 0) {
                    $sampleRows[] = ['sheet'=>'Recipes', 'row'=>$index + 2, 'type'=>'recipe', 'values'=>$values];
                    continue;
                }
                $rows[] = ['sheet'=>'Recipes', 'row'=>$index + 2, 'type'=>'recipe', 'values'=>$values];
            }
            return ['rows'=>$rows, 'sample_rows'=>$sampleRows, 'warnings'=>$warnings];
        } finally {
            if ($ss) $ss->disconnectWorksheets();
        }
    }

    private function writeLegacyWorkbook(array $rows,string $path): void
    {
        $ss=new Spreadsheet(); $sheet=$ss->getActiveSheet(); $sheet->setTitle(self::SHEET_NAME);
        $sheet->fromArray([self::HEADERS],null,'A1');
        foreach ($rows as $n=>$row) $sheet->fromArray([$row],null,'A'.($n+2));
        (new Xlsx($ss))->save($path); $ss->disconnectWorksheets();
    }

    private function preflightXlsx(string $path): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Only XLSX files are accepted.');
        }
        $handle = fopen($path, 'rb');
        $signature = $handle ? fread($handle, 4) : '';
        if ($handle) fclose($handle);
        if ($signature !== "PK\x03\x04") throw new \RuntimeException('Invalid XLSX signature.');
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('Invalid XLSX archive.');
        if ($zip->numFiles > 300) { $zip->close(); throw new \RuntimeException('XLSX contains too many archive entries.'); }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\')
                || preg_match('~(^|/)\.\.?(/|$)~', $name)
                || preg_match('~(vbaProject|externalLinks|embeddings|oleObject|activeX|customUI|connections)~i', $name)) {
                $zip->close();
                throw new \RuntimeException('Unsafe or prohibited XLSX archive member.');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > 25 * 1024 * 1024) { $zip->close(); throw new \RuntimeException('XLSX is too large when expanded.'); }
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('~\.rels$~i', $name)) {
                $contents = (string) $zip->getFromIndex($i);
                if (preg_match('~TargetMode\s*=\s*["\']External~i', $contents)
                    || preg_match('~Target\s*=\s*["\'](?:https?|file):~i', $contents)) {
                    $zip->close();
                    throw new \RuntimeException('External XLSX relationships are not permitted.');
                }
            }
        }
        $zip->close();
    }

    private function addWorkbookValidation($sheet, string $range, string $formula): void
    {
        $validation = $sheet->getCell(strtok($range, ':'))->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)->setShowErrorMessage(true)->setShowDropDown(true)
            ->setFormula1($formula)->setSqref($range);
    }

    private function safeWriteRow($sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $column => $value) {
            $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1).$row;
            if (is_string($value) && preg_match('/^[=+\-@]/', $value)) {
                $sheet->setCellValueExplicit($coordinate, "'".$value, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($coordinate, $value);
            }
        }
    }

    private function planOperations(array $normalized, int $companyId, string $mode): array
    {
        $operations = [];
        $warnings = $normalized['warnings'];
        $errors = [];
        $categories = [];
        $products = PosProduct::where('company_id', $companyId)->get();
        $ingredients = Ingredient::where('company_id', $companyId)->get();
        $plannedProducts = [];
        $plannedIngredients = [];
        $seenProductIdentities = [];
        $seenIngredientIdentities = [];
        foreach ($normalized['rows'] as $item) {
            $v = $item['values'];
            if ($item['type'] === 'product') {
                [$match, $ambiguous] = $this->matchProductRow($products, $v);
                if ($ambiguous) {
                    $errors[] = $this->cellError($item, 'SKU/Barcode/Product Name', 'Ambiguous tenant-scoped product identity.');
                    continue;
                }
                $activeValue = $this->parseYesNo((string)($v[9]??''));
                $trackStockValue = $this->parseYesNo((string)($v[10]??''));
                $fields = [
                    'name'=>trim((string)($v[0]??'')), 'category'=>trim((string)($v[1]??'')),
                    'price'=>$this->cleanImportNumber($v[3]??null), 'cost_price'=>$this->cleanImportNumber($v[4]??null),
                    'sku'=>$this->cleanImportCode($v[5]??null), 'barcode'=>$this->cleanImportCode($v[6]??null),
                    'uom'=>trim((string)($v[7]??'')), 'tax_rate'=>$this->cleanImportNumber($v[8]??null) ?? 0,
                    'is_active'=>$activeValue ?? true,
                    'low_stock_threshold'=>$this->cleanImportNumber($v[12]??null), 'description'=>trim((string)($v[13]??'')),
                ];
                if ($fields['name']==='' || $fields['price']===null) {
                    $errors[]=$this->cellError($item,'Product Name/Sale Price','Product Name and Sale Price are required.'); continue;
                }
                if ($fields['price'] <= 0) {
                    $errors[]=$this->cellError($item,'Sale Price','Sale Price must be greater than zero.'); continue;
                }
                if ($fields['tax_rate'] !== null && ($fields['tax_rate'] < 0 || $fields['tax_rate'] > 100)) {
                    $errors[]=$this->cellError($item,'Tax Rate','Tax Rate must be between 0 and 100.'); continue;
                }
                if ($activeValue === null && trim((string)($v[9]??'')) !== '') {
                    $errors[]=$this->cellError($item,'Active','Active must be Yes or No.'); continue;
                }
                if ($trackStockValue === null && trim((string)($v[10]??'')) !== '') {
                    $errors[]=$this->cellError($item,'Track Stock','Track Stock must be Yes or No.'); continue;
                }
                if (!in_array(strtolower(trim((string)($v[2]??''))), ['recipe','resale'], true)) {
                    $errors[]=$this->cellError($item,'Product Type','Product Type must be Recipe or Resale.'); continue;
                }
                $identityKeys = array_values(array_filter([
                    $fields['sku'] ? 'code:'.strtolower($fields['sku']) : null,
                    $fields['barcode'] ? 'code:'.strtolower($fields['barcode']) : null,
                    'name:'.$this->normalizedName($fields['name']),
                ]));
                $duplicateKey = collect($identityKeys)->first(fn ($key) => isset($seenProductIdentities[$key]));
                if ($duplicateKey !== null) {
                    $errors[]=$this->cellError($item,'SKU/Barcode/Product Name','Duplicate product identity in this workbook.'); continue;
                }
                foreach ($identityKeys as $identityKey) $seenProductIdentities[$identityKey] = $item['row'];
                if (trim((string)($v[11]??''))!=='') $warnings[]=$this->cellWarning($item,'Opening Stock','Opening Stock is informational and will be skipped.');
                if (trim((string)($v[2]??''))!=='') $warnings[]=$this->cellWarning($item,'Product Type','Product Type is derived from active recipe lines.');
                if (trim((string)($v[10]??''))!=='') $warnings[]=$this->cellWarning($item,'Track Stock','Track Stock is not changed by this import.');
                $fields = array_filter($fields, fn ($value, $field) => Schema::hasColumn('pos_products', $field), ARRAY_FILTER_USE_BOTH);
                if ($fields['category']!=='') $categories[$fields['category']] = true;
                $operation = $this->operation('product',$item,$match,$fields,$mode);
                $operations[]=$operation;
                foreach ([$fields['sku'], $fields['barcode'], $fields['name']] as $reference) {
                    $ref = $this->stableReference('', '', $reference);
                    if ($ref !== '') $plannedProducts[$ref] = $operation;
                }
            } elseif ($item['type'] === 'ingredient') {
                [$match, $ambiguous] = $this->matchIngredientRow($ingredients, $v);
                if ($ambiguous) { $errors[]=$this->cellError($item,'Code/Ingredient Name','Ambiguous tenant-scoped ingredient identity.'); continue; }
                $purchaseUnit=trim((string)($v[2]??'')); $usageUnit=trim((string)($v[3]??''));
                $factor=$this->cleanImportNumber($v[4]??null);
                if ($purchaseUnit===$usageUnit && $factor===null) $factor=1;
                $purchaseCost=$this->cleanImportNumber($v[5]??null);
                $fields=['name'=>trim((string)($v[0]??'')),'unit'=>$usageUnit,
                    'base_unit'=>$purchaseUnit,'conversion_factor'=>$factor,
                    'cost_per_unit'=>$factor && $purchaseCost !== null ? $purchaseCost / $factor : $purchaseCost,'min_stock_level'=>$this->cleanImportNumber($v[7]??null) ?: 0,
                    'is_active'=>$this->parseYesNo((string)($v[9]??''))];
                if (Schema::hasColumn('ingredients', 'category')) $fields['category'] = trim((string)($v[1]??''));
                if (Schema::hasColumn('ingredients', 'supplier')) $fields['supplier'] = trim((string)($v[8]??''));
                if ($fields['name']==='' || $fields['unit']==='') { $errors[]=$this->cellError($item,'Ingredient Name/Usage Unit','Ingredient Name and Usage Unit are required.'); continue; }
                if (!in_array($purchaseUnit, RecipeInventoryService::UNITS, true) || !in_array($usageUnit, RecipeInventoryService::UNITS, true)
                    || $factor === null || $factor <= 0) {
                    $errors[]=$this->cellError($item,'Purchase Unit/Usage Unit/Conversion Factor','Units must be allowed and Conversion Factor must be positive when units differ.'); continue;
                }
                $ingredientIdentity = 'name:'.$this->normalizedName($fields['name']);
                if (isset($seenIngredientIdentities[$ingredientIdentity])) {
                    $errors[]=$this->cellError($item,'Ingredient Name','Duplicate ingredient identity in this workbook.'); continue;
                }
                $seenIngredientIdentities[$ingredientIdentity] = $item['row'];
                if ($match) {
                    $unitMeaningChanged = (string) $match->unit !== (string) $fields['unit']
                        || (string) ($match->base_unit ?: $match->unit) !== (string) $fields['base_unit']
                        || abs((float) ($match->conversion_factor ?: 1) - (float) $fields['conversion_factor']) > 0.000001;
                    if ($unitMeaningChanged) {
                        $hasStock = abs((float) $match->current_stock) > 0.000001;
                        $hasRecipes = ProductRecipe::where('company_id', $companyId)
                            ->where('ingredient_id', $match->id)
                            ->exists();
                        if ($hasStock || $hasRecipes) {
                            $errors[]=$this->cellError(
                                $item,
                                'Purchase Unit/Usage Unit/Conversion Factor',
                                'Unit meaning cannot change while stock or recipes exist; use the audited unit-conversion workflow.'
                            );
                            continue;
                        }
                    }
                }
                if (trim((string)($v[1]??'')) !== '' && !Schema::hasColumn('ingredients', 'category')) {
                    $warnings[]=$this->cellWarning($item,'Category','Category is not stored until the menu import migration is applied.');
                }
                if (trim((string)($v[8]??'')) !== '' && !Schema::hasColumn('ingredients', 'supplier')) {
                    $warnings[]=$this->cellWarning($item,'Supplier','Supplier is not stored until the menu import migration is applied.');
                }
                $operation = $this->operation('ingredient',$item,$match,$fields,$mode);
                $operations[]=$operation;
                foreach ([$fields['name']] as $reference) {
                    $ref = $this->stableReference('', '', $reference);
                    if ($ref !== '') $plannedIngredients[$ref] = $operation;
                }
            } else {
                [$product, $productAmbiguous] = $this->matchProductRow($products, [$v[0]??'']);
                [$ingredient, $ingredientAmbiguous] = $this->matchIngredientRow($ingredients, [$v[1]??'']);
                $productRef = $this->stableReference('', '', $v[0] ?? '');
                $ingredientRef = $this->stableReference('', '', $v[1] ?? '');
                $productPlan = $plannedProducts[$productRef] ?? null;
                $ingredientPlan = $plannedIngredients[$ingredientRef] ?? null;
                if (!$product && $productPlan) $product = (object) ['id'=>0, 'name'=>$productPlan['fields']['name']];
                if (!$ingredient && $ingredientPlan) $ingredient = (object) ['id'=>0, 'name'=>$ingredientPlan['fields']['name'], 'unit'=>$ingredientPlan['fields']['unit']];
                $qty=$this->cleanImportNumber($v[2]??null);
                if ($productAmbiguous || $ingredientAmbiguous || !$product || !$ingredient || $qty===null || $qty<=0) { $errors[]=$this->cellError($item,'Product/Ingredient/Quantity Used','Recipe references must be unique and Quantity Used must be positive.'); continue; }
                if (trim((string)($v[3]??'')) === '' || strtolower(trim((string)($v[3]??''))) !== strtolower((string)($ingredient->unit))) {
                    $errors[]=$this->cellError($item,'Usage Unit','Recipe Usage Unit must match the ingredient Usage Unit.'); continue;
                }
                $key=$productRef.'|'.$ingredientRef;
                if (collect($operations)->contains(fn($op)=>$op['entity']==='recipe' && $op['key']===$key)) { $errors[]=$this->cellError($item,'Product Name/Ingredient Name','Duplicate recipe line; combine lines before uploading.'); continue; }
                $existingRecipe = ($product->id && $ingredient->id)
                    ? ProductRecipe::where('company_id',$companyId)->where('product_id',$product->id)->where('ingredient_id',$ingredient->id)->first() : null;
                $waste=$this->cleanImportNumber($v[4]??null); $waste=$waste===null?0:$waste;
                if ($waste<0 || $waste>100) { $errors[]=$this->cellError($item,'Waste %','Waste % must be between 0 and 100.'); continue; }
                $recipeFields=['product_id'=>$product->id,'ingredient_id'=>$ingredient->id,'quantity_needed'=>$qty];
                if (Schema::hasColumn('product_recipes','waste_percent')) $recipeFields['waste_percent']=$waste;
                if (Schema::hasColumn('product_recipes','notes')) $recipeFields['notes']=trim((string)($v[5]??''));
                $operations[]=['entity'=>'recipe','sheet'=>$item['sheet'],'row'=>$item['row'],'key'=>$key,'match_id'=>$existingRecipe?->id,'fingerprint'=>$existingRecipe ? $this->modelFingerprint($existingRecipe,array_keys($recipeFields)) : null,
                    'product_ref'=>$productRef,'ingredient_ref'=>$ingredientRef,
                    'fields'=>$recipeFields,
                    'action'=>$existingRecipe ? ($mode==='create_only' ? 'skip' : 'update') : 'create',
                    'existing_quantity'=>$existingRecipe?->quantity_needed];
                if (trim((string)($v[3]??''))==='') $errors[]=$this->cellError($item,'Usage Unit','Usage Unit is required.');
            }
        }
        return ['operations'=>$operations,'warnings'=>$warnings,'errors'=>$errors,'categories'=>array_keys($categories)];
    }

    private function calculatePreviewRecipeCosts(array $normalized): array
    {
        $costs = [];
        foreach ([$normalized['sample_rows'] ?? [], $normalized['rows'] ?? []] as $rows) {
            $ingredientCosts = [];
            $datasetCosts = [];
            foreach ($rows as $item) {
                if (($item['type'] ?? '') !== 'ingredient') continue;
                $values = $item['values'];
                $factor = $this->cleanImportNumber($values[4] ?? null);
                $purchaseCost = $this->cleanImportNumber($values[5] ?? null);
                if ($factor === null || $factor <= 0 || $purchaseCost === null) continue;
                $ingredientCosts[$this->sampleReference($values[0] ?? '')] = $purchaseCost / $factor;
            }
            foreach ($rows as $item) {
                if (($item['type'] ?? '') !== 'recipe') continue;
                $values = $item['values'];
                $ingredientRef = $this->sampleReference($values[1] ?? '');
                $quantity = $this->cleanImportNumber($values[2] ?? null);
                $waste = $this->cleanImportNumber($values[4] ?? null) ?? 0;
                if (!isset($ingredientCosts[$ingredientRef]) || $quantity === null || $quantity <= 0) continue;
                $product = trim((string) preg_replace('/^'.preg_quote(self::SAMPLE_MARKER, '/').'\s*/i', '', (string) ($values[0] ?? '')));
                if ($product === '') continue;
                $key = $this->normalizedName($product);
                $datasetCosts[$key] ??= ['product'=>$product, 'cost'=>0.0];
                $datasetCosts[$key]['cost'] += $quantity * (1 + max(0, $waste) / 100) * $ingredientCosts[$ingredientRef];
            }
            $costs = array_replace($costs, $datasetCosts);
        }
        return array_values(array_map(
            fn (array $cost): array => ['product'=>$cost['product'], 'cost'=>round($cost['cost'], 2)],
            $costs
        ));
    }

    private function sampleReference($value): string
    {
        return $this->normalizedName(preg_replace(
            '/^'.preg_quote(self::SAMPLE_MARKER, '/').'\s*/i',
            '',
            (string) $value
        ));
    }

    private function validateNormalized(array $normalized,int $companyId,string $mode): array
    {
        $errors=[]; $seen=[];
        foreach ($normalized['rows'] as $n=>$row) {
            $type=$row[0]??''; $name=trim((string)($type==='PRODUCT'?$row[1]:($type==='INGREDIENT'?$row[10]:$row[1])));
            if (!in_array($type,['PRODUCT','INGREDIENT','RECIPE'],true)) $errors[]='Row '.($n+2).': unsupported row type.';
            if ($type==='PRODUCT' && $name==='' ) $errors[]='Products row '.($n+2).': Name is required.';
            if ($type==='INGREDIENT' && $name==='') $errors[]='Ingredients row '.($n+2).': Name is required.';
            if ($type==='RECIPE' && ((float)($row[15]??0)<=0)) $errors[]='Recipes row '.($n+2).': Quantity Needed must be positive.';
            if ($mode==='create' && $type==='PRODUCT') {
                $code=trim((string)($row[2]??'')); $q=PosProduct::where('company_id',$companyId);
                if (($code!=='' && $q->where(fn($x)=>$x->where('sku',$code)->orWhere('barcode',$code))->exists()) || $q->whereRaw('LOWER(name)=?', [strtolower($name)])->exists()) $errors[]='Products row '.($n+2).': item already exists (create-only mode).';
            }
            if ($mode==='create' && $type==='INGREDIENT') {
                $code=trim((string)($row[11]??'')); $q=Ingredient::where('company_id',$companyId);
                if (($code!=='' && $q->where('code',$code)->exists()) || $q->whereRaw('LOWER(name)=?', [strtolower($name)])->exists()) $errors[]='Ingredients row '.($n+2).': item already exists (create-only mode).';
            }
            $key=$type.'|'.strtolower(implode('|',[$row[1]??'',$row[2]??'',$row[10]??'',$row[11]??'']));
            if ($key!=='PRODUCT||||' && isset($seen[$key])) $errors[]='Row '.($n+2).' duplicates row '.$seen[$key].'.';
            $seen[$key]=$n+2;
        }
        return array_slice($errors,0,1000);
    }

    private function operation(string $entity, array $item, $match, array $fields, string $mode): array
    {
        $action = !$match ? 'create' : ($mode === 'create_only' ? 'skip' : 'update');
        $changed = [];
        if ($match) {
            foreach ($fields as $key => $value) {
                if ($value !== null && (string) $match->{$key} !== (string) $value) $changed[$key] = ['from'=>$match->{$key},'to'=>$value];
            }
        }
        if ($match && !$changed) {
            $action = 'skip';
        }
        return ['entity'=>$entity,'sheet'=>$item['sheet'],'row'=>$item['row'],'match_id'=>$match?->id,
            'fingerprint'=>$match ? $this->modelFingerprint($match, array_keys($fields)) : null,
            'fields'=>$fields,'changed'=>$changed,'action'=>$action,
            'references'=>array_values(array_filter([
                $fields['sku'] ?? null, $fields['barcode'] ?? null, $fields['code'] ?? null, $fields['name'] ?? null,
            ]))];
    }

    private function matchProductRow($products, array $values): array
    {
        $sku=trim((string)($values[5]??'')); $barcode=trim((string)($values[6]??'')); $name=$this->normalizedName($values[0]??'');
        $hits=$products->filter(fn($p)=>($sku!=='' && (($p->sku??'')===$sku || ($p->barcode??'')===$sku))
            || ($barcode!=='' && (($p->sku??'')===$barcode || ($p->barcode??'')===$barcode))
            || ($name!=='' && $this->normalizedName($p->name)===$name))->values();
        return [$hits->count()===1?$hits->first():null,$hits->count()>1];
    }

    private function matchIngredientRow($ingredients, array $values): array
    {
        $code=trim((string)($values[1]??'')); $name=$this->normalizedName($values[0]??'');
        $hits=$ingredients->filter(fn($i)=>$name!=='' && $this->normalizedName($i->name)===$name)->values();
        return [$hits->count()===1?$hits->first():null,$hits->count()>1];
    }

    private function normalizedName($value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/u',' ',(string)$value)));
    }

    private function stableReference($first, $second, $name): string
    {
        foreach ([$first, $second] as $value) {
            if (trim((string) $value) !== '') {
                return strtolower(trim((string) $value));
            }
        }
        return $this->normalizedName($name);
    }

    private function modelFingerprint($model, array $fields): string
    {
        $values=[]; foreach ($fields as $field) $values[$field]=$model->{$field};
        return hash('sha256',json_encode($values));
    }

    private function catalogFingerprint(int $companyId): string
    {
        $products=PosProduct::where('company_id',$companyId)->orderBy('id')->get(['id','name','sku','barcode','price','cost_price','category','uom','tax_rate','is_active','description','low_stock_threshold']);
        $ingredients=Ingredient::where('company_id',$companyId)->orderBy('id')->get(['id','name','code','unit','base_unit','conversion_factor','cost_per_unit','current_stock','min_stock_level','is_active']);
        $recipes=ProductRecipe::where('company_id',$companyId)->orderBy('id')->get(['id','product_id','ingredient_id','quantity_needed','is_active']);
        $branchStocks = Schema::hasTable('ingredient_stocks')
            ? DB::table('ingredient_stocks')->where('company_id', $companyId)->orderBy('id')->get(['id','ingredient_id','branch_id','quantity'])
            : collect();
        return hash('sha256',json_encode([$products->toArray(),$ingredients->toArray(),$recipes->toArray(),$branchStocks->toArray()]));
    }

    private function applyOperations(array $operations,int $companyId,string $mode): array
    {
        $counts=['created'=>0,'updated'=>0,'skipped'=>0,'recipes_created'=>0,'recipes_updated'=>0];
        $references = [];
        foreach (['ingredient','product','recipe'] as $entity) {
            foreach ($operations as $operation) {
                if ($operation['entity']!==$entity) continue;
                if ($operation['action']==='skip') { $counts['skipped']++; continue; }
                if ($entity==='ingredient') {
                    $model=$operation['match_id'] ? Ingredient::where('company_id',$companyId)->lockForUpdate()->find($operation['match_id']) : null;
                    if ($operation['match_id'] && (!$model || $this->modelFingerprint($model,array_keys($operation['fields']))!==$operation['fingerprint'])) throw new \RuntimeException('Ingredient changed after preview.');
                    if ($model) {
                        $unitMeaningChanged = (string) $model->unit !== (string) ($operation['fields']['unit'] ?? $model->unit)
                            || (string) ($model->base_unit ?: $model->unit) !== (string) ($operation['fields']['base_unit'] ?? ($model->base_unit ?: $model->unit))
                            || abs((float) ($model->conversion_factor ?: 1) - (float) ($operation['fields']['conversion_factor'] ?? ($model->conversion_factor ?: 1))) > 0.000001;
                        if ($unitMeaningChanged) {
                            $branchStocks = Schema::hasTable('ingredient_stocks')
                                ? DB::table('ingredient_stocks')->where('company_id',$companyId)
                                    ->where('ingredient_id',$model->id)->lockForUpdate()->get()
                                : collect();
                            $hasRecipes = ProductRecipe::where('company_id',$companyId)
                                ->where('ingredient_id',$model->id)->lockForUpdate()->exists();
                            $hasStock = abs((float) $model->current_stock) > 0.000001
                                || $branchStocks->contains(fn ($stock) => abs((float) $stock->quantity) > 0.000001);
                            if ($hasStock || $hasRecipes) {
                                throw new \RuntimeException('Ingredient unit meaning changed after preview or is already in use.');
                            }
                        }
                        $model->update(array_filter($operation['fields'],fn($v)=>$v!==null));
                        $created = $model;
                        $counts['updated']++;
                    }
                    else {
                        $name = $this->normalizedName($operation['fields']['name'] ?? '');
                        if ($name !== '' && Ingredient::where('company_id',$companyId)
                            ->whereRaw('LOWER(TRIM(name)) = ?', [$name])->exists()) {
                            throw new \RuntimeException('Ingredient identity was created after preview.');
                        }
                        $created = Ingredient::create($operation['fields']+['company_id'=>$companyId,'current_stock'=>0]);
                        $counts['created']++;
                    }
                    foreach ($operation['references'] ?? [$operation['fields']['name']] as $reference) {
                        $references[$this->stableReference('', '', $reference)] = $created->id;
                    }
                } elseif ($entity==='product') {
                    $model=$operation['match_id'] ? PosProduct::where('company_id',$companyId)->lockForUpdate()->find($operation['match_id']) : null;
                    if ($operation['match_id'] && (!$model || $this->modelFingerprint($model,array_keys($operation['fields']))!==$operation['fingerprint'])) throw new \RuntimeException('Product changed after preview.');
                    if ($model) { $model->update(array_filter($operation['fields'],fn($v)=>$v!==null)); $created = $model; $counts['updated']++; }
                    else {
                        $fields = $operation['fields'];
                        $duplicate = PosProduct::where('company_id',$companyId)->where(function ($query) use ($fields) {
                            if (!empty($fields['sku'])) $query->orWhere('sku',$fields['sku'])->orWhere('barcode',$fields['sku']);
                            if (!empty($fields['barcode'])) $query->orWhere('sku',$fields['barcode'])->orWhere('barcode',$fields['barcode']);
                            $query->orWhereRaw('LOWER(TRIM(name)) = ?', [$this->normalizedName($fields['name'] ?? '')]);
                        })->exists();
                        if ($duplicate) throw new \RuntimeException('Product identity was created after preview.');
                        $created = PosProduct::create($fields+['company_id'=>$companyId,'show_on_sale'=>true]);
                        $counts['created']++;
                    }
                    foreach ([$operation['fields']['sku'] ?? '', $operation['fields']['barcode'] ?? '', $operation['fields']['name'] ?? ''] as $reference) {
                        $references[$this->stableReference('', '', $reference)] = $created->id;
                    }
                } else {
                    if ($operation['action'] === 'skip' && $mode === 'create_only') { $counts['skipped']++; continue; }
                    $operation['fields']['product_id'] = $references[$operation['product_ref']] ?? $operation['fields']['product_id'];
                    $operation['fields']['ingredient_id'] = $references[$operation['ingredient_ref']] ?? $operation['fields']['ingredient_id'];
                    $existing=ProductRecipe::where('company_id',$companyId)->where('product_id',$operation['fields']['product_id'])->where('ingredient_id',$operation['fields']['ingredient_id'])->lockForUpdate()->first();
                    if ($operation['match_id'] && (!$existing || $this->modelFingerprint($existing, array_keys($operation['fields'])) !== $operation['fingerprint'])) {
                        throw new \RuntimeException('Recipe changed after preview.');
                    }
                    if ($existing) {
                        $changes = [];
                        foreach (['quantity_needed', 'waste_percent', 'notes'] as $field) {
                            if (array_key_exists($field, $operation['fields'])
                                && (string) $existing->{$field} !== (string) $operation['fields'][$field]) {
                                $changes[$field] = $operation['fields'][$field];
                            }
                        }
                        if ($changes) {
                            $changes['recipe_version'] = ((int) $existing->recipe_version) + 1;
                            $existing->update($changes);
                            $counts['recipes_updated']++;
                        } else {
                            $counts['skipped']++;
                        }
                    }
                    else { ProductRecipe::create($operation['fields']+['company_id'=>$companyId,'recipe_version'=>1]); $counts['recipes_created']++; }
                }
            }
        }
        return ['ok'=>true,'message'=>'Menu import confirmed.','counts'=>$counts,'errors'=>[]];
    }

    private function cellError(array $item,string $field,string $message): array
    {
        return ['sheet'=>$item['sheet'],'row'=>$item['row'],'field'=>$field,'message'=>$message];
    }

    private function cellWarning(array $item,string $field,string $message): array
    {
        return $this->cellError($item,$field,$message);
    }

    private function productHasRecipe(int $productId, ?int $companyId = null): bool
    {
        $query = ProductRecipe::where('product_id',$productId);
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }
        return $query->where(function ($query) {
            if (Schema::hasColumn('product_recipes','is_active')) $query->where('is_active',true);
        })->exists();
    }

    private function previewCounts(array $rows): array
    {
        return ['products'=>count(array_filter($rows,fn($r)=>($r[0]??'')==='PRODUCT')),'ingredients'=>count(array_filter($rows,fn($r)=>($r[0]??'')==='INGREDIENT')),'recipes'=>count(array_filter($rows,fn($r)=>($r[0]??'')==='RECIPE'))];
    }

    private function csvValue($value): string
    {
        return '"'.str_replace('"', '""', (string) $value).'"';
    }
}
