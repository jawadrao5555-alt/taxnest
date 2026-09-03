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
        if ($company->inventory_enabled) {
            $this->validateInventorySaleSnapshot($company, $sale);
            // Read-only expansion performs all tenant/id/quantity validation
            // before storeInvoice gets an opportunity to write anything.
            InventoryService::canonicalConsumptions((int) $company->id, $sale);
        }
        $requestData = $sale + [
            'offline_queued_at' => $event['occurred_at'] ?? now()->toIso8601String(),
            'offline_queued_by' => $user->id,
            'offline_branch_id' => $branchId,
            'order_type' => null,
            'save_as_provisional' => false,
        ];
        if (($requestData['discount_type'] ?? null) === 'fixed') {
            $requestData['discount_type'] = 'amount';
        }
        $request = Request::create('/pos/invoice/store', 'POST', $requestData);
        $request->headers->set('Accept', 'application/json');
        if ($company->inventory_enabled) {
            // Request attributes are server-internal and cannot be supplied by
            // an HTTP client. They select the immutable snapshot stock path.
            $request->attributes->set('agent_core_inventory_sale', $sale);
        }
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

    private function validateInventorySaleSnapshot(Company $company, array $sale): void
    {
        $items = $sale['items'] ?? null;
        $totals = $sale['totals'] ?? null;
        if (!is_array($items) || !$items || !is_array($totals)
            || empty($sale['business_date'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $sale['business_date'])) {
            throw ValidationException::withMessages(['payload.sale' => ['Complete immutable items and totals are required.']]);
        }
        $subtotal = 0;
        foreach ($items as $line) {
            $tax = is_array($line) ? ($line['tax_snapshot'] ?? null) : null;
            if (!is_array($line)
                || trim((string) ($line['line_id'] ?? '')) === ''
                || !is_numeric($line['quantity'] ?? null)
                || (float) $line['quantity'] < 1
                || abs((float) $line['quantity'] - round((float) $line['quantity'])) > 0.000001
                || !is_numeric($line['unit_price'] ?? null)
                || (float) $line['unit_price'] < 0
                || !array_key_exists('recipe_snapshot', $line)
                || !is_array($line['recipe_snapshot'])
                || !is_bool($line['has_recipe'] ?? null)
                || (($line['has_recipe'] === true) !== !empty($line['recipe_snapshot']))
                || !is_array($tax)
                || !is_int($tax['rate_basis_points'] ?? null)
                || !is_bool($tax['exempt'] ?? null)
                || !is_bool($tax['inclusive'] ?? null)
                || !array_key_exists('menu_rate_basis_points', $tax)) {
                throw ValidationException::withMessages(['payload.sale.items' => ['Immutable line quantity, price, and recipe snapshot are invalid.']]);
            }
            $lineTotal = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
            if (isset($line['line_total']) && abs((float) $line['line_total'] - $lineTotal) > 0.001) {
                throw ValidationException::withMessages(['payload.sale.items' => ['Immutable line total is inconsistent.']]);
            }
            $subtotal = round($subtotal + $lineTotal, 2);
        }
        foreach (['subtotal', 'discount_amount', 'tax_amount', 'total_amount'] as $field) {
            if (!is_numeric($totals[$field] ?? null) || !is_finite((float) $totals[$field]) || (float) $totals[$field] < 0) {
                throw ValidationException::withMessages(['payload.sale.totals' => ['Immutable totals are invalid.']]);
            }
        }
        if (abs($subtotal - (float) $totals['subtotal']) > 0.001
            || (float) $totals['discount_amount'] > $subtotal) {
            throw ValidationException::withMessages(['payload.sale.totals' => ['Immutable subtotal or discount is inconsistent.']]);
        }
        $expected = !empty($totals['tax_inclusive'])
            ? $subtotal - (float) $totals['discount_amount']
            : $subtotal - (float) $totals['discount_amount'] + (float) $totals['tax_amount'];
        if (abs(round($expected, 2) - (float) $totals['total_amount']) > 0.001) {
            throw ValidationException::withMessages(['payload.sale.totals' => ['Immutable total is inconsistent.']]);
        }
    }
}