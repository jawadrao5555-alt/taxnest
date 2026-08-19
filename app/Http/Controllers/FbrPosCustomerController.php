<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\PosCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Task 1260: FBR POS Customers page — FBR twin of the PRA /pos/customers page
 * (PosController customer methods). Same shared pos_customers table (always
 * company-scoped), but ALL purchase stats and history read fbr_pos_transactions
 * — never PRA data. The sale screen's quick-add / search / phone-lookup APIs
 * (FbrPosController apiCustomer*) are untouched; this page is the standalone
 * management surface.
 *
 * Access (mirrors PRA behaviour + khata-page convention): every fbrpos role
 * may VIEW the list and ADD a customer; manage actions (edit / delete /
 * toggle / export / import / history) are admin+manager only via
 * assertNotCashier — pos_cashier and local_viewer get a true 403.
 */
class FbrPosCustomerController extends Controller
{
    private function user()
    {
        return Auth::guard('fbrpos')->user();
    }

    private function isCashier(): bool
    {
        return in_array($this->user()->pos_role ?? '', ['pos_cashier', 'local_viewer'], true);
    }

    private function assertNotCashier(): void
    {
        if ($this->isCashier()) {
            abort(403, 'Sirf admin/manager customers manage kar sakte hain.');
        }
    }

    public function index(Request $request)
    {
        $companyId = app('currentCompanyId');
        // PRA ZFC lesson (Aug 2026): never render every row — server-side
        // search + pagination (100/page) so any phone/name is findable
        // regardless of page.
        $q = trim((string) $request->query('q', ''));
        $query = PosCustomer::where('company_id', $companyId);
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                $w->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('cnic', 'like', $like)
                    ->orWhere('ntn', 'like', $like);
            });
        }
        $totalCount = PosCustomer::where('company_id', $companyId)->count();
        $customers = $query->orderBy('name')->paginate(100)->withQueryString();
        $isCashier = $this->isCashier();
        return view('fbr-pos.customers', compact('customers', 'isCashier', 'q', 'totalCount'));
    }

    public function store(Request $request)
    {
        $companyId = app('currentCompanyId');
        // Name is OPTIONAL when a phone is given (PRA owner rule, Jul 2026):
        // blank name = the phone number doubles as the display name.
        $request->validate([
            'name' => 'nullable|required_without:phone|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'type' => 'required|in:registered,unregistered',
        ]);

        $customer = PosCustomer::create(array_merge($request->only(['name', 'email', 'phone', 'address', 'city', 'ntn', 'cnic', 'type']), [
            'company_id' => $companyId,
            'name' => trim((string) $request->name) !== '' ? trim($request->name) : $request->phone,
        ]));

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'customer' => ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone]]);
        }

        return back()->with('success', __('pos.customer_added_success'));
    }

    public function update(Request $request, $id)
    {
        $this->assertNotCashier();
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'type' => 'required|in:registered,unregistered',
        ]);

        $customer->update($request->only(['name', 'email', 'phone', 'address', 'city', 'ntn', 'cnic', 'type']));
        return back()->with('success', __('pos.customer_updated_success'));
    }

    public function destroy($id)
    {
        $this->assertNotCashier();
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $customer->delete();
        return back()->with('success', __('pos.customer_deleted_success'));
    }

    public function toggle($id)
    {
        $this->assertNotCashier();
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $customer->update(['is_active' => !$customer->is_active]);
        return back()->with('success', $customer->is_active ? __('pos.customer_activated') : __('pos.customer_deactivated'));
    }

    /**
     * Neutralize CSV formula-injection: cells starting with = + - @ (or a
     * leading tab/CR) are prefixed with a single quote so spreadsheets treat
     * them as text instead of executing them as a formula.
     */
    private function csvSafe($value)
    {
        $s = (string) $value;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * Export the company's customers to a CSV (streamed). Order/spend totals
     * come from FBR POS transactions (customer_id OR phone, no double-count) —
     * same matching rule as the history view.
     */
    public function export()
    {
        $this->assertNotCashier();
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customers = PosCustomer::where('company_id', $companyId)->orderBy('name')->get();

        // Aggregate by linked id, then by phone for rows WITHOUT a linked id
        // (so a sale counted by id is never also counted by phone).
        $aggById = FbrPosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as cnt, SUM(total_amount) as spent')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $aggByPhone = FbrPosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereNull('customer_id')
            ->whereNotNull('customer_phone')
            ->selectRaw('customer_phone, COUNT(*) as cnt, SUM(total_amount) as spent')
            ->groupBy('customer_phone')
            ->get()
            ->keyBy('customer_phone');

        $filename = 'FBR_POS_Customers_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($customers, $aggById, $aggByPhone, $company) {
            $file = fopen('php://output', 'w');
            // UTF-8 BOM so Excel renders Urdu / special chars correctly.
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, [$this->csvSafe('FBR POS Customers — ' . ($company->name ?? ''))]);
            fputcsv($file, ['Generated: ' . now()->format('d M Y H:i')]);
            fputcsv($file, []);
            fputcsv($file, ['Name', 'Phone', 'Email', 'CNIC', 'NTN', 'City', 'Address', 'Type', 'Status', 'Total Orders', 'Total Spent']);
            foreach ($customers as $c) {
                $byId = $aggById[$c->id] ?? null;
                $byPhone = $c->phone ? ($aggByPhone[$c->phone] ?? null) : null;
                $cnt = (int) ($byId->cnt ?? 0) + (int) ($byPhone->cnt ?? 0);
                $spent = (float) ($byId->spent ?? 0) + (float) ($byPhone->spent ?? 0);
                fputcsv($file, [
                    $this->csvSafe($c->name),
                    $this->csvSafe($c->phone),
                    $this->csvSafe($c->email),
                    $this->csvSafe($c->cnic),
                    $this->csvSafe($c->ntn),
                    $this->csvSafe($c->city),
                    $this->csvSafe($c->address),
                    ucfirst($c->type),
                    $c->is_active ? 'Active' : 'Inactive',
                    $cnt,
                    number_format($spent, 2, '.', ''),
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Download a blank CSV template (with one example row) for customer import.
     */
    public function template()
    {
        $this->assertNotCashier();
        $filename = 'FBR_POS_Customers_Import_Template.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];
        $callback = function () {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Name', 'Phone', 'Email', 'CNIC', 'NTN', 'City', 'Address', 'Type']);
            fputcsv($file, ['Ahmed Khan', '03001234567', 'ahmed@example.com', '35201-1234567-1', '1234567-8', 'Lahore', '123 Main Street', 'unregistered']);
            fclose($file);
        };
        return response()->stream($callback, 200, $headers);
    }

    /**
     * Import customers from an uploaded CSV. Forces company_id, dedupes by
     * phone then CNIC within the company (updates existing), skips invalid rows.
     * Line-for-line port of PosController::importCustomers.
     */
    public function import(Request $request)
    {
        $this->assertNotCashier();
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $companyId = app('currentCompanyId');
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->with('error', __('pos.customer_import_could_not_read'));
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return back()->with('error', __('pos.customer_import_empty'));
        }
        // Strip a UTF-8 BOM that Excel may prepend to the first header cell.
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        $map = [];
        foreach ($header as $i => $col) {
            $key = strtolower(trim(str_replace([' ', '_', '-'], '', (string) $col)));
            if ($key !== '') {
                $map[$key] = $i;
            }
        }

        $get = function ($row, $keys) use ($map) {
            foreach ((array) $keys as $k) {
                if (isset($map[$k]) && isset($row[$map[$k]])) {
                    return trim((string) $row[$map[$k]]);
                }
            }
            return null;
        };

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $rowNum = 1;
        $errors = [];

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                    continue; // wholly blank line
                }

                $name = $get($row, ['name', 'customername', 'fullname']);
                if (empty($name)) {
                    $skipped++;
                    if (count($errors) < 10) $errors[] = "Row {$rowNum}: missing name — skipped.";
                    continue;
                }

                // Truncate per column length so a long cell never throws a
                // QueryException that rolls back the entire import.
                $name = mb_substr($name, 0, 255);
                $phone = ($v = $get($row, ['phone', 'mobile', 'contact', 'phonenumber'])) !== null && $v !== '' ? mb_substr($v, 0, 20) : null;
                $cnic = ($v = $get($row, ['cnic'])) !== null && $v !== '' ? mb_substr($v, 0, 20) : null;
                $email = ($v = $get($row, ['email'])) !== null && $v !== '' ? mb_substr($v, 0, 255) : null;
                $ntn = ($v = $get($row, ['ntn'])) !== null && $v !== '' ? mb_substr($v, 0, 50) : null;
                $city = ($v = $get($row, ['city'])) !== null && $v !== '' ? mb_substr($v, 0, 100) : null;
                $address = ($v = $get($row, ['address'])) !== null && $v !== '' ? mb_substr($v, 0, 500) : null;

                // Only treat type as authoritative when the cell actually has a value,
                // so a partial CSV never silently flips registered → unregistered.
                $typeRaw = $get($row, ['type']);
                $hasType = $typeRaw !== null && trim($typeRaw) !== '';
                $type = strtolower((string) $typeRaw) === 'registered' ? 'registered' : 'unregistered';

                $existing = null;
                if (!empty($phone)) {
                    $existing = PosCustomer::where('company_id', $companyId)->where('phone', $phone)->first();
                }
                if (!$existing && !empty($cnic)) {
                    $existing = PosCustomer::where('company_id', $companyId)->where('cnic', $cnic)->first();
                }

                $fields = [
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'cnic' => $cnic,
                    'ntn' => $ntn,
                    'city' => $city,
                    'address' => $address,
                ];

                if ($existing) {
                    // Non-destructive: only overwrite fields the CSV actually carries,
                    // so blank cells never null out existing data.
                    $updateData = [];
                    foreach ($fields as $k => $val) {
                        if ($val !== null && $val !== '') {
                            $updateData[$k] = $val;
                        }
                    }
                    if ($hasType) {
                        $updateData['type'] = $type;
                    }
                    if (!empty($updateData)) {
                        $existing->update($updateData);
                    }
                    $updated++;
                } else {
                    PosCustomer::create(array_merge($fields, [
                        'type' => $hasType ? $type : 'unregistered',
                        'company_id' => $companyId,
                        'is_active' => true,
                    ]));
                    $imported++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            \Log::error('FBR POS customer import failed', ['company_id' => $companyId, 'error' => $e->getMessage()]);
            return back()->with('error', __('pos.customer_import_failed'));
        }
        fclose($handle);

        $msg = __('pos.customer_import_complete', ['added' => $imported, 'updated' => $updated, 'skipped' => ($skipped ? __('pos.customer_import_skipped_part', ['count' => $skipped]) : '')]);
        return back()->with('success', $msg)->with('import_errors', $errors);
    }

    /**
     * Resolve a customer's completed FBR transactions, matching by id OR phone
     * (the sale screen records customer_phone even without a linked id).
     * No archive/snapshot layer here — FBR POS has no day-close purge of
     * completed bills, so live rows ARE the full history.
     */
    private function customerTransactions($companyId, PosCustomer $customer)
    {
        return FbrPosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id);
                if (!empty($customer->phone)) {
                    $q->orWhere('customer_phone', $customer->phone);
                }
            })
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Per-customer purchase history page (FBR bills only).
     */
    public function history($id)
    {
        $this->assertNotCashier();
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);

        $transactions = $this->customerTransactions($companyId, $customer);
        $totalSpent = $transactions->sum('total_amount');
        $totalOrders = $transactions->count();
        $avgOrder = $totalOrders > 0 ? $totalSpent / $totalOrders : 0;
        $lastOrder = $transactions->first();

        return view('fbr-pos.customer-history', compact('company', 'customer', 'transactions', 'totalSpent', 'totalOrders', 'avgOrder', 'lastOrder'));
    }

    /**
     * Download a single customer's purchase history as CSV.
     */
    public function historyExport($id)
    {
        $this->assertNotCashier();
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $transactions = $this->customerTransactions($companyId, $customer);

        $filename = 'Customer_History_' . preg_replace('/[^A-Za-z0-9]+/', '_', $customer->name) . '_' . now()->format('Ymd') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($transactions, $customer, $company) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, [$this->csvSafe('Customer Purchase History — ' . ($company->name ?? ''))]);
            fputcsv($file, [$this->csvSafe('Customer: ' . $customer->name), 'Phone: ' . ($customer->phone ?: '—')]);
            fputcsv($file, ['Generated: ' . now()->format('d M Y H:i')]);
            fputcsv($file, []);
            fputcsv($file, ['Date', 'Invoice #', 'Mode', 'Payment', 'Subtotal', 'Discount', 'Tax', 'Total']);
            foreach ($transactions as $t) {
                fputcsv($file, [
                    $t->created_at->format('d M Y H:i'),
                    $this->csvSafe($t->invoice_number),
                    ($t->invoice_mode ?? '') === 'local' ? 'Local' : 'FBR',
                    ucwords(str_replace('_', ' ', (string) $t->payment_method)),
                    number_format($t->subtotal, 2, '.', ''),
                    number_format($t->discount_amount, 2, '.', ''),
                    number_format($t->tax_amount, 2, '.', ''),
                    number_format($t->total_amount, 2, '.', ''),
                ]);
            }
            fputcsv($file, []);
            fputcsv($file, ['', '', '', 'TOTAL', '', '', '', number_format($transactions->sum('total_amount'), 2, '.', '')]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Download a single customer's purchase history as a PDF.
     */
    public function historyPdf($id)
    {
        $this->assertNotCashier();
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $transactions = $this->customerTransactions($companyId, $customer);
        $totalSpent = $transactions->sum('total_amount');
        $totalOrders = $transactions->count();

        $filename = 'Customer_History_' . preg_replace('/[^A-Za-z0-9]+/', '_', $customer->name) . '_' . now()->format('Ymd') . '.pdf';
        return $this->renderReportPdf(
            'fbr-pos.customer-history-pdf',
            compact('company', 'customer', 'transactions', 'totalSpent', 'totalOrders'),
            $filename
        );
    }

    /**
     * Render an A4 report PDF via mPDF for 'ur' locale or DomPDF for en/rur.
     * Mirrors FbrPosController::renderReportPdf (kept local — that one is
     * private; see PosController::renderReportPdf for full notes).
     */
    private function renderReportPdf(
        string $view,
        array $data,
        string $filename,
        string $orientation = 'portrait'
    ): \Illuminate\Http\Response {
        $isUrdu = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT;
        $data['pdfUrdu'] = $isUrdu;

        if ($isUrdu) {
            try {
                return \App\Support\MpdfRenderer::render(
                    $view, $data, 'a4-report', $filename, false, $orientation
                );
            } catch (\Throwable $e) {
                \Log::warning("mPDF report render failed [{$filename}]: " . $e->getMessage());
            }
        }

        \App\Support\PosLocale::applyPdfSafeLocale();
        $data['pdfUrdu'] = false;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $data)
            ->setPaper('a4', $orientation);

        return $pdf->download($filename);
    }
}
