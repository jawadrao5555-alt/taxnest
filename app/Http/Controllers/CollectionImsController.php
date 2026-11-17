<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\BranchContextService;
use App\Services\FbrPosAgentClaim;
use App\Services\HospitalPaymentImsService;
use App\Services\SchoolFeeImsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CollectionImsController extends Controller
{
    private function owner(Request $request): Company
    {
        $user = auth('fbrpos')->user();
        abort_unless($user && $user->is_active && app(BranchContextService::class)->isOwner($user), 403);
        abort_if($request->session()->get('impersonation.readonly'), 403);
        $company = Company::findOrFail($user->company_id);
        abort_unless($company->isLocalCollectionPilot(), 404);
        abort_unless($request->routeIs($company->isSchoolFeePilot() ? 'fbrpos.school-fees.*' : 'fbrpos.hospital.*'), 404);

        return $company;
    }

    public function save(Request $request)
    {
        $company = $this->owner($request);
        $data = $request->validate([
            'fbr_pos_id' => ['nullable', 'string', 'regex:/^[0-9]{1,20}$/D'],
            'fbr_pos_environment' => 'required|in:sandbox,production',
            'fbr_pos_token' => 'nullable|string|max:2000',
            'fbr_access_code' => 'nullable|string|max:500',
            'fbr_sales_tax_registration_number' => 'nullable|string|max:30',
            'fbr_tax_office' => 'nullable|string|max:150',
        ]);

        DB::transaction(function () use ($company, $data) {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $posId = $data['fbr_pos_id'] ?? null;
            if ($locked->fbr_reporting_enabled && (!$posId || !trim($posId, '0'))) {
                throw ValidationException::withMessages(['fbr_pos_id' => 'Turn reporting OFF before removing the registered POS ID.']);
            }
            if ($locked->fbr_reporting_enabled && ((string) $posId !== (string) $locked->fbr_pos_id
                || $data['fbr_pos_environment'] !== ($locked->fbr_pos_environment ?? 'sandbox'))) {
                throw ValidationException::withMessages(['fbr_pos_id' => 'Turn reporting OFF before changing the POS ID or environment.']);
            }
            $locked->fbr_pos_id = $posId;
            $locked->fbr_pos_environment = $data['fbr_pos_environment'];
            $locked->fbr_connection_mode = 'fiscal_device';
            $locked->fbr_pos_enabled = (bool) trim((string) $posId, '0');
            foreach (['fbr_pos_token', 'fbr_access_code'] as $key) {
                if (!empty($data[$key])) {
                    $locked->{$key} = Crypt::encryptString($data[$key]);
                }
            }
            foreach (['fbr_sales_tax_registration_number', 'fbr_tax_office'] as $key) {
                if (array_key_exists($key, $data)) $locked->{$key} = $data[$key];
            }
            $locked->save();
        }, 3);

        return back()->with('status', 'IMS setup saved.');
    }

    public function toggle(Request $request)
    {
        $company = $this->owner($request);
        $enabled = $request->validate(['enabled' => 'required|boolean'])['enabled'];
        DB::transaction(function () use ($company, $enabled) {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            if ($enabled && (!$locked->agentServesFbr() || !$locked->fbrPosIntegrationConfigured())) {
                throw ValidationException::withMessages(['enabled' => 'Save the registered POS ID and pair the Desktop Agent before turning ON reporting.']);
            }
            $locked->fbr_reporting_enabled = (bool) $enabled;
            $locked->save();
        }, 3);

        return back()->with('status', $enabled ? 'FBR IMS reporting ON for eligible new receipts.' : 'FBR IMS reporting OFF.');
    }

    public function diagnostics(Request $request)
    {
        $company = $this->owner($request);
        $table = $company->isSchoolFeePilot() ? 'school_fee_receipts' : 'hospital_payments';
        $receipts = collect();
        if (Schema::hasColumns($table, ['fiscal_route_snapshot', 'fbr_status', 'fbr_pos_transaction_id'])) {
            $receipts = DB::table($table)->where('company_id', $company->id)->orderByDesc('id')->limit(15)
                ->get(['id', 'fiscal_route_snapshot', 'fbr_status', 'fbr_pos_transaction_id']);
        }

        return back()->with('ims_diagnostics', [
            'company_id' => $company->id,
            'agent_online' => $company->agentOnline(),
            'receipts' => $receipts->map(function ($receipt) {
                $route = json_decode($receipt->fiscal_route_snapshot ?? 'null', true)['route'] ?? null;
                return [
                    'id' => $receipt->id,
                    'status' => $receipt->fbr_status ?: match ($route) {
                        'ims' => $receipt->fbr_pos_transaction_id ? 'Waiting for status' : 'IMS queue entry missing',
                        'off' => 'Local only',
                        default => 'Original route unavailable',
                    },
                ];
            })->all(),
        ]);
    }

    public function retry(Request $request, int $id)
    {
        $company = $this->owner($request);
        $school = $company->isSchoolFeePilot();
        $table = $school ? 'school_fee_receipts' : 'hospital_payments';
        $service = $school ? SchoolFeeImsService::class : HospitalPaymentImsService::class;

        try {
            $message = DB::transaction(function () use ($company, $table, $service, $id) {
                $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
                $receipt = DB::table($table)->where('company_id', $locked->id)->where('id', $id)->lockForUpdate()->first();
                abort_unless($receipt, 404);
                $snapshot = json_decode($receipt->fiscal_route_snapshot ?? 'null', true);
                if (($snapshot['route'] ?? null) !== 'ims' || empty($snapshot['eligible'])) {
                    return 'This receipt was saved as local-only or has no verified IMS route. It cannot be retried.';
                }
                $environment = $locked->fbr_pos_environment === 'production' ? 'production' : 'sandbox';
                if (!$locked->fbr_reporting_enabled || !$locked->agentServesFbr() || !$locked->fbrPosIntegrationConfigured()
                    || ($snapshot['environment'] ?? null) !== $environment) {
                    return 'Restore the receipt’s original IMS environment and enable reporting before retrying.';
                }
                if (empty($receipt->fbr_pos_transaction_id)) {
                    $result = $service::queueFinalizedReceipt((int) $locked->id, $id);

                    return !empty($result['queued']) ? 'Existing receipt queued for IMS.' : 'IMS queue is unavailable. Ask your administrator to check the receipt.';
                }
                $mirror = FbrPosTransaction::where('company_id', $locked->id)->findOrFail($receipt->fbr_pos_transaction_id);
                if ($mirror->fbr_invoice_number || $mirror->fbr_status === 'submitted') {
                    return 'A fiscal result already exists for this receipt.';
                }
                if (!in_array($mirror->fbr_status, ['failed', 'config_error'], true) || !FbrPosAgentClaim::prepareRetry($mirror)) {
                    return 'This receipt is already queued or needs reconciliation before another attempt.';
                }
                $service::reconcileReceipt((int) $locked->id, $id);

                return 'Existing receipt queued for IMS retry.';
            }, 3);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning('Collection receipt could not be queued for FBR IMS', [
                'company_id' => $company->id, 'receipt_id' => $id, 'source' => $table, 'sqlstate' => $e->getCode(),
            ]);
            $message = 'IMS queue could not be saved. Ask your administrator to check the receipt.';
        }

        return back()->with('ims_status', $message);
    }
}
