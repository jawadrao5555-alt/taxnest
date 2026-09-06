<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\MedicineCatalogueEntry;
use App\Models\MedicineCataloguePrice;
use App\Models\MedicineCatalogueSync;
use App\Models\MedicinePriceNotice;
use App\Services\Pharmacy\MedicineCatalogueSyncService;
use App\Services\Pharmacy\MedicineCompositionParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS admin — global Medicine Catalogue (Task 1579).
 *
 * Source of the DRAP rows: Drug Regulatory Authority of Pakistan, Pharmaceutical
 * Product Price Index (Government of Pakistan public data),
 * https://e.dra.gov.pk/public/price — cited on the page.
 *
 * Pattern follows the HS-master admin pages: one list/search page, actions as
 * POSTs that flash back, an xlsx export and a supplementary xlsx import.
 */
class MedicineCatalogueController extends Controller
{
    public function __construct(private readonly MedicineCatalogueSyncService $sync)
    {
    }

    public function index(Request $request)
    {
        if (!MedicineCatalogueSyncService::tablesReady()) {
            return view('admin.medicine-catalogue', [
                'ready' => false, 'entries' => collect(), 'stats' => [], 'run' => null,
                'q' => '', 'category' => '', 'source' => '', 'status' => 'active', 'recentPrices' => collect(),
                'phaseCount' => count(MedicineCatalogueSyncService::phases()),
            ]);
        }

        $q = trim((string) $request->query('q', ''));
        $category = (string) $request->query('category', '');
        $source = (string) $request->query('source', '');
        $status = (string) $request->query('status', 'active');

        $query = MedicineCatalogueEntry::query();
        $this->applyFilters($query, $q, $category, $source, $status);
        $entries = $query->orderBy('brand_name')->orderBy('pack_size')->paginate(50)->withQueryString();

        $stats = [
            'total' => MedicineCatalogueEntry::count(),
            'active' => MedicineCatalogueEntry::where('is_active', true)->count(),
            'drap' => MedicineCatalogueEntry::where('source', MedicineCatalogueEntry::SOURCE_DRAP)->count(),
            'supplementary' => MedicineCatalogueEntry::where('source', '!=', MedicineCatalogueEntry::SOURCE_DRAP)->count(),
            'essential' => MedicineCatalogueEntry::where('category', MedicineCatalogueEntry::CATEGORY_ESSENTIAL)->count(),
            'low_price' => MedicineCatalogueEntry::where('category', MedicineCatalogueEntry::CATEGORY_LOW_PRICE)->count(),
            'linked_products' => Schema::hasColumn('products', 'medicine_catalogue_id')
                ? \App\Models\Product::whereNotNull('medicine_catalogue_id')->count() : 0,
            'pending_notices' => Schema::hasTable('medicine_price_notices')
                ? MedicinePriceNotice::where('status', MedicinePriceNotice::STATUS_PENDING)->count() : 0,
            'price_changes_30d' => MedicineCataloguePrice::whereNotNull('old_mrp')->where('created_at', '>=', now()->subDays(30))->count(),
        ];

        $run = MedicineCatalogueSync::latest('id')->first();
        $lastCompleted = MedicineCatalogueSync::where('state', 'completed')->latest('completed_at')->first();
        $recentPrices = MedicineCataloguePrice::with('entry')->whereNotNull('old_mrp')->orderByDesc('id')->limit(15)->get();

        return view('admin.medicine-catalogue', [
            'ready' => true,
            'entries' => $entries,
            'stats' => $stats,
            'run' => $run,
            'lastCompleted' => $lastCompleted,
            'q' => $q,
            'category' => $category,
            'source' => $source,
            'status' => $status,
            'recentPrices' => $recentPrices,
            'phaseCount' => count(MedicineCatalogueSyncService::phases()),
        ]);
    }

    private function applyFilters($query, string $q, string $category, string $source, string $status): void
    {
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('brand_name', 'like', $like)
                    ->orWhere('generic_name', 'like', $like)
                    ->orWhere('composition', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like)
                    ->orWhere('drap_reg_no', 'like', $like);
                if (ctype_digit($q)) {
                    $w->orWhere('drap_reg_no', str_pad($q, 6, '0', STR_PAD_LEFT));
                }
            });
        }
        if (in_array($category, [MedicineCatalogueEntry::CATEGORY_ESSENTIAL, MedicineCatalogueEntry::CATEGORY_LOW_PRICE, MedicineCatalogueEntry::CATEGORY_NORMAL], true)) {
            $query->where('category', $category);
        }
        if (in_array($source, [MedicineCatalogueEntry::SOURCE_DRAP, MedicineCatalogueEntry::SOURCE_MANUAL, MedicineCatalogueEntry::SOURCE_IMPORT], true)) {
            $query->where('source', $source);
        }
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }
    }

    /** JSON for the page's live progress poll. */
    public function syncStatus()
    {
        if (!MedicineCatalogueSyncService::tablesReady()) {
            return response()->json(['ready' => false, 'run' => null]);
        }
        $run = MedicineCatalogueSync::latest('id')->first();

        return response()->json([
            'ready' => true,
            'run' => $run?->toStatusArray(count(MedicineCatalogueSyncService::phases())),
            'total' => MedicineCatalogueEntry::count(),
        ]);
    }

    /** "Sync from DRAP" — idempotent: a second press returns the same run. */
    public function startSync(Request $request)
    {
        if (!MedicineCatalogueSyncService::tablesReady()) {
            return back()->with('error', 'Catalogue tables are missing — run migrations first.');
        }
        $before = MedicineCatalogueSync::active();
        $run = $this->sync->start('manual', auth('admin')->id());
        $this->adminLog($before && $before->id === $run->id ? 'Medicine catalogue sync already running' : 'Medicine catalogue sync started', $run->id);

        return back()->with('success', $before && $before->id === $run->id
            ? 'A DRAP sync is already running (run #' . $run->id . ') — progress continues below.'
            : 'DRAP sync queued as run #' . $run->id . '. It walks ~1,070 pages politely (about an hour) and resumes by itself after any interruption.');
    }

    public function cancelSync()
    {
        $run = MedicineCatalogueSync::active();
        if (!$run) {
            return back()->with('error', 'No active sync to cancel.');
        }
        $this->sync->requestCancel($run);
        $this->adminLog('Medicine catalogue sync cancel requested', $run->id);

        return back()->with('success', 'Cancel requested — the run stops after its current page. Rows already written stay.');
    }

    /** Inline edit of one row's parsed/descriptive fields (+ optional explicit MRP change). */
    public function update(Request $request, int $id)
    {
        $entry = MedicineCatalogueEntry::findOrFail($id);
        $data = $request->validate([
            'brand_name' => 'required|string|max:250',
            'generic_name' => 'nullable|string|max:250',
            'strength' => 'nullable|string|max:110',
            'dosage_form' => 'nullable|string|max:40',
            'manufacturer' => 'nullable|string|max:250',
            'pack_size' => 'nullable|string|max:160',
            'category' => 'nullable|in:essential,low_price,normal',
            'mrp' => 'nullable|numeric|min:0|max:99999999',
            'effective_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        $before = $entry->only(['brand_name', 'generic_name', 'strength', 'dosage_form', 'manufacturer', 'pack_size', 'category', 'mrp', 'effective_date', 'is_active']);

        $newMrp = array_key_exists('mrp', $data) && $data['mrp'] !== null && $data['mrp'] !== '' ? round((float) $data['mrp'], 2) : null;
        $oldMrp = $entry->mrp !== null ? round((float) $entry->mrp, 2) : null;
        unset($data['mrp']);
        $effective = !empty($data['effective_date']) ? substr((string) $data['effective_date'], 0, 10) : $entry->effective_date?->format('Y-m-d');
        $data['effective_date'] = $effective;
        $data['is_active'] = $request->boolean('is_active', (bool) $entry->is_active);

        $entry->fill($data);
        $entry->save();

        // An explicit MRP change by the admin goes through the SAME history +
        // notice path a DRAP change does — no silent overwrite anywhere.
        if ($newMrp !== null && ($oldMrp === null || abs($oldMrp - $newMrp) >= 0.005)) {
            $entry->forceFill(['mrp' => $newMrp, 'effective_date' => $effective])->save();
            $this->sync->recordPriceChange($entry, $oldMrp, $newMrp, $before['effective_date']?->format('Y-m-d'), $effective, MedicineCatalogueEntry::SOURCE_MANUAL, null);
        }

        $this->adminLog('Medicine catalogue row edited', $entry->id, ['before' => $before, 'after' => $entry->only(array_keys($before))]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'entry' => $entry->fresh()->toPickerArray()]);
        }

        return back()->with('success', 'Row #' . $entry->id . ' saved.');
    }

    /** Add one supplementary row by hand (source = manual). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'brand_name' => 'required|string|max:250',
            'composition' => 'nullable|string|max:2000',
            'manufacturer' => 'nullable|string|max:250',
            'drap_reg_no' => 'nullable|string|max:40',
            'pack_size' => 'nullable|string|max:160',
            'category' => 'nullable|in:essential,low_price,normal',
            'mrp' => 'nullable|numeric|min:0|max:99999999',
            'effective_date' => 'nullable|date',
        ]);
        $data['category'] = $data['category'] ?? MedicineCatalogueEntry::CATEGORY_NORMAL;
        $r = $this->sync->upsertRow($data, MedicineCatalogueEntry::SOURCE_MANUAL, null, true);
        $this->adminLog('Medicine catalogue row ' . $r['outcome'], $r['entry']->id);

        return back()->with('success', 'Row ' . $r['outcome'] . ': ' . $r['entry']->brand_name . ' (#' . $r['entry']->id . ').');
    }

    /**
     * Supplementary xlsx/csv import (distributor price lists etc.).
     * Never overwrites a DRAP-sourced MRP unless the admin ticked the box.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
            'overwrite_drap_mrp' => 'nullable|boolean',
        ]);
        $overwrite = $request->boolean('overwrite_drap_mrp');

        $path = $request->file('file')->getRealPath();
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        if ($sheet->getHighestDataRow() > 20001) {
            $spreadsheet->disconnectWorksheets();

            return back()->with('error', 'File too large — at most 20,000 rows per import.');
        }
        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();
        if (count($rows) < 2) {
            return back()->with('error', 'The file has no data rows.');
        }

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/[\x{FEFF}]/u', '', (string) $h))), $rows[0]);
        $find = function (array $aliases) use ($header) {
            foreach ($aliases as $a) {
                $i = array_search($a, $header, true);
                if ($i !== false) {
                    return $i;
                }
            }

            return false;
        };
        $idx = [
            'brand_name' => $find(['brand', 'brand name', 'name', 'product', 'product name', 'medicine', 'item']),
            'composition' => $find(['composition', 'generic / salt', 'generic/salt', 'generic', 'salt', 'formula']),
            'generic_name' => $find(['generic name', 'salt name']),
            'strength' => $find(['strength', 'potency']),
            'dosage_form' => $find(['dosage form', 'form']),
            'manufacturer' => $find(['manufacturer', 'company', 'maker', 'mfg']),
            'drap_reg_no' => $find(['drap reg no', 'drap reg. no', 'reg no', 'reg. no', 'registration no', 'registration number', 'drap_reg_no', 'reg no.']),
            'pack_size' => $find(['pack size', 'pack', 'packing', 'pack_size']),
            'category' => $find(['category', 'drap category']),
            'mrp' => $find(['mrp', 'mrp (rs)', 'price', 'retail price', 'trade price']),
            'effective_date' => $find(['effective date', 'effective from', 'w.e.f', 'wef', 'date']),
        ];
        if ($idx['brand_name'] === false) {
            return back()->with('error', 'Brand/Name column not found. Expected headers: Brand, Composition, Manufacturer, DRAP Reg No, Pack Size, MRP, Effective Date.');
        }

        $created = 0; $updated = 0; $skipped = 0; $errors = [];
        for ($i = 1; $i < count($rows); $i++) {
            $data = $rows[$i];
            $get = fn (string $k) => $idx[$k] !== false ? trim((string) ($data[$idx[$k]] ?? '')) : '';
            $brand = $get('brand_name');
            if ($brand === '') {
                $skipped++;
                continue;
            }
            $mrpRaw = $get('mrp');
            $mrp = $mrpRaw !== '' ? (float) preg_replace('/[^0-9.\-]/', '', $mrpRaw) : null;
            if ($mrp !== null && $mrp <= 0) {
                $mrp = null;
            }
            $eff = $get('effective_date');
            $effective = null;
            if ($eff !== '') {
                $ts = strtotime($eff);
                if (!$ts && preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $eff, $dm)) {
                    $ts = strtotime(sprintf('%04d-%02d-%02d', strlen($dm[3]) === 2 ? 2000 + (int) $dm[3] : (int) $dm[3], (int) $dm[2], (int) $dm[1]));
                }
                $effective = $ts ? date('Y-m-d', $ts) : null;
            }
            $row = [
                'brand_name' => $brand,
                'composition' => $get('composition') ?: null,
                'generic_name' => $get('generic_name') ?: null,
                'strength' => $get('strength') ?: null,
                'dosage_form' => strtolower($get('dosage_form')) ?: null,
                'manufacturer' => $get('manufacturer') ?: null,
                'drap_reg_no' => $get('drap_reg_no') ?: null,
                'pack_size' => $get('pack_size') ?: null,
                'category' => $get('category') ?: null,
                'mrp' => $mrp,
                'effective_date' => $effective,
            ];
            if ($row['dosage_form'] !== null && !in_array($row['dosage_form'], \App\Models\Product::DOSAGE_FORMS, true)) {
                $row['dosage_form'] = (new MedicineCompositionParser())->detectForm($row['dosage_form']);
            }
            try {
                $r = $this->sync->upsertRow($row, MedicineCatalogueEntry::SOURCE_IMPORT, null, $overwrite);
                if ($r['outcome'] === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                if (count($errors) < 5) {
                    $errors[] = 'Row ' . ($i + 1) . ': ' . $e->getMessage();
                }
            }
        }

        $this->adminLog('Medicine catalogue import', null, ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'overwrite_drap_mrp' => $overwrite]);
        $msg = "Import done: {$created} new, {$updated} updated, {$skipped} skipped." . ($overwrite ? '' : ' DRAP-sourced MRPs were left untouched (overwrite box not ticked).');
        if ($errors) {
            $msg .= ' Issues: ' . implode('; ', $errors);
        }

        return back()->with($created + $updated > 0 ? 'success' : 'error', $msg);
    }

    /** Full xlsx export of the current filter. */
    public function export(Request $request)
    {
        $query = MedicineCatalogueEntry::query();
        $this->applyFilters($query, trim((string) $request->query('q', '')), (string) $request->query('category', ''), (string) $request->query('source', ''), (string) $request->query('status', 'active'));

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Medicine Catalogue');
        $headers = ['ID', 'Brand', 'Composition', 'Generic Name', 'Strength', 'Dosage Form', 'Manufacturer', 'Licence', 'DRAP Reg No', 'Category', 'Pack Size', 'MRP', 'Effective Date', 'Source', 'Active'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:O1')->getFont()->setBold(true);
        $sheet->getStyle('I:I')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
        foreach (['B' => 36, 'C' => 44, 'D' => 28, 'E' => 14, 'F' => 12, 'G' => 32, 'H' => 14, 'I' => 12, 'J' => 11, 'K' => 16, 'L' => 12, 'M' => 14] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
        $r = 2;
        $query->orderBy('brand_name')->chunkById(1000, function ($chunk) use ($sheet, &$r) {
            foreach ($chunk as $e) {
                $sheet->setCellValue('A' . $r, $e->id);
                $sheet->setCellValue('B' . $r, $e->brand_name);
                $sheet->setCellValue('C' . $r, $e->composition);
                $sheet->setCellValue('D' . $r, $e->generic_name);
                $sheet->setCellValue('E' . $r, $e->strength);
                $sheet->setCellValue('F' . $r, $e->dosage_form);
                $sheet->setCellValue('G' . $r, $e->manufacturer);
                $sheet->setCellValue('H' . $r, $e->manufacturer_licence);
                $sheet->setCellValueExplicit('I' . $r, (string) $e->drap_reg_no, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('J' . $r, MedicineCatalogueEntry::categoryLabel($e->category));
                $sheet->setCellValue('K' . $r, $e->pack_size);
                $sheet->setCellValue('L' . $r, $e->mrp !== null ? (float) $e->mrp : '');
                $sheet->setCellValue('M' . $r, $e->effective_date?->format('Y-m-d'));
                $sheet->setCellValue('N' . $r, $e->source);
                $sheet->setCellValue('O' . $r, $e->is_active ? 'Yes' : 'No');
                $r++;
            }
        });

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'medicine_catalogue_' . now()->format('Ymd_Hi') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function adminLog(string $action, ?int $entityId = null, array $details = []): void
    {
        try {
            AdminAuditLog::log(auth('admin')->id(), $action, 'MedicineCatalogue', $entityId, $details);
        } catch (\Throwable $e) {
            // audit must never block the action
        }
    }
}
