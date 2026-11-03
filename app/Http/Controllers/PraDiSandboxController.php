<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosDiSandboxSubmission;
use App\Models\PosDiSandboxProfile;
use App\Models\PosTransaction;
use App\Services\PraDiSandboxService;
use App\Support\PraDiSandboxGate;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PraDiSandboxController extends Controller
{
    private function company(Request $request, bool $setup = false): Company
    {
        $user = auth('pos')->user();
        // Fiscal rehearsal is owner-only, independent of custom cashier/manager grants.
        abort_unless($user && $user->isCompanyAdmin() && $user->is_active, 403);
        $company = Company::where('product_type', 'pos')->findOrFail($user->company_id);
        abort_unless($company->status === 'approved' && $company->company_status === 'active', 403);
        if ($setup) abort_unless(PraDiSandboxGate::setupAvailable(), 404);
        else PraDiSandboxGate::enforce($company->id);
        if (!$request->isMethod('GET')) {
            // POS WebViews have a legacy blanket exemption; this new browser workbench does not.
            $token = $request->input('_token');
            abort_unless(is_string($token) && hash_equals($request->session()->token(), $token), 419);
        }
        return $company;
    }

    public function setup(Request $request)
    {
        $company = $this->company($request, true);
        $profile = PosDiSandboxProfile::where('company_id', $company->id)->first();
        $scenarios = PosDiSandboxSubmission::where('company_id', $company->id)->where('environment', 'sandbox')
            ->where('state', 'accepted')->get()->map(fn ($record) => $record->payload['scenarioId'] ?? null)->filter()->unique()->values();
        return response()->view('pos.di-sandbox.setup', compact('company', 'profile', 'scenarios'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function saveSetup(Request $request)
    {
        // Strip both credentials before validation can flash request input.
        $tokens = $request->only(['sandbox_token', 'production_token']);
        foreach (['sandbox_token', 'production_token'] as $field) {
            $request->request->remove($field);
            if ($request->isJson()) $request->json()->remove($field);
        }
        $company = $this->company($request, true);
        $data = $request->validate([
            'enabled' => 'required|boolean', 'environment' => 'sometimes|required|in:sandbox,production',
            'confirm_sandbox_setup' => 'sometimes|accepted', 'confirm_production_setup' => 'sometimes|accepted',
        ]);
        $tokenRule = ['nullable', 'string', 'max:8192', 'regex:/^[\x21-\x7E]+$/'];
        Validator::make($tokens, ['sandbox_token' => $tokenRule, 'production_token' => $tokenRule])->validate();
        DB::transaction(function () use ($company, $data, $tokens) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $profile = PosDiSandboxProfile::firstOrNew(['company_id' => $company->id]);
            $environment = $data['environment'] ?? $profile->environment ?? 'sandbox';
            foreach (['sandbox_token', 'production_token'] as $field) {
                if (is_string($tokens[$field] ?? null) && $tokens[$field] !== '') $profile->{$field} = $tokens[$field];
            }
            if ($data['enabled']) {
                $confirmation = $environment === 'production' ? 'confirm_production_setup' : 'confirm_sandbox_setup';
                Validator::make($data, [$confirmation => 'accepted'])->validate();
                $field = $environment.'_token';
                if (!$profile->{$field}) {
                    throw ValidationException::withMessages([$field => 'Enter the selected environment\'s DI token before enabling reporting.']);
                }
                if ($environment === 'production') {
                    abort_unless(config('pra_di_sandbox.production_enabled', false), 422, 'Production DI is disabled on this server.');
                    $profile->production_confirmed_at = now();
                    $profile->production_confirmed_by = auth('pos')->id();
                }
            }
            $profile->environment = $environment;
            $profile->enabled = (bool) $data['enabled'];
            $profile->updated_by = auth('pos')->id();
            $profile->save();
        });
        return redirect()->route('pos.di-sandbox.setup')->with('success',
            'DI settings saved. No invoice has been sent. Disabling stops new requests; an already-started request may finish.');
    }

    private function record(Company $company, int $id): PosDiSandboxSubmission
    {
        return PosDiSandboxSubmission::where('company_id', $company->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $company = $this->company($request, true);
        return response()->view('pos.di-sandbox.index', [
            'company' => $company,
            'hosted' => PraDiSandboxGate::hostedAvailable(),
            'environment' => PraDiSandboxGate::environment($company->id),
            'canReport' => PraDiSandboxGate::available($company->id),
            'sales' => PosTransaction::where('company_id', $company->id)->where('status', 'completed')->latest('id')->limit(30)->get(),
            'records' => PosDiSandboxSubmission::where('company_id', $company->id)->latest('id')->limit(30)->get(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function capture(Request $request, PraDiSandboxService $service)
    {
        $company = $this->company($request);
        $environment = PraDiSandboxGate::environment($company->id);
        $confirmation = $environment === 'production' ? 'confirm_production_copy'
            : (PraDiSandboxGate::hostedAvailable() ? 'sandbox_copy' : 'fictional_data');
        $data = $request->validate(['transaction_id' => 'required|integer|min:1', $confirmation => 'accepted']);
        $record = $service->capture($company, (int) $data['transaction_id'], auth('pos')->id(), $request->input('environment'));
        return redirect()->route('pos.di-sandbox.show', $record->id);
    }

    public function show(Request $request, int $id, PraDiSandboxService $service)
    {
        $record = $this->record($this->company($request, true), $id);
        return response()->view('pos.di-sandbox.show', [
            'record' => $record, 'payload' => $record->payload ?? $service->template($record),
            'canSubmit' => PraDiSandboxGate::available($record->company_id, $record->reportingEnvironment()),
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
        return redirect()->route('pos.di-sandbox.show', $id)->with('success', 'DI payload saved for review. Nothing has been sent.');
    }

    public function submit(Request $request, int $id, PraDiSandboxService $service)
    {
        $company = $this->company($request);
        $record = $this->record($company, $id);
        $confirmation = $record->reportingEnvironment() === 'production' ? 'confirm_production' : 'confirm_sandbox';
        $request->validate([$confirmation => 'accepted']);
        $service->submit($company->id, $id, auth('pos')->id());
        return redirect()->route('pos.di-sandbox.show', $id);
    }

    public function receipt(Request $request, int $id)
    {
        $record = $this->record($this->company($request, true), $id);
        $width = $request->query('width') === '58' ? 58 : 80;
        return response()->view('pos.di-sandbox.receipt', compact('record', 'width'))
            ->header('Cache-Control', 'no-store, private');
    }
}
