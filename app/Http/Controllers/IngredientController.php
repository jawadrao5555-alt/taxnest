<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\ProductRecipe;
use App\Models\PosProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\RecipeInventoryService;

class IngredientController extends Controller
{
    // Template sample rows carry this EXPLICIT product-name marker so the
    // import can identify them reliably. Never infer "sample" from business
    // values (a real Chicken Burger + Bun qty-1 recipe must always import).
    private const RECIPE_SAMPLE_MARKER = 'Misal:';

    // Row cap for the bulk import (data rows, excluding header). A 200-dish
    // menu with 10 ingredients each is 2,000 rows — plenty of headroom.
    private const RECIPE_IMPORT_MAX_ROWS = 2000;

    public function index()
    {
        $companyId = app('currentCompanyId');

        $ingredients = Ingredient::where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return view('pos.restaurant.ingredients', compact('ingredients'));
    }

    public function kitchenReport(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);
        $companyId = (int) app('currentCompanyId');
        $from = $request->input('from');
        $to = $request->input('to');
        $movements = collect();
        $prepared = collect();
        $wastage = collect();
        $praReturns = collect();
        $foodCost = collect();
        $returnSummary = collect();
        $lowStock = collect();

        if (Schema::hasTable('ingredient_movements')) {
            $query = DB::table('ingredient_movements as im')
                ->leftJoin('ingredients as i', function ($join) {
                    $join->on('i.id', '=', 'im.ingredient_id')
                        ->on('i.company_id', '=', 'im.company_id');
                })
                ->where('im.company_id', $companyId)
                ->whereIn('im.type', ['recipe_sale', 'recipe_return', 'ingredient_adjustment'])
                ->select('im.*', 'i.name as ingredient_name', 'i.unit');
            if ($from) $query->whereDate('im.created_at', '>=', $from);
            if ($to) $query->whereDate('im.created_at', '<=', $to);
            $movements = $query->orderByDesc('im.created_at')->limit(500)->get();
        }

        if (Schema::hasTable('prepared_returns')) {
            $prepared = DB::table('prepared_returns as pr')
                ->leftJoin('pos_products as p', function ($join) {
                    $join->on('p.id', '=', 'pr.product_id')
                        ->on('p.company_id', '=', 'pr.company_id');
                })
                ->where('pr.company_id', $companyId)
                ->select('pr.*', 'p.name as product_name')
                ->orderByDesc('pr.created_at')->limit(500)->get();
        }

        if (Schema::hasTable('ingredients')) {
            $lowStock = Ingredient::where('company_id', $companyId)
                ->where('is_active', true)
                ->whereColumn('current_stock', '<=', 'min_stock_level')
                ->orderBy('current_stock')
                ->limit(500)->get();
        }

        if (Schema::hasTable('recipe_consumptions') && Schema::hasTable('ingredients')) {
            $query = DB::table('recipe_consumptions as rc')
                ->join('ingredients as i', function ($join) {
                    $join->on('i.id', '=', 'rc.ingredient_id')
                        ->on('i.company_id', '=', 'rc.company_id');
                })
                ->where('rc.company_id', $companyId)
                ->where('rc.ingredient_id', '>', 0)
                ->select('i.name as ingredient_name', 'i.unit', 'i.cost_per_unit',
                    DB::raw('SUM(rc.quantity) as quantity'),
                    DB::raw('SUM(rc.quantity * i.cost_per_unit) as cost'))
                ->groupBy('i.id', 'i.name', 'i.unit', 'i.cost_per_unit')
                ->orderByDesc('cost');
            if ($from) $query->whereDate('rc.created_at', '>=', $from);
            if ($to) $query->whereDate('rc.created_at', '<=', $to);
            if (Schema::hasColumn('recipe_consumptions', 'reversed_at')) {
                $query->whereNull('rc.reversed_at');
            }
            $foodCost = $query->limit(500)->get();
        }

        if (Schema::hasTable('pos_transaction_items') && Schema::hasColumn('pos_transaction_items', 'return_disposition')) {
            $query = DB::table('pos_transaction_items as ri')
                ->join('pos_transactions as rt', 'rt.id', '=', 'ri.transaction_id')
                ->where('rt.company_id', $companyId)
                ->where('rt.transaction_type', 'return')
                ->where('ri.return_disposition', RecipeInventoryService::DISPOSITION_WASTAGE)
                ->select('ri.item_name', 'ri.quantity', 'ri.subtotal', 'ri.created_at', 'rt.invoice_number');
            if ($from) $query->whereDate('ri.created_at', '>=', $from);
            if ($to) $query->whereDate('ri.created_at', '<=', $to);
            $wastage = $query->orderByDesc('ri.created_at')->limit(500)->get();

            if (Schema::hasColumn('pos_transactions', 'transaction_type')) {
                $returnSummary = DB::table('pos_transaction_items as ri')
                    ->join('pos_transactions as rt', 'rt.id', '=', 'ri.transaction_id')
                    ->where('rt.company_id', $companyId)
                    ->where('rt.transaction_type', 'return')
                    ->whereNotNull('ri.return_disposition')
                    ->select('ri.return_disposition', DB::raw('SUM(ri.quantity) as quantity'), DB::raw('SUM(ri.subtotal) as subtotal'))
                    ->groupBy('ri.return_disposition')
                    ->orderBy('ri.return_disposition')->get();
            }
        }

        if (Schema::hasTable('pos_transactions')
            && Schema::hasColumn('pos_transactions', 'transaction_type')
            && Schema::hasColumn('pos_transactions', 'parent_transaction_id')) {
            $praReturns = DB::table('pos_transactions as rt')
                ->leftJoin('pos_transactions as parent', 'parent.id', '=', 'rt.parent_transaction_id')
                ->where('rt.company_id', $companyId)
                ->where('rt.transaction_type', 'return')
                ->select('rt.id', 'rt.invoice_number', 'rt.pra_status', 'rt.pra_invoice_number',
                    'rt.total_amount', 'rt.created_at', 'parent.invoice_number as parent_invoice_number')
                ->orderByDesc('rt.created_at')->limit(500)->get();
        }

        $consumedQty = $movements->where('type', 'recipe_sale')->sum('quantity');
        $returnedQty = $movements->where('type', 'recipe_return')->sum('quantity');
        $wastageValue = $wastage->sum('subtotal');
        $foodCostTotal = $foodCost->sum('cost');
        $returnTotal = $returnSummary->sum('quantity');

        return view('pos.restaurant.kitchen-report', compact(
            'movements', 'prepared', 'wastage', 'praReturns',
            'foodCost', 'foodCostTotal', 'returnSummary', 'returnTotal', 'lowStock',
            'consumedQty', 'returnedQty', 'wastageValue', 'from', 'to'
        ));
    }

    public function store(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'unit' => 'required|in:' . implode(',', RecipeInventoryService::UNITS),
            'base_unit' => 'nullable|in:' . implode(',', RecipeInventoryService::UNITS),
            'conversion_factor' => 'nullable|numeric|gt:0',
            'cost_per_unit' => 'required|numeric|min:0',
            'current_stock' => 'required|numeric|min:0',
            'min_stock_level' => 'required|numeric|min:0',
        ]);

        $code = trim((string) $request->input('code', ''));
        if ($code !== '' && Schema::hasColumn('ingredients', 'code')
            && Ingredient::where('company_id', $companyId)->whereRaw('LOWER(code) = ?', [strtolower($code)])->exists()) {
            return back()->withInput()->with('error', 'Yeh ingredient code pehle se mojood hai.');
        }
        $ingredient = Ingredient::create([
            'company_id' => $companyId,
            'code' => $code !== '' && Schema::hasColumn('ingredients', 'code') ? $code : null,
            'name' => $request->name,
            'unit' => $request->unit,
            'base_unit' => $request->input('base_unit') ?: $request->unit,
            'conversion_factor' => (float) ($request->input('conversion_factor') ?: 1),
            'cost_per_unit' => $request->cost_per_unit,
            // Opening stock is posted through the same locked adjustment
            // ledger below; do not seed it here and count it twice.
            'current_stock' => 0,
            'min_stock_level' => $request->min_stock_level,
        ]);
        if ((float) $request->current_stock > 0) {
            RecipeInventoryService::adjustIngredientStock(
                (int) $companyId, (int) $ingredient->id, (float) $request->current_stock,
                auth('pos')->id(), null, 'Opening kitchen stock'
            );
        }

        return back()->with('success', "Ingredient \"{$request->name}\" added.");
    }

    public function update(Request $request, $id)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'unit' => 'required|in:' . implode(',', RecipeInventoryService::UNITS),
            'base_unit' => 'nullable|in:' . implode(',', RecipeInventoryService::UNITS),
            'conversion_factor' => 'nullable|numeric|gt:0',
            'cost_per_unit' => 'required|numeric|min:0',
            'min_stock_level' => 'required|numeric|min:0',
        ]);

        $ingredient = Ingredient::where('company_id', $companyId)->findOrFail($id);
        $code = trim((string) $request->input('code', ''));
        if ($code !== '' && Schema::hasColumn('ingredients', 'code')
            && Ingredient::where('company_id', $companyId)->where('id', '!=', $id)
                ->whereRaw('LOWER(code) = ?', [strtolower($code)])->exists()) {
            return back()->withInput()->with('error', 'Yeh ingredient code pehle se mojood hai.');
        }

        $ingredient->update([
            'code' => $code !== '' && Schema::hasColumn('ingredients', 'code') ? $code : null,
            'name' => $request->name,
            'unit' => $request->unit,
            'base_unit' => $request->input('base_unit') ?: $request->unit,
            'conversion_factor' => (float) ($request->input('conversion_factor') ?: 1),
            'cost_per_unit' => $request->cost_per_unit,
            'min_stock_level' => $request->min_stock_level,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', "Ingredient updated.");
    }

    public function adjustStock(Request $request, $id)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'adjustment' => 'required|numeric',
            'reason' => 'required|string|max:255',
        ]);

        $ingredient = Ingredient::where('company_id', $companyId)->findOrFail($id);
        try {
            $result = RecipeInventoryService::adjustIngredientStock(
                (int) $companyId, (int) $ingredient->id, (float) $request->adjustment,
                auth('pos')->id(), null, (string) $request->reason
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Stock adjusted. New: {$result['after']} {$ingredient->unit}");
    }

    public function destroy($id)
    {
        $companyId = app('currentCompanyId');
        $ingredient = Ingredient::where('company_id', $companyId)->findOrFail($id);

        $recipesUsing = ProductRecipe::where('ingredient_id', $id)->count();
        if ($recipesUsing > 0) {
            return back()->with('error', "Cannot delete ingredient used in {$recipesUsing} recipe(s). Remove from recipes first.");
        }

        $ingredient->delete();
        return back()->with('success', "Ingredient deleted.");
    }

    public function recipes()
    {
        $companyId = app('currentCompanyId');

        $products = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $ingredients = Ingredient::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $recipes = ProductRecipe::where('company_id', $companyId)
            ->with(['product', 'ingredient'])
            ->get()
            ->groupBy('product_id');

        return view('pos.restaurant.recipes', compact('products', 'ingredients', 'recipes'));
    }

    public function storeRecipe(Request $request)
    {
        $companyId = app('currentCompanyId');

        // MULTI-INGREDIENT FLOW (owner request Jul 2026): one form submits MANY ingredient
        // rows for a product. Each row picks an existing ingredient OR creates one inline.
        $request->validate([
            'product_id' => 'required|integer',
            'ingredients' => 'required|array|min:1',
            'ingredients.*.quantity_needed' => 'required|numeric|min:0.0001',
            'ingredients.*.ingredient_id' => 'nullable|integer',
            'ingredients.*.new_name' => 'nullable|string|max:120',
            'ingredients.*.new_unit' => 'nullable|string|max:20',
            'ingredients.*.new_cost' => 'nullable|numeric|min:0',
        ]);

        $product = PosProduct::where('company_id', $companyId)->where('id', $request->product_id)->first();
        if (!$product) {
            return back()->with('error', 'Invalid product.');
        }

        $added = [];
        $skipped = [];

        foreach ($request->input('ingredients', []) as $row) {
            $ingredient = null;

            if (!empty($row['ingredient_id'])) {
                $ingredient = Ingredient::where('company_id', $companyId)->where('id', $row['ingredient_id'])->first();
            } elseif (!empty($row['new_name'])) {
                $name = trim($row['new_name']);
                $unit = strtolower(trim($row['new_unit'] ?? '')) ?: 'pcs';
                if (!in_array($unit, RecipeInventoryService::UNITS, true)) {
                    $skipped[] = "'{$name}' ka unit valid nahi";
                    continue;
                }
                // Reuse existing ingredient if name+unit matches (avoid duplicates)
                $ingredient = Ingredient::where('company_id', $companyId)
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->where('unit', $unit)
                    ->first();
                if (!$ingredient) {
                    $ingredient = Ingredient::create([
                        'company_id' => $companyId,
                        'name' => $name,
                        'unit' => $unit,
                        'cost_per_unit' => (float) ($row['new_cost'] ?? 0),
                        'current_stock' => 0,
                        'min_stock_level' => 0,
                        'is_active' => true,
                    ]);
                }
            }

            if (!$ingredient) {
                $skipped[] = 'ek row (ingredient select/likha nahi gaya)';
                continue;
            }

            $exists = ProductRecipe::where('company_id', $companyId)
                ->where('product_id', $request->product_id)
                ->where('ingredient_id', $ingredient->id)
                ->exists();

            if ($exists || in_array($ingredient->id, array_column($added, 'id'))) {
                $skipped[] = "'{$ingredient->name}' (pehle se recipe mein hai)";
                continue;
            }

            $recipeData = [
                'company_id' => $companyId,
                'product_id' => $request->product_id,
                'ingredient_id' => $ingredient->id,
                'quantity_needed' => $row['quantity_needed'],
            ];
            if (Schema::hasColumn('product_recipes', 'recipe_version')) {
                $recipeData['recipe_version'] = 1;
            }
            ProductRecipe::create($recipeData);

            $added[] = ['id' => $ingredient->id, 'name' => $ingredient->name];
        }

        if (empty($added)) {
            return back()->with('error', 'Koi ingredient add nahi hua' . (!empty($skipped) ? ' — ' . implode(', ', $skipped) : '.'));
        }

        $msg = count($added) . ' ingredient(s) added: ' . implode(', ', array_column($added, 'name')) . '.';
        if (!empty($skipped)) {
            $msg .= ' Skipped: ' . implode(', ', $skipped) . '.';
        }

        return back()->with('success', $msg);
    }

    /**
     * Recipes Excel template (Task 1162): real .xlsx (never CSV — CSV round-trips
     * mangle code columns into scientific notation). One ingredient per row;
     * repeating the product name groups rows into one recipe on import.
     */
    public function downloadRecipeTemplate()
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Recipes');

        $headers = ['Product Name', 'Product Code (SKU/Barcode)', 'Ingredient Name', 'Unit', 'Quantity Needed', 'Cost per Unit (optional)'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E9D5FF');
        foreach (['A' => 28, 'B' => 24, 'C' => 24, 'D' => 10, 'E' => 16, 'F' => 20] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        // Product Code column forced to TEXT so Excel never converts long
        // barcodes to scientific notation or strips leading zeros.
        $sheet->getStyle('B:B')->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

        // Sample rows are marked with the explicit 'Misal:' prefix — the import
        // recognizes the marker (never the values) and skips them with a clear
        // reason, so an untouched template can't create junk data.
        $samples = [
            [self::RECIPE_SAMPLE_MARKER . ' Chicken Burger', '', 'Bun', 'pcs', 1, 15],
            [self::RECIPE_SAMPLE_MARKER . ' Chicken Burger', '', 'Chicken Patty', 'pcs', 1, 60],
            [self::RECIPE_SAMPLE_MARKER . ' Chicken Burger', '', 'Mayo Sauce', 'g', 20, 0.4],
            [self::RECIPE_SAMPLE_MARKER . ' Zinger Large Pizza', 'PZ-001', 'Pizza Dough', 'g', 350, 0.15],
            [self::RECIPE_SAMPLE_MARKER . ' Zinger Large Pizza', 'PZ-001', 'Cheese', 'g', 120, 1.2],
        ];
        $rowNum = 2;
        foreach ($samples as $s) {
            $sheet->setCellValue('A' . $rowNum, $s[0]);
            $sheet->setCellValueExplicit('B' . $rowNum, (string) $s[1], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $rowNum, $s[2]);
            $sheet->setCellValue('D' . $rowNum, $s[3]);
            $sheet->setCellValue('E' . $rowNum, $s[4]);
            $sheet->setCellValue('F' . $rowNum, $s[5]);
            $rowNum++;
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'recipes_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Bulk recipe import (Task 1162). Per-row: resolve product company-scoped
     * (code → name), find-or-create ingredient (name+unit), upsert the recipe
     * row (duplicate product+ingredient UPDATES quantity — never errors). Bad
     * rows are skipped with a reason; they never abort the file.
     */
    public function importRecipes(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls|max:5120',
        ], [
            'excel_file.required' => 'Pehle Excel file chunein.',
            'excel_file.mimes' => 'File Excel (.xlsx) honi chahiye — template download kar ke usi file mein likhein.',
            'excel_file.max' => 'File 5 MB se choti honi chahiye.',
        ]);

        try {
            $rows = $this->readRecipeRowsExcel($request->file('excel_file')->getRealPath());
        } catch (\Throwable $e) {
            Log::error('POS recipe import parse failed: ' . $e->getMessage());
            return back()->with('error', 'File parhi nahi ja saki — file kharab ya password-protected lag rahi hai. Template dobara download kar ke usi file mein recipes likhein.');
        }

        if (count($rows) < 2) {
            return back()->with('error', 'File khali hai — header ke neeche recipe rows likhein.');
        }
        if (count($rows) > self::RECIPE_IMPORT_MAX_ROWS + 1) {
            return back()->with('error', 'Ek waqt mein zyada se zyada ' . self::RECIPE_IMPORT_MAX_ROWS . ' rows import karein — file do hisson mein todein.');
        }

        $header = array_map(function ($h) {
            return strtolower(trim(preg_replace('/[\x{FEFF}]/u', '', (string) $h)));
        }, $rows[0]);

        $productIdx = $this->findRecipeColumn($header, ['product name', 'product', 'item name', 'item']);
        $codeIdx = $this->findRecipeColumn($header, ['product code (sku/barcode)', 'product code (sku / barcode)', 'product code', 'code', 'sku', 'barcode', 'item code']);
        $ingIdx = $this->findRecipeColumn($header, ['ingredient name', 'ingredient']);
        $unitIdx = $this->findRecipeColumn($header, ['unit', 'uom', 'unit (uom)']);
        $qtyIdx = $this->findRecipeColumn($header, ['quantity needed', 'qty needed', 'quantity', 'qty', 'miqdaar']);
        $costIdx = $this->findRecipeColumn($header, ['cost per unit (optional)', 'cost per unit', 'cost', 'rate']);

        if (($productIdx === false && $codeIdx === false) || $ingIdx === false || $qtyIdx === false) {
            return back()->with('error', 'File mein "Product Name", "Ingredient Name" aur "Quantity Needed" columns nahi mile. Template download kar ke USI file mein recipes likh kar dobara upload karein — pehli header row delete na karein.');
        }

        DB::beginTransaction();
        try {
            // Preload company catalog + ingredients + existing recipes ONCE.
            // Maps updated after each create so later rows in the same file
            // match what earlier rows created.
            $byBarcode = []; $bySku = []; $byName = [];
            foreach (PosProduct::where('company_id', $companyId)->get() as $p) {
                if (trim((string) $p->barcode) !== '') $byBarcode[strtolower(trim($p->barcode))] = $p;
                if (trim((string) $p->sku) !== '') $bySku[strtolower(trim($p->sku))] = $p;
                $byName[strtolower(trim($p->name))] = $p;
            }

            $ingByNameUnit = []; $ingByName = [];
            foreach (Ingredient::where('company_id', $companyId)->get() as $ing) {
                $ingByNameUnit[strtolower(trim($ing->name)) . '|' . strtolower(trim($ing->unit))] = $ing;
                $ingByName[strtolower(trim($ing->name))] ??= $ing;
            }

            $recipeByKey = [];
            foreach (ProductRecipe::where('company_id', $companyId)->get() as $r) {
                $recipeByKey[$r->product_id . '|' . $r->ingredient_id] = $r;
            }

            $added = 0; $updated = 0; $newIngredients = 0; $skipped = 0; $samplesSkipped = 0;
            $errors = [];

            for ($i = 1; $i < count($rows); $i++) {
                $data = $rows[$i];
                $rowNo = $i + 1;

                $rowEmpty = true;
                foreach ($data as $cell) { if (trim((string) $cell) !== '') { $rowEmpty = false; break; } }
                if ($rowEmpty) continue;

                $productName = $productIdx !== false ? trim((string) ($data[$productIdx] ?? '')) : '';
                $code = $codeIdx !== false ? $this->cleanRecipeCode($data[$codeIdx] ?? null) : null;
                $ingName = trim((string) ($data[$ingIdx] ?? ''));
                $unit = $unitIdx !== false ? strtolower(trim((string) ($data[$unitIdx] ?? ''))) : '';
                $qty = $this->cleanRecipeNumber($data[$qtyIdx] ?? '');
                $cost = $costIdx !== false ? $this->cleanRecipeNumber($data[$costIdx] ?? '') : null;

                // Untouched template sample rows (explicit 'Misal:' marker) are
                // skipped — identified by the marker ONLY, never by values.
                if ($productName !== '' && stripos($productName, self::RECIPE_SAMPLE_MARKER) === 0) {
                    $samplesSkipped++;
                    continue;
                }

                if ($productName === '' && $code === null) {
                    $errors[] = "Row {$rowNo}: product ka naam/code khali hai";
                    $skipped++;
                    continue;
                }
                if ($ingName === '') {
                    $errors[] = "Row {$rowNo}: ingredient ka naam khali hai";
                    $skipped++;
                    continue;
                }
                if ($qty === null || $qty <= 0) {
                    $errors[] = "Row {$rowNo}: '{$ingName}' ki miqdaar samajh nahi aayi (" . trim((string) ($data[$qtyIdx] ?? '')) . ")";
                    $skipped++;
                    continue;
                }

                // Product match precedence: barcode → SKU → name (company-scoped).
                $product = null;
                if ($code !== null) {
                    $product = $byBarcode[strtolower($code)] ?? $bySku[strtolower($code)] ?? null;
                }
                if (!$product && $productName !== '') {
                    $product = $byName[strtolower($productName)] ?? null;
                }
                if (!$product) {
                    $label = $productName !== '' ? $productName : $code;
                    $errors[] = "Row {$rowNo}: product '{$label}' nahi mila — naam/code Products page jaisa likhein";
                    $skipped++;
                    continue;
                }

                // Find-or-create ingredient (name+unit — same rule as the modal).
                $ingredient = null;
                if ($unit !== '') {
                    $ingredient = $ingByNameUnit[strtolower($ingName) . '|' . $unit] ?? null;
                } else {
                    $ingredient = $ingByName[strtolower($ingName)] ?? null;
                }
                if (!$ingredient) {
                    $ingredient = Ingredient::create([
                        'company_id' => $companyId,
                        'name' => $ingName,
                        'unit' => $unit !== '' ? $unit : 'pcs',
                        'cost_per_unit' => $cost !== null && $cost >= 0 ? $cost : 0,
                        'current_stock' => 0,
                        'min_stock_level' => 0,
                        'is_active' => true,
                    ]);
                    $newIngredients++;
                    $ingByNameUnit[strtolower($ingName) . '|' . strtolower($ingredient->unit)] = $ingredient;
                    $ingByName[strtolower($ingName)] ??= $ingredient;
                }

                // Upsert: duplicate product+ingredient UPDATES quantity.
                $key = $product->id . '|' . $ingredient->id;
                if (isset($recipeByKey[$key])) {
                    $recipeUpdate = ['quantity_needed' => $qty];
                    if (Schema::hasColumn('product_recipes', 'recipe_version')) {
                        $recipeUpdate['recipe_version'] = ((int) ($recipeByKey[$key]->recipe_version ?? 1)) + 1;
                    }
                    $recipeByKey[$key]->update($recipeUpdate);
                    $updated++;
                } else {
                    $recipeData = [
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'ingredient_id' => $ingredient->id,
                        'quantity_needed' => $qty,
                    ];
                    if (Schema::hasColumn('product_recipes', 'recipe_version')) {
                        $recipeData['recipe_version'] = 1;
                    }
                    $recipeByKey[$key] = ProductRecipe::create($recipeData);
                    $added++;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $parts = [];
        if ($added > 0) $parts[] = "{$added} nayi recipe rows add hui";
        if ($updated > 0) $parts[] = "{$updated} update hui";
        if ($newIngredients > 0) $parts[] = "{$newIngredients} naye ingredients bane";
        if ($samplesSkipped > 0) $parts[] = "{$samplesSkipped} template sample rows chhori gayi";
        if ($skipped > 0) $parts[] = "{$skipped} rows skip hui";
        $msg = $parts ? implode(', ', $parts) . '.' : 'File mein koi recipe row nahi mili.';
        if (!empty($errors)) {
            $msg .= ' Masail: ' . implode('; ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? ' (+' . (count($errors) - 5) . ' aur)' : '');
        }

        if ($added === 0 && $updated === 0) {
            return back()->with('error', $msg);
        }
        return back()->with('success', $msg);
    }

    private function readRecipeRowsExcel(string $path): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // Row-cap BEFORE materializing the sheet — a 5MB xlsx can hold hundreds
        // of thousands of rows (zip compression); toArray() on that would OOM
        // shared cPanel PHP before any post-parse count check ran.
        if ($sheet->getHighestDataRow() > self::RECIPE_IMPORT_MAX_ROWS + 1) {
            $spreadsheet->disconnectWorksheets();
            return array_fill(0, self::RECIPE_IMPORT_MAX_ROWS + 2, []); // triggers the friendly cap error upstream
        }

        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();
        return $rows;
    }

    // "Rs 1,200", "0.25", "16%" → float; anything non-numeric → null.
    private function cleanRecipeNumber($raw): ?float
    {
        if (is_int($raw) || is_float($raw)) return (float) $raw;
        $s = trim((string) $raw);
        if ($s === '') return null;
        $s = str_ireplace(['rs.', 'rs', 'pkr', '%'], '', $s);
        $s = str_replace([',', ' '], '', $s);
        if (!is_numeric($s)) return null;
        return (float) $s;
    }

    // Code cleaner: Excel numeric cells arrive as floats (8901234567890.0) and
    // scientific notation ("8.9E+12") — both restored to plain digit strings.
    private function cleanRecipeCode($raw): ?string
    {
        if ($raw === null) return null;
        if (is_int($raw) || is_float($raw)) return sprintf('%.0f', (float) $raw);
        $s = trim((string) $raw);
        if ($s === '') return null;
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $s)) return sprintf('%.0f', (float) $s);
        if (preg_match('/^\d+\.0+$/', $s)) return preg_replace('/\.0+$/', '', $s);
        return $s;
    }

    private function findRecipeColumn(array $header, array $names): int|false
    {
        foreach ($names as $name) {
            $idx = array_search($name, $header);
            if ($idx !== false) return $idx;
        }
        return false;
    }

    public function updateRecipe(Request $request, $id)
    {
        $companyId = app('currentCompanyId');

        $request->validate(['quantity_needed' => 'required|numeric|min:0.0001']);

        $recipe = ProductRecipe::where('company_id', $companyId)->findOrFail($id);
        $recipeUpdate = ['quantity_needed' => $request->quantity_needed];
        if (Schema::hasColumn('product_recipes', 'recipe_version')) {
            $recipeUpdate['recipe_version'] = ((int) ($recipe->recipe_version ?? 1)) + 1;
        }
        $recipe->update($recipeUpdate);

        return back()->with('success', 'Recipe updated.');
    }

    public function deleteRecipe($id)
    {
        $companyId = app('currentCompanyId');
        $recipe = ProductRecipe::where('company_id', $companyId)->findOrFail($id);
        $recipe->delete();

        return back()->with('success', 'Recipe ingredient removed.');
    }
}
