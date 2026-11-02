<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosDiSandboxSubmission;
use App\Models\PosTransaction;
use App\Services\PraDiSandboxService;
use App\Support\PraDiSandboxGate;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PraDiSandboxController extends Controller
{
    private function company(Request $request): Company
    {
        PraDiSandboxGate::enforce();
        $user = auth('pos')->user();
        // Fiscal rehearsal is owner-only, independent of custom cashier/manager grants.
        abort_unless($user && $user->isCompanyAdmin() && $user->is_active, 403);
        $company = Company::where('product_type', 'pos')->findOrFail($user->company_id);
        abort_unless($company->status === 'approved' && $company->company_status === 'active', 403);
        if (!$request->isMethod('GET')) {
            // POS WebViews have a legacy blanket exemption; this new browser workbench does not.
            $token = $request->input('_token');
            abort_unless(is_string($token) && hash_equals($request->session()->token(), $token), 419);
        }
        return $company;
    }

    private function record(Company $company, int $id): PosDiSandboxSubmission
    {
        return PosDiSandboxSubmission::where('company_id', $company->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $company = $this->company($request);
        return response()->view('pos.di-sandbox.index', [
            'company' => $company,
            'sales' => PosTransaction::where('company_id', $company->id)->where('status', 'completed')->latest('id')->limit(30)->get(),
            'records' => PosDiSandboxSubmission::where('company_id', $company->id)->latest('id')->limit(30)->get(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function capture(Request $request, PraDiSandboxService $service)
    {
        $company = $this->company($request);
        $data = $request->validate(['transaction_id' => 'required|integer|min:1', 'fictional_data' => 'accepted']);
        $record = $service->capture($company, (int) $data['transaction_id'], auth('pos')->id());
        return redirect()->route('pos.di-sandbox.show', $record->id);
    }

    public function show(Request $request, int $id, PraDiSandboxService $service)
    {
        $record = $this->record($this->company($request), $id);
        return response()->view('pos.di-sandbox.show', [
            'record' => $record, 'payload' => $record->payload ?? $service->template($record),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function prepare(Request $request, int $id, PraDiSandboxService $service)
    {
        $company = $this->company($request);
        $this->record($company, $id);
        $data = $request->validate(['payload_json' => 'required|string|max:100000', 'mapping_reviewed' => 'accepted']);
        try { $payload = json_decode($data['payload_json'], true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw ValidationException::withMessages(['payload_json' => 'Enter a valid JSON invoice object.']); }
        if (!is_array($payload)) throw ValidationException::withMessages(['payload_json' => 'Enter a JSON invoice object.']);
        $service->prepare($company->id, $id, $payload);
        return redirect()->route('pos.di-sandbox.show', $id)->with('success', 'Sandbox payload saved for review. Nothing has been sent.');
    }

    public function submit(Request $request, int $id, PraDiSandboxService $service)
    {
        $company = $this->company($request);
        $this->record($company, $id);
        $request->validate(['confirm_sandbox' => 'accepted']);
        $service->submit($company->id, $id, auth('pos')->id());
        return redirect()->route('pos.di-sandbox.show', $id);
    }

    public function receipt(Request $request, int $id)
    {
        $record = $this->record($this->company($request), $id);
        $width = $request->query('width') === '58' ? 58 : 80;
        return response()->view('pos.di-sandbox.receipt', compact('record', 'width'))
            ->header('Cache-Control', 'no-store, private');
    }
}
