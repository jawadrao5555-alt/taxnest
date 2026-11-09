<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionFinancialAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AdminSubscriptionAdjustmentController extends Controller
{
    public function store(Request $request, int $company): RedirectResponse
    {
        $actor = \App\Models\AdminUser::requireBillingAdministrator();
        $validated = $request->validate([
            'kind' => 'required|in:credit_note,refund_record,reversal',
            'subscription_invoice_id' => 'required|integer|min:1',
            'subscription_payment_id' => 'nullable|integer|min:1',
            'reverses_adjustment_id' => 'nullable|integer|min:1',
            'amount' => 'required|numeric|gt:0|max:9999999999.99',
            'reason' => 'required|string|max:500',
            'external_reference' => 'nullable|string|max:255',
            'idempotency_key' => 'required|uuid',
        ]);
        $validated['company_id'] = $company;
        try {
            $adjustment = SubscriptionFinancialAdjustmentService::record($validated, (int) $actor->id);
        } catch (\DomainException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw ValidationException::withMessages(['adjustment' => $e->getMessage()]);
        }

        return back()->with('success', 'Immutable billing adjustment evidence #'.$adjustment->id.' recorded. No money, access or invoice was changed.');
    }
}
