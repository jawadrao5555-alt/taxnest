<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Models\Company;
use App\Models\AdminAuditLog;
use App\Models\Franchise;
use App\Models\User;
use App\Services\CredentialLedgerService;
use App\Services\HealthModuleService;
use App\Services\TrialSubscriptionService;
use App\Support\HealthPanel;
use App\Support\NestErps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Dedicated company signup boundary. No credential-reveal or lifecycle actions. */
class AdminCompanyCreationController extends AdminCompanyController
{
    public function create()
    {
        \App\Models\AdminUser::requireCompanyLifecycleAdministrator();
        $franchises = Franchise::where('status', 'active')->get();
        $agents = $this->activeAgents();
        $registrationPlans = \App\Models\PricingPlan::where('is_trial', false)->get()
            ->reject(fn ($plan) => \App\Services\PlanSellabilityService::isRetired($plan));
        return view('saas-admin.companies.create', compact('franchises', 'agents', 'registrationPlans'));
    }

    /** Active agents for the "Introduced by Agent" dropdown (super-admin only UI). */
    private function activeAgents()
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('agents')) {
            return collect();
        }
        return \App\Models\Agent::where('status', 'active')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $currentAdmin = \App\Models\AdminUser::requireBillingAdministrator();
        // Which POS panel (if any) owns the selected business type.
        $posPanel = match ($request->input('product_type')) {
            'fbrpos' => 'fbr',
            'pos'    => 'pra',
            default  => null,
        };

        $request->validate([
            'name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'product_type' => 'required|' . \App\Support\ProductCatalog::validationRule(),
            'erps_vertical' => ['nullable', 'string', Rule::in(NestErps::verticalKeys())],
            'health_org_type' => ['nullable', 'string', Rule::in(HealthPanel::ORG_TYPES)],
            'email' => 'required|email|max:255',
            'ntn' => 'nullable|string|max:50',
            // Shared CNIC truth (Task 580): 13 digits, dash-tolerant, GLOBAL
            // uniqueness — admin screens must not bypass the owner-facing rules.
            'cnic' => \App\Services\LoginIdentifierResolver::cnicRules(null, $request->input('product_type')),
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'business_activity' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            // Business type — POS products only, and only the types that panel
            // is allowed to use (the same list the signup screens offer, plus
            // the 'general' catch-all). Nothing picked = general.
            'pos_type' => $posPanel
                ? 'nullable|string|in:' . implode(',', \App\Services\PosFeatureService::choosableCategories($posPanel))
                : 'nullable|string|max:50',
            'requested_plan' => 'nullable|string|max:255',
            'requested_billing_cycle' => 'nullable|string|in:monthly,quarterly,semi_annual,annual',
            'status' => 'required|in:approved,pending',
            'franchise_id' => 'nullable|exists:franchises,id',
            'agent_id' => 'nullable|exists:agents,id',
            'admin_email' => ['required', 'email', \App\Support\IdentityScope::uniqueEmail($request->input('product_type'))],
            'admin_password' => ['required', 'string', \Illuminate\Validation\Rules\Password::defaults()],
            'admin_name' => 'required|string|max:255',
        ], \App\Services\LoginIdentifierResolver::cnicMessages());

        // Canonical product type BEFORE anything reads it: a form still open in
        // another tab may post the old healthcare spelling, and a company that
        // stored it would look like an unknown product to the money paths.
        $request->merge([
            'product_type' => \App\Support\ProductCatalog::normalize($request->input('product_type'))
                ?? $request->input('product_type'),
        ]);

        $requestedPlan = \App\Services\RequestedPackageService::resolvePlan($request->input('requested_plan'), $request->product_type);
        if ($request->filled('requested_plan') && !$requestedPlan) {
            throw \Illuminate\Validation\ValidationException::withMessages(['requested_plan' => 'Choose an available package for the selected product.']);
        }
        // Selection records intent, never creates paid entitlement or enables fiscal reporting.
        $requestedAttributes = \App\Services\RequestedPackageService::companyAttributes($requestedPlan, $request->input('requested_billing_cycle'));

        // Store plain digits — the login lookup digit-compares, and plain
        // storage keeps every panel's CNIC matching trivially exact.
        $normalizedCnic = \App\Services\LoginIdentifierResolver::normalizeCnic($request->cnic);

        $companyData = [
            'name' => $request->name,
            'owner_name' => $request->owner_name,
            'product_type' => $request->product_type,
            'email' => $request->email,
            'ntn' => $request->ntn,
            'cnic' => $normalizedCnic,
            'phone' => $request->phone,
            'mobile' => $request->mobile,
            'address' => $request->address,
            'city' => $request->city,
            'province' => $request->province,
            'business_activity' => $request->business_activity,
            'website' => $request->website,
            'status' => $request->status,
            'franchise_id' => $request->franchise_id,
            'company_status' => $request->status === 'pending' ? 'pending' : 'active',
            'standard_tax_rate' => $request->product_type === 'di' ? 18.00 : 16.00,
            'fbr_pos_enabled' => $request->product_type === 'fbrpos',
            'fbr_reporting_enabled' => $request->product_type === 'fbrpos',
        ];

        // The database supplies the default on products that do not use FBR POS.
        if ($request->product_type === 'fbrpos') {
            $companyData['fbr_pos_environment'] = 'sandbox';
        }

        if ($request->product_type === 'pos') {
            $companyData['pra_reporting_enabled'] = false;
            $companyData['pra_environment'] = 'sandbox';
        }

        // A POS company created here must open exactly like one that signed
        // itself up (Task 1562): the business type staff picked is stored WITH
        // that trade's module map and the master switches derived from it, so
        // the owner never lands on an empty POS. It goes through the one shared
        // registration-preset helper the signup screens use, so the admin path
        // can never drift from them. Nothing picked = the 'general' catch-all,
        // which stores a bare preset rather than nothing at all (a shop with no
        // stored type silently reads as a restaurant). Every column the helper
        // writes is hasColumn-guarded there, so a partly-migrated deployment
        // still creates companies instead of 500ing.
        if ($posPanel !== null) {
            $posType = $request->filled('pos_type') ? $request->input('pos_type') : 'general';
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_type')) {
                $companyData['pos_type'] = $posType;
            }
            $companyData += \App\Services\PosFeatureService::registrationAttributes($posType);
            if ($request->product_type === 'fbrpos' && in_array($posType, ['education', 'clinic', 'hospital'], true)) {
                $companyData['fbr_reporting_enabled'] = false;
            }
        }

        if (NestErps::isProductType($request->product_type)) {
            $vertical = NestErps::normalizeVertical($request->input('erps_vertical'));
            if (Schema::hasColumn('companies', NestErps::VERTICAL_COLUMN)) {
                $companyData[NestErps::VERTICAL_COLUMN] = $vertical;
            }
            if ($vertical === NestErps::HEALTH) {
                $orgType = HealthPanel::normalizeOrgType($request->input('health_org_type'));
                if (Schema::hasColumn('companies', 'health_org_type')) {
                    $companyData['health_org_type'] = $orgType;
                }
                if (Schema::hasColumn('companies', 'health_modules')) {
                    $companyData['health_modules'] = HealthModuleService::defaultsForOrgType($orgType);
                }
            }
        }

        // Only super admin sees/sets the agent dropdown (Agents section is
        // super-admin-only); key added conditionally so older schemas without
        // the column never receive it.
        if ($currentAdmin->isSuperAdmin()
            && $request->filled('agent_id')
            && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'agent_id')) {
            $companyData['agent_id'] = $request->agent_id;
        }

        $adminPassword = Hash::make($request->admin_password);
        $company = DB::transaction(function () use ($request, $currentAdmin, $companyData, $requestedAttributes, $normalizedCnic, $adminPassword): Company {
            $company = Company::create($companyData + $requestedAttributes);

            User::create([
                'name' => $request->admin_name,
                'email' => $request->admin_email,
                'password' => $adminPassword,
                'company_id' => $company->id,
                'role' => 'company_admin',
                'is_active' => true,
                'pos_role' => in_array($request->product_type, ['pos', 'fbrpos']) ? 'pos_admin' : null,
            ]);

            // The requested paid package remains separate from the standard 14-day trial.
            TrialSubscriptionService::ensureTrial($company->id, $request->product_type, 14);

            CredentialLedgerService::record([
                'email' => $request->admin_email,
                'phone' => $request->phone ?: $request->mobile,
                'ntn' => $request->ntn,
                'cnic' => $normalizedCnic,
            ], $company->id, $request->product_type);

            AdminAuditLog::log($currentAdmin->id, 'Company created', 'Company', $company->id, [
                'name' => $company->name,
                'type' => $request->product_type,
                'admin_email' => $request->admin_email,
                'agent_id' => $company->agent_id,
                'attribution_source' => $company->agent_id ? 'super_admin_company_creation' : 'direct_customer',
            ]);

            return $company;
        });

        return redirect()->route('saas.admin.companies.show', $company->id)->with('success', "Company '{$company->name}' created successfully with admin account.");
    }

}
