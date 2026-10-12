<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\InvoiceImportBatch;
use App\Models\InvoiceImportVerifiedMapping;
use App\Models\InvoiceImportSourceReceipt;
use App\Services\InvoiceImportService;
use App\Services\SmartInvoiceImportReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Review is part of the existing bulk importer, never a second submission engine. */
class SmartInvoiceImportController extends Controller
{
    private function batch(int $id): InvoiceImportBatch
    {
        abort_unless(config('features.enable_smart_di_import_safeguards'), 404);
        return InvoiceImportBatch::where('company_id', app('currentCompanyId'))
            ->whereNotNull('smart_source_system')->findOrFail($id);
    }

    public function show(int $batchId)
    {
        $batch = $this->batch($batchId);
        $mappings = InvoiceImportVerifiedMapping::where('company_id', $batch->company_id)
            ->where('source_system', $batch->smart_source_system)->orderByDesc('version')->get()
            ->unique(fn ($m) => $m->kind . ':' . $m->source_code);
        $receipts = InvoiceImportSourceReceipt::where('company_id', $batch->company_id)
            ->where('import_batch_id', $batch->id)->with('invoice')->get();
        return view('invoice.source-import-review', compact('batch', 'mappings', 'receipts'));
    }

    public function saveMapping(Request $request, int $batchId)
    {
        $batch = $this->batch($batchId);
        abort_unless(auth()->user()?->role === 'company_admin', 403);
        $input = $request->validate([
            'kind' => ['required', Rule::in(['sku', 'buyer'])],
            'source_code' => 'required|string|max:120',
            'evidence' => 'required|string|min:5|max:2000',
            'verified' => 'accepted',
            'details' => 'required|array',
        ]);
        if ($input['kind'] === 'sku') {
            $request->validate([
                'details.description' => 'required|string|max:255',
                'details.hs_code' => 'required|string|regex:/^\d{4,12}$/',
                'details.uom' => ['required', Rule::in(['Liter'])],
                'details.litres_per_case' => 'required|numeric|gt:0|max:10000',
                'details.schedule_type' => ['required', Rule::in(InvoiceImportService::VALID_SCHEDULE_TYPES)],
                'details.tax_rate' => 'required|numeric|min:0|max:100',
                'details.mrp' => 'nullable|numeric|gt:0',
                'details.sro_schedule_no' => 'nullable|string|max:100',
                'details.sro_serial_no' => 'nullable|string|max:100',
            ]);
            if ($input['details']['schedule_type'] === '3rd_schedule') {
                $request->validate(['details.mrp' => 'required|numeric|gt:0']);
            }
            $allowed = ['description', 'hs_code', 'uom', 'litres_per_case', 'schedule_type', 'tax_rate', 'mrp', 'sro_schedule_no', 'sro_serial_no'];
        } else {
            $request->validate([
                'details.buyer_name' => 'required|string|max:255',
                'details.buyer_address' => 'required|string|max:500',
                'details.destination_province' => ['required', Rule::in(InvoiceImportService::VALID_PROVINCES)],
                'details.registration_type' => ['required', Rule::in(['Registered', 'Unregistered'])],
                'details.buyer_ntn' => 'nullable|string|regex:/^(\d{7}|\d{13})$/',
                'details.buyer_cnic' => 'nullable|string|regex:/^\d{13}$/',
            ]);
            if ($input['details']['registration_type'] === 'Registered') {
                $request->validate(['details.buyer_ntn' => 'required|string']);
            }
            $allowed = ['buyer_name', 'buyer_address', 'buyer_ntn', 'buyer_cnic', 'destination_province', 'registration_type'];
        }
        DB::transaction(function () use ($input, $allowed, $batch) {
            // Serialize mapping versions across concurrent admin requests.
            Company::whereKey($batch->company_id)->lockForUpdate()->firstOrFail();
            $base = InvoiceImportVerifiedMapping::where('company_id', $batch->company_id)
                ->where('source_system', $batch->smart_source_system)->where('kind', $input['kind'])
                ->where('source_code', trim($input['source_code']));
            InvoiceImportVerifiedMapping::create([
                'company_id' => $batch->company_id, 'source_system' => $batch->smart_source_system,
                'kind' => $input['kind'], 'source_code' => trim($input['source_code']),
                'version' => ((int) $base->max('version')) + 1,
                'details' => array_intersect_key($input['details'], array_flip($allowed)),
                'evidence' => $input['evidence'], 'verified_by' => auth()->id(), 'verified_at' => now(),
            ]);
        });
        return back()->with('success', 'Verified mapping saved as a new version. Refresh source review to apply it.');
    }

    public function review(Request $request, int $batchId)
    {
        $this->batch($batchId);
        $request->validate(['exclusions' => 'nullable|array', 'exclusions.*' => 'nullable|string|max:500', 'approve' => 'nullable|accepted', 'amounts' => 'nullable|array', 'amounts.*' => 'array', 'amounts.*.discount' => 'nullable|string|max:30', 'amounts.*.vat' => 'nullable|string|max:30', 'amounts.*.net' => 'nullable|string|max:30', 'amounts.*.evidence' => 'nullable|string|max:500']);
        DB::transaction(function () use ($request, $batchId) {
            $batch = InvoiceImportBatch::where('company_id', app('currentCompanyId'))->whereKey($batchId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($batch->status, ['needs_review', 'validated'], true) && !$batch->approved_at, 409, 'Approved/processing source snapshots cannot be edited.');
            $raw = array_map(fn ($row) => ['row' => $row['row'], 'data' => $row['original_data'], 'errors' => $row['parse_errors'] ?? []], $batch->rowsArray());
            $review = (new SmartInvoiceImportReview())->review($raw, Company::findOrFail($batch->company_id), $batch->smart_source_system, $request->input('exclusions', []));
            $invalid = count(array_filter($review['rows'], fn ($row) => !empty($row['errors'])));
            $valid = count(array_filter($review['rows'], fn ($row) => $row['valid']));
            $approve = $request->boolean('approve');
            if ($approve && ($invalid || !$valid)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['approve' => 'Resolve all source invoice blockers before approving drafts.']);
            }
            $json = json_encode($review['rows'], JSON_THROW_ON_ERROR);
            if ($approve) {
                $review['approved_hash'] = hash('sha256', $json);
            }
            $batch->update(['rows_json' => $json, 'smart_review' => $review,
                'invalid_rows' => $invalid, 'valid_rows' => $valid,
                'status' => $invalid ? 'needs_review' : 'validated',
                'approved_at' => $approve ? now() : null, 'approved_by' => $approve ? auth()->id() : null]);
        });
        return back()->with('success', $request->boolean('approve') ? 'Approved for draft creation. FBR submission remains a separate action.' : 'Source review refreshed.');
    }
}
