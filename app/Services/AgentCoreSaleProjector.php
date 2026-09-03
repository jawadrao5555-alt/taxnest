<?php

namespace App\Services;

use App\Http\Controllers\PosController;
use App\Models\AgentCoreEvent;
use App\Models\Company;
use App\Models\User;
use App\Models\PosTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AgentCoreSaleProjector
{
    public function project(Company $company, string $deviceUid, array $event): array
    {
        if (($event['event_type'] ?? '') !== 'sale.created') {
            return ['event_id' => $event['event_id'], 'status' => 'accepted'];
        }
        $scope = (array) ($event['scope'] ?? []);
        if ((string) ($scope['company_id'] ?? '') !== (string) $company->id ||
            (string) ($scope['device_id'] ?? '') !== $deviceUid) {
            throw ValidationException::withMessages(['scope' => ['Company/device scope mismatch.']]);
        }
        $payload = (array) ($event['payload'] ?? []);
        if (($payload['schema'] ?? '') !== 'pra.manual-immediate.v1') {
            throw ValidationException::withMessages(['payload' => ['Unsupported sale schema.']]);
        }
        if ($company->inventory_enabled) {
            throw ValidationException::withMessages(['payload' => ['Inventory-enabled sales are not supported by this Local Core phase.']]);
        }
        $user = User::query()->where('company_id', $company->id)
            ->whereKey((int) ($scope['user_id'] ?? 0))->first();
        $allowedRoles = ['company_admin', 'pos_admin', 'pos_manager', 'pos_cashier'];
        if (!$user || !$user->is_active || $company->product_type !== 'pos' ||
            !in_array((string) ($user->pos_role ?: $user->role), $allowedRoles, true)) {
            throw ValidationException::withMessages(['scope.user_id' => ['Cashier is not authorized.']]);
        }
        $branchId = (int) ($scope['branch_id'] ?? 0);
        if ($branchId < 1 || !Schema::hasTable('branches') ||
            !DB::table('branches')->where('company_id', $company->id)->where('id', $branchId)->exists()) {
            throw ValidationException::withMessages(['scope.branch_id' => ['Branch scope mismatch.']]);
        }
        $role = app(\App\Services\BranchContextService::class)->effectiveRole($user);
        if (in_array($role, ['cashier', 'employee'], true) && (int) $user->default_branch_id !== $branchId) {
            throw ValidationException::withMessages(['scope.branch_id' => ['Cashier is not assigned to this branch.']]);
        }
        if ($role === 'manager') {
            $assigned = DB::table('branch_user')->where('user_id', $user->id)->where('branch_id', $branchId)->exists();
            if (!$assigned && (int) $user->default_branch_id !== $branchId) {
                throw ValidationException::withMessages(['scope.branch_id' => ['Manager is not assigned to this branch.']]);
            }
        }

        $sale = (array) ($payload['sale'] ?? []);
        if (!in_array($sale['payment_method'] ?? null, ['cash', 'card'], true) || empty($sale['offline_uuid'])) {
            throw ValidationException::withMessages(['payload.sale' => ['Unsupported payment or missing offline_uuid.']]);
        }
        $requestData = $sale + [
            'offline_queued_at' => $event['occurred_at'] ?? now()->toIso8601String(),
            'offline_queued_by' => $user->id,
            'offline_branch_id' => $branchId,
            'order_type' => null,
            'save_as_provisional' => false,
        ];
        $request = Request::create('/pos/invoice/store', 'POST', $requestData);
        $request->headers->set('Accept', 'application/json');
        $priorUser = Auth::guard('pos')->user();
        $hadCompany = app()->bound('currentCompanyId');
        $priorCompany = $hadCompany ? app('currentCompanyId') : null;
        $hadBranch = app()->bound('currentBranchId');
        $priorBranch = $hadBranch ? app('currentBranchId') : null;

        try {
            Auth::guard('pos')->setUser($user);
            app()->instance('currentCompanyId', $company->id);
            app()->instance('currentBranchId', $branchId);
            $expected = (float) (($payload['sale']['totals']['total_amount'] ?? null));
            $data = DB::transaction(function () use ($request, $expected) {
                $response = app(PosController::class)->storeInvoice($request);
                $decoded = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
                if (($response->getStatusCode() ?? 500) >= 400 || !($decoded['success'] ?? false)) {
                    $winner = PosTransaction::withoutGlobalScope('hide_archived')
                        ->where('company_id', app('currentCompanyId'))
                        ->where('offline_uuid', (string) $request->input('offline_uuid'))->first();
                    if ($winner) {
                        return [
                            'success' => true, 'replayed' => true, 'transaction_id' => $winner->id,
                            'invoice_number' => $winner->invoice_number, 'total_amount' => (float) $winner->total_amount,
                            'pra_status' => $winner->pra_status,
                        ];
                    }
                    throw ValidationException::withMessages([
                        'projection' => [(string) ($decoded['message'] ?? $decoded['error'] ?? 'Sale projection rejected.')],
                    ]);
                }
                if (abs((float) ($decoded['total_amount'] ?? 0) - $expected) > 0.001) {
                    throw ValidationException::withMessages([
                        'payload.sale.totals' => ['Authoritative total no longer matches the accepted snapshot.'],
                    ]);
                }
                return $decoded;
            });
        } finally {
            Auth::guard('pos')->setUser($priorUser);
            if ($hadCompany) app()->instance('currentCompanyId', $priorCompany); else app()->forgetInstance('currentCompanyId');
            if ($hadBranch) app()->instance('currentBranchId', $priorBranch); else app()->forgetInstance('currentBranchId');
        }

        return [
            'event_id' => $event['event_id'],
            'status' => 'projected',
            'transaction_id' => $data['transaction_id'],
            'invoice_number' => $data['invoice_number'],
            'pra_status' => $data['pra_status'] ?? null,
            'replayed' => (bool) ($data['replayed'] ?? false),
        ];
    }
}