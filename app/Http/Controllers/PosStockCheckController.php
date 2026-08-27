<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosProduct;
use App\Models\StockCheck;
use App\Models\StockCheckLine;
use App\Services\AuditLogService;
use App\Services\BranchStockService;
use App\Services\StockCheckAlreadyOpenException;
use App\Services\StockCheckService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Physical Stock Check — the counting sheet the shop fills in at day close.
 *
 * Flow: open a sheet (expected quantities frozen) → count on screen or in Excel
 * → review the gaps → post, which corrects live stock and leaves a ledger trail.
 */
class PosStockCheckController extends Controller
{
    /** Excel sheets are capped so one upload cannot stall the server. */
    private const IMPORT_ROW_CAP = 5001;

    /* ------------------------------------------------------------------ */
    /* Guards                                                              */
    /* ------------------------------------------------------------------ */

    private function boot(): array
    {
        $companyId = (int) app('currentCompanyId');
        $company = Company::find($companyId);

        if (!$company || !$company->inventory_enabled) {
            abort(redirect()->route('pos.features')
                ->with('error', __('pos.stock_check_needs_inventory')));
        }

        if (!StockCheckService::ready()) {
            abort(redirect()->route('pos.inventory.dashboard')
                ->with('error', __('pos.stock_check_not_ready')));
        }

        BranchStockService::healLegacyRows($companyId);

        return [$companyId, $company];
    }

    /** Counting corrects real stock, so it is an owner/manager job. */
    private function assertNotCashier(): void
    {
        $user = auth('pos')->user();
        if ($user && $user->posCashierBlocked()) {
            abort(403, __('pos.access_denied'));
        }
    }

    private function branchView(int $companyId): array
    {
        $branches = BranchStockService::branches($companyId);
        return [
            'branches' => BranchStockService::actorBranches($companyId),
            'multiBranch' => $branches->isNotEmpty(),
            'canTransfer' => BranchStockService::canTransfer($companyId),
            'activeBranchId' => BranchStockService::viewBranchId($companyId),
            'activeBranchName' => BranchStockService::branchName($companyId, BranchStockService::viewBranchId($companyId)),
            'allBranches' => BranchStockService::viewingAllBranches($companyId),
            'branchNames' => $branches->pluck('name', 'id')->all(),
        ];
    }

    /**
     * Find a check this user is actually allowed to touch.
     *
     * Company scope alone is not enough: a manager confined to one shop must
     * not be able to read — let alone post — another shop's count by guessing
     * an id. Owners and unconfined users keep company-wide reach.
     */
    private function findCheck(int $companyId, int $id): StockCheck
    {
        $check = StockCheck::where('company_id', $companyId)->findOrFail($id);

        if ($check->branch_id !== null && !BranchStockService::actorCanUse($companyId, (int) $check->branch_id)) {
            abort(403, __('pos.access_denied'));
        }

        return $check;
    }

    /* ------------------------------------------------------------------ */
    /* Listing                                                             */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        [$companyId, $company] = $this->boot();
        $branchView = $this->branchView($companyId);

        $query = StockCheck::where('company_id', $companyId)->with('branch');
        BranchStockService::applyViewFilter($query, $companyId);

        if ($request->filled('status') && in_array($request->status, ['counting', 'completed', 'cancelled'], true)) {
            $query->where('status', $request->status);
        }

        $checks = $query->orderByDesc('id')->paginate(20)->appends($request->all());

        // One open sheet at a time keeps the shop from counting into two places.
        // Scoped to the branches this user can reach, so a confined manager is
        // never handed a link to a shop they cannot open.
        $openQuery = StockCheck::where('company_id', $companyId)
            ->where('status', StockCheck::STATUS_COUNTING);
        BranchStockService::applyViewFilter($openQuery, $companyId);
        $openCheck = $openQuery->orderByDesc('id')->first();

        return view('pos.inventory.stock-check.index', array_merge($branchView, compact(
            'company', 'checks', 'openCheck'
        )));
    }

    /* ------------------------------------------------------------------ */
    /* Opening a sheet                                                     */
    /* ------------------------------------------------------------------ */

    public function create()
    {
        [$companyId, $company] = $this->boot();
        $this->assertNotCashier();
        $branchView = $this->branchView($companyId);

        $categories = PosProduct::where('company_id', $companyId)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $hasIngredients = StockCheckService::ingredientsAvailable($companyId);

        $openCheck = StockCheck::where('company_id', $companyId)
            ->where('status', StockCheck::STATUS_COUNTING)
            ->orderByDesc('id')
            ->first();

        return view('pos.inventory.stock-check.create', array_merge($branchView, compact(
            'company', 'categories', 'hasIngredients', 'openCheck'
        )));
    }

    public function store(Request $request)
    {
        [$companyId, $company] = $this->boot();
        $this->assertNotCashier();

        $request->validate([
            'scope' => 'required|in:products,ingredients,both',
            'branch_id' => 'nullable|integer',
            'category' => 'nullable|string|max:100',
            'only_in_stock' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        $picked = $request->filled('branch_id') ? (int) $request->branch_id : null;
        if ($picked !== null && !BranchStockService::actorCanUse($companyId, $picked)) {
            abort(403, __('pos.access_denied'));
        }
        // A physical count always happens at ONE physical place, so an
        // all-branches view still resolves to a concrete branch to count.
        $branchId = BranchStockService::writeBranchId($companyId, $picked);

        $scope = $request->scope;
        if ($scope !== StockCheck::SCOPE_PRODUCTS && !StockCheckService::ingredientsAvailable($companyId)) {
            // Asking for raw materials in a shop that has none would produce an
            // empty sheet; fall back rather than hand back a blank page.
            $scope = StockCheck::SCOPE_PRODUCTS;
        }

        try {
            $check = StockCheckService::open($companyId, $branchId, $scope, (int) auth('pos')->id(), [
                'category' => $request->input('category') ?: null,
                'only_in_stock' => $request->boolean('only_in_stock'),
                'notes' => $request->input('notes'),
            ]);
        } catch (StockCheckAlreadyOpenException $e) {
            // Someone else opened one for this branch a moment ago — send the
            // manager to that sheet instead of starting a rival snapshot.
            $existing = StockCheck::where('company_id', $companyId)
                ->where('status', StockCheck::STATUS_COUNTING)
                ->when($branchId === null,
                    fn ($q) => $q->whereNull('branch_id'),
                    fn ($q) => $q->where('branch_id', $branchId))
                ->orderByDesc('id')->first();

            return $existing
                ? redirect()->route('pos.inventory.stock-check.show', $existing->id)
                    ->with('error', __('pos.stock_check_already_open', ['code' => $existing->code]))
                : back()->withInput()->with('error', __('pos.stock_check_no_items'));
        }

        if ((int) $check->total_lines === 0) {
            $check->delete();
            return back()->withInput()->with('error', __('pos.stock_check_no_items'));
        }

        AuditLogService::log(
            'pos_stock_check_opened', 'StockCheck', $check->id, null,
            ['code' => $check->code, 'scope' => $check->scope, 'lines' => $check->total_lines],
            $companyId, auth('pos')->id()
        );

        return redirect()->route('pos.inventory.stock-check.show', $check->id)
            ->with('success', __('pos.stock_check_opened', ['code' => $check->code, 'count' => $check->total_lines]));
    }

    /* ------------------------------------------------------------------ */
    /* The count sheet                                                     */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, int $id)
    {
        [$companyId, $company] = $this->boot();
        $check = $this->findCheck($companyId, $id);
        $branchView = $this->branchView($companyId);

        $query = StockCheckLine::where('stock_check_id', $check->id);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('item_name', \App\Helpers\DbCompat::like(), "%{$search}%")
                  ->orWhere('item_code', \App\Helpers\DbCompat::like(), "%{$search}%");
            });
        }

        $filter = $request->input('filter');
        if ($filter === 'pending') {
            $query->whereNull('counted_quantity');
        } elseif ($filter === 'variance') {
            $query->whereNotNull('counted_quantity')->where('variance', '!=', 0);
        } elseif ($filter === 'short') {
            $query->whereNotNull('counted_quantity')->where('variance', '<', 0);
        } elseif ($filter === 'excess') {
            $query->whereNotNull('counted_quantity')->where('variance', '>', 0);
        }

        // Counting runs top-to-bottom on a paper-like list, so keep the order
        // stable: raw materials after menu items, each alphabetical.
        $lines = $query->orderBy('item_type')->orderBy('item_name')
            ->paginate(100)->appends($request->all());

        $branchLabel = BranchStockService::branchName($companyId, $check->branch_id);

        return view('pos.inventory.stock-check.show', array_merge($branchView, compact(
            'company', 'check', 'lines', 'branchLabel', 'filter'
        )));
    }

    /** Save the counts typed on screen. Stays on the same page/filter. */
    public function saveCounts(Request $request, int $id)
    {
        [$companyId] = $this->boot();
        $this->assertNotCashier();
        $check = $this->findCheck($companyId, $id);

        if (!$check->isOpen()) {
            return back()->with('error', __('pos.stock_check_closed'));
        }

        $input = $request->input('lines', []);
        if (!is_array($input)) $input = [];

        $changed = StockCheckService::saveCounts($check, $input, (int) auth('pos')->id());

        return back()->with('success', __('pos.stock_check_saved', ['count' => $changed]));
    }

    /* ------------------------------------------------------------------ */
    /* Excel round-trip                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Download the sheet as a real .xlsx — the client's own workflow: system
     * prints "should be", the counter writes the physical number beside it.
     */
    public function downloadSheet(int $id)
    {
        [$companyId, $company] = $this->boot();
        $check = $this->findCheck($companyId, $id);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Stock Check');

        $headers = [
            'Line ID', 'Type', 'Code', 'Item', 'Unit',
            'System Qty (Should Be)', 'Physical Count', 'Reason', 'Notes',
        ];
        $sheet->fromArray($headers, null, 'A1');

        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('0A4D5C');
        $sheet->getStyle('A1:I1')->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:I1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $lines = StockCheckLine::where('stock_check_id', $check->id)
            ->orderBy('item_type')->orderBy('item_name')->get();

        $row = 2;
        foreach ($lines as $line) {
            $sheet->setCellValue('A' . $row, $line->id);
            $sheet->setCellValue('B' . $row, $line->item_type === StockCheckLine::TYPE_INGREDIENT ? 'Raw Material' : 'Item');
            // Codes are text: a barcode must never become 8.9E+12.
            $sheet->setCellValueExplicit('C' . $row, (string) ($line->item_code ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue('D' . $row, (string) $line->item_name);
            $sheet->setCellValue('E' . $row, (string) ($line->unit ?? ''));
            $sheet->setCellValue('F' . $row, (float) $line->expected_quantity);
            if ($line->counted_quantity !== null) {
                $sheet->setCellValue('G' . $row, (float) $line->counted_quantity);
            }
            $sheet->setCellValue('H' . $row, (string) ($line->reason ?? ''));
            $sheet->setCellValue('I' . $row, (string) ($line->notes ?? ''));
            $row++;
        }

        $lastRow = max(2, $row - 1);
        $sheet->getStyle('C2:C' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        // The "should be" column is the system's word — lock it visually so a
        // counter does not overwrite the very number they are checking against.
        $sheet->getStyle('F2:F' . $lastRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFF6F7');
        $sheet->getStyle('G2:G' . $lastRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF8E1');
        $sheet->getStyle('A2:A' . $lastRow)->getFont()->getColor()->setRGB('9CA3AF');

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $writer = new Xlsx($spreadsheet);
        $filename = 'Stock-Check-' . $check->code . '-' . now()->format('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /** Read the filled-in sheet back and apply the physical counts. */
    public function importSheet(Request $request, int $id)
    {
        [$companyId] = $this->boot();
        $this->assertNotCashier();
        $check = $this->findCheck($companyId, $id);

        if (!$check->isOpen()) {
            return back()->with('error', __('pos.stock_check_closed'));
        }

        $request->validate([
            'sheet' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120',
        ]);

        try {
            $rows = $this->readRows($request->file('sheet'));
        } catch (\Throwable $e) {
            return back()->with('error', __('pos.stock_check_import_unreadable'));
        }

        if (count($rows) < 2) {
            return back()->with('error', __('pos.stock_check_import_empty'));
        }
        if (count($rows) > self::IMPORT_ROW_CAP) {
            return back()->with('error', __('pos.stock_check_import_too_big', ['cap' => self::IMPORT_ROW_CAP - 1]));
        }

        $header = array_map(
            fn ($h) => strtolower(trim(preg_replace('/^\x{FEFF}/u', '', (string) $h))),
            $rows[0]
        );
        $col = function (array $aliases) use ($header) {
            foreach ($aliases as $alias) {
                $idx = array_search($alias, $header, true);
                if ($idx !== false) return $idx;
            }
            return null;
        };

        $idCol = $col(['line id', 'line_id', 'id']);
        $countCol = $col(['physical count', 'physical_count', 'counted', 'physical', 'count']);
        $codeCol = $col(['code', 'sku', 'barcode']);
        $reasonCol = $col(['reason']);
        $notesCol = $col(['notes', 'note']);

        if ($countCol === null || ($idCol === null && $codeCol === null)) {
            return back()->with('error', __('pos.stock_check_import_bad_header'));
        }

        // Matching by code needs a lookup; ids are exact and always preferred.
        $byCode = [];
        if ($idCol === null) {
            $byCode = StockCheckLine::where('stock_check_id', $check->id)
                ->whereNotNull('item_code')
                ->get(['id', 'item_code'])
                ->reduce(function ($carry, $l) {
                    $key = mb_strtolower(trim((string) $l->item_code));
                    // A duplicated code is ambiguous — refuse it rather than
                    // guess which shelf the number belongs to.
                    $carry[$key] = array_key_exists($key, $carry) ? null : $l->id;
                    return $carry;
                }, []);
        }

        $payload = [];
        $unmatched = 0;
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $rawCount = $countCol !== null ? ($row[$countCol] ?? null) : null;
            if ($rawCount === null || trim((string) $rawCount) === '') continue;
            if (!is_numeric(trim((string) $rawCount))) { $unmatched++; continue; }

            $lineId = null;
            if ($idCol !== null && !empty($row[$idCol])) {
                $lineId = (int) $row[$idCol];
            } elseif ($codeCol !== null && !empty($row[$codeCol])) {
                $lineId = $byCode[mb_strtolower(trim((string) $row[$codeCol]))] ?? null;
            }
            if (!$lineId) { $unmatched++; continue; }

            $payload[$lineId] = [
                'counted' => trim((string) $rawCount),
                'reason' => $reasonCol !== null ? trim((string) ($row[$reasonCol] ?? '')) : null,
                'notes' => $notesCol !== null ? trim((string) ($row[$notesCol] ?? '')) : null,
            ];
        }

        if (empty($payload)) {
            return back()->with('error', __('pos.stock_check_import_nothing'));
        }

        $changed = StockCheckService::saveCounts($check, $payload, (int) auth('pos')->id());

        $message = __('pos.stock_check_imported', ['count' => $changed]);
        if ($unmatched > 0) {
            $message .= ' ' . __('pos.stock_check_import_skipped', ['count' => $unmatched]);
        }

        return redirect()->route('pos.inventory.stock-check.show', $check->id)->with('success', $message);
    }

    /** Read xlsx/csv into a plain array of rows, capped. */
    private function readRows($file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            if ($sheet->getHighestDataRow() > self::IMPORT_ROW_CAP) {
                $spreadsheet->disconnectWorksheets();
                return array_fill(0, self::IMPORT_ROW_CAP + 1, []);
            }
            $rows = $sheet->toArray(null, true, false, false);
            $spreadsheet->disconnectWorksheets();
            return $rows;
        }

        $rows = [];
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) return [];
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
            if (count($rows) > self::IMPORT_ROW_CAP) break;
        }
        fclose($handle);
        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* Closing out                                                         */
    /* ------------------------------------------------------------------ */

    public function post(Request $request, int $id)
    {
        [$companyId] = $this->boot();
        $this->assertNotCashier();
        $check = $this->findCheck($companyId, $id);

        if (!$check->isOpen()) {
            return back()->with('error', __('pos.stock_check_closed'));
        }
        if ((int) $check->counted_lines === 0) {
            return back()->with('error', __('pos.stock_check_nothing_counted'));
        }

        $result = StockCheckService::post($check, (int) auth('pos')->id());

        AuditLogService::log(
            'pos_stock_check_posted', 'StockCheck', $check->id, null,
            [
                'code' => $check->code,
                'applied' => $result['applied'],
                'short_value' => $check->fresh()->short_value,
                'excess_value' => $check->fresh()->excess_value,
            ],
            $companyId, auth('pos')->id()
        );

        return redirect()->route('pos.inventory.stock-check.show', $check->id)
            ->with('success', __('pos.stock_check_posted', ['count' => $result['applied']]));
    }

    public function cancel(Request $request, int $id)
    {
        [$companyId] = $this->boot();
        $this->assertNotCashier();
        $check = $this->findCheck($companyId, $id);

        if (!$check->isOpen()) {
            return back()->with('error', __('pos.stock_check_closed'));
        }

        $check->update(['status' => StockCheck::STATUS_CANCELLED]);

        AuditLogService::log(
            'pos_stock_check_cancelled', 'StockCheck', $check->id, null,
            ['code' => $check->code], $companyId, auth('pos')->id()
        );

        return redirect()->route('pos.inventory.stock-check.index')
            ->with('success', __('pos.stock_check_cancelled_msg', ['code' => $check->code]));
    }

    /* ------------------------------------------------------------------ */
    /* Variance report                                                     */
    /* ------------------------------------------------------------------ */

    public function pdf(int $id)
    {
        [$companyId, $company] = $this->boot();
        $check = $this->findCheck($companyId, $id);

        // The report is the point of the whole exercise: only the gaps.
        $lines = StockCheckLine::where('stock_check_id', $check->id)
            ->whereNotNull('counted_quantity')
            ->where('variance', '!=', 0)
            ->orderBy('variance')
            ->get();

        $branchLabel = BranchStockService::branchName($companyId, $check->branch_id);

        $isUrdu = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT;
        $data = compact('company', 'check', 'lines', 'branchLabel') + ['pdfUrdu' => $isUrdu];
        $filename = 'Stock-Check-' . $check->code . '.pdf';

        if ($isUrdu) {
            try {
                return \App\Support\MpdfRenderer::render(
                    'pos.inventory.stock-check.pdf', $data, 'a4-report', $filename, false, 'portrait'
                );
            } catch (\Throwable $e) {
                \Log::warning("Stock check PDF (mPDF) failed [{$filename}]: " . $e->getMessage());
            }
        }

        \App\Support\PosLocale::applyPdfSafeLocale();
        $data['pdfUrdu'] = false;

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pos.inventory.stock-check.pdf', $data)
            ->setPaper('a4', 'portrait')
            ->download($filename);
    }
}
