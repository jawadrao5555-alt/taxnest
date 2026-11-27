<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Company;
use App\Services\ErpUpgradeQuote;
use App\Services\SubscriptionAssignmentService;
use Illuminate\Http\Request;
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
            'company', 'plans', 'cycles', 'quote', 'quoteError', 'planId', 'cycle'
        ))->header('Cache-Control', 'private, no-store');
    }
}
