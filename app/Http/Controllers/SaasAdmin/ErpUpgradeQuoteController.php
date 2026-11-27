<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ErpUpgradeSettlement;
use App\Models\Scopes\CompanyScope;
use App\Services\ErpUpgradeCutoverService;
use App\Services\ErpUpgradeQuote;
use App\Services\ErpUpgradeSettlementService;
use App\Services\SubscriptionAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ErpUpgradeQuoteController extends Controller
{
    public function __invoke(Request $request, Company $company, ErpUpgradeQuote $quotes)
    {
        AdminUser::requireSuperAdministrator();
        abort_unless($company->isHospitalCollectionPilot(), 404);

        $plans = $quotes->hospitalPlans();
        $cycles = $plans->flatMap(fn ($plan) => SubscriptionAssignmentService::cyclesForPlan($plan))->unique()->all();
        $quote = null;
        $quoteError = null;
        $planId = null;
        $cycle = 'annual';
        $settlements = ErpUpgradeSettlement::where('source_company_id', $company->id)
            ->latest('id')->limit(10)->get();
        $settlement = $request->filled('settlement_id')
            ? ErpUpgradeSettlement::where('source_company_id', $company->id)->findOrFail($request->integer('settlement_id'))
            : ($settlements->first(fn (ErpUpgradeSettlement $item) => $item->settled_at) ?? $settlements->first());
        $branches = collect();
        if ($settlement?->settled_at && !$settlement->activated_at) {
            $branches = Branch::withoutGlobalScope(CompanyScope::class)
                ->whereIn('company_id', [$company->id, $settlement->destination_company_id])
                ->where('is_active', true)->orderBy('name')->orderBy('id')
                ->get(['id', 'company_id', 'name'])->groupBy('company_id');
        }

        if ($request->has('pricing_plan_id') || $request->has('billing_cycle')) {
            try {
                $input = $request->validate([
                    'pricing_plan_id' => ['required', 'integer', 'min:1'],
                    'billing_cycle' => ['required', Rule::in($cycles)],
                ]);
                $planId = (int) $input['pricing_plan_id'];
                $cycle = $input['billing_cycle'];
                $quote = $quotes->preview($company, $planId, $cycle);
            } catch (ValidationException $exception) {
                if ($request->expectsJson()) {
                    throw $exception;
                }
                $quoteError = $exception->validator->errors()->first();
            }
        }

        return response()->view('saas-admin.companies.erp-upgrade-quote', compact(
            'company', 'plans', 'cycles', 'quote', 'quoteError', 'planId', 'cycle', 'settlement', 'settlements', 'branches'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function freeze(Request $request, Company $company, ErpUpgradeSettlementService $upgrades)
    {
        $admin = AdminUser::requireSuperAdministrator();
        abort_unless($company->isHospitalCollectionPilot(), 404);
        $input = $request->validate([
            'destination_company_id' => ['required', 'integer', 'min:1'],
            'pricing_plan_id' => ['required', 'integer', 'min:1'],
            'billing_cycle' => ['required', Rule::in(SubscriptionAssignmentService::SELLABLE_CYCLES)],
            'owner_authorization_reference' => ['required', 'string', 'max:150'],
            'expected_package_paisa' => ['required', 'integer', 'min:1'],
            'expected_credit_paisa' => ['required', 'integer', 'min:0'],
        ]);
        $settlement = DB::transaction(function () use ($company, $input, $admin, $upgrades) {
            $settlement = $upgrades->freeze(
                $company->id, (int) $input['destination_company_id'], (int) $input['pricing_plan_id'],
                $input['billing_cycle'], $admin->id, $input['owner_authorization_reference']
            );
            if ($settlement->target_price_paisa !== (int) $input['expected_package_paisa']
                || $settlement->credit_paisa !== (int) $input['expected_credit_paisa']) {
                throw ValidationException::withMessages(['quote' => 'The amount changed. Preview the updated difference before freezing the quote.']);
            }

            return $settlement;
        }, 3);

        return $this->result($company, $settlement, 'Upgrade quote frozen.');
    }

    public function settle(Request $request, Company $company, int $upgrade, ErpUpgradeSettlementService $upgrades)
    {
        $admin = AdminUser::requireSuperAdministrator();
        abort_unless($company->isHospitalCollectionPilot(), 404);
        $input = $request->validate([
            'verified_received_amount' => ['required', 'string', 'max:14'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
        ]);
        $settlement = $upgrades->settle(
            $company->id, $upgrade, $input['verified_received_amount'], $input['payment_reference'] ?? null, $admin->id
        );

        return $this->result($company, $settlement, 'Upgrade difference verified.');
    }

    public function activate(Request $request, Company $company, int $upgrade, ErpUpgradeCutoverService $cutover)
    {
        $admin = AdminUser::requireSuperAdministrator();
        abort_unless($company->isHospitalCollectionPilot(), 404);
        $input = $request->validate([
            'branch_map' => ['nullable', 'array'],
            'branch_map.*' => ['required', 'integer', 'min:1'],
            'activation_reference' => ['required', 'string', 'max:150'],
        ]);
        $settlement = $cutover->activate(
            $company->id, $upgrade, $input['branch_map'] ?? [], $input['activation_reference'], $admin->id
        );

        return $this->result($company, $settlement, 'Hospital ERP package activated.');
    }

    private function result(Company $company, ErpUpgradeSettlement $settlement, string $message)
    {
        return redirect()->route('saas.admin.companies.erpUpgradeQuote', [
            'company' => $company, 'settlement_id' => $settlement->id,
        ])->with('success', $message);
    }
}
