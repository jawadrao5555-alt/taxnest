<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\{AdminUser, Company};
use App\Services\{HistoricalPosFederalAddon, PosFederalAddonAccess};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class HistoricalPosFederalAddonController extends Controller
{
    public function show(int $companyId): View
    {
        AdminUser::requireBillingAdministrator();
        $company = Company::findOrFail($companyId);
        $term = HistoricalPosFederalAddon::currentTerm($company);
        $grant = HistoricalPosFederalAddon::available() ? HistoricalPosFederalAddon::grant($company) : null;
        $blocker = HistoricalPosFederalAddon::blocker($company, $term, $grant);
        $canRecord = $blocker === null;

        return view('saas-admin.historical-federal-addon', compact('company', 'term', 'grant', 'blocker', 'canRecord'));
    }

    public function record(Request $request, int $companyId): RedirectResponse
    {
        AdminUser::requireBillingAdministrator();
        $data = $request->validate([
            'subscription_id' => ['required', 'integer', 'min:1'],
            'addon_code' => ['required', Rule::in(PosFederalAddonAccess::CODES)],
            'purchase_mode' => ['required', Rule::in(['included', 'separate'])],
            'evidence_reference' => ['required', 'string', 'max:150'],
            'idempotency_key' => ['required', 'uuid'],
            'received_amount' => ['exclude_unless:purchase_mode,separate', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000000'],
            'payment_date' => ['exclude_unless:purchase_mode,separate', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_method' => ['exclude_unless:purchase_mode,separate', 'required', Rule::in(['cash', 'card', 'bank'])],
            'payment_reference' => ['exclude_unless:purchase_mode,separate', 'nullable', 'string', 'max:150'],
        ]);

        HistoricalPosFederalAddon::record($companyId, $data);

        return redirect()->route('saas.admin.companies.historical-federal-addon', $companyId)
            ->with('success', 'Historical add-on recorded against the selected package term.');
    }
}
