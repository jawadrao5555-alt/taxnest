<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\AdminAuditLog;
use App\Models\CompanyUsageStat;
use App\Models\Invoice;
use App\Models\PosTransaction;
use App\Models\FbrPosTransaction;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Franchise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Services\CredentialLedgerService;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminCompanyController extends Controller
{
    public function create()
    {
        $franchises = Franchise::where('status', 'active')->get();
        $agents = $this->activeAgents();
        return view('saas-admin.companies.create', compact('franchises', 'agents'));
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
        $request->validate([
            'name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'product_type' => 'required|in:di,pos,fbrpos',
            'email' => 'required|email|max:255',
            'ntn' => 'nullable|string|max:50',
            // Shared CNIC truth (Task 580): 13 digits, dash-tolerant, GLOBAL
            // uniqueness — admin screens must not bypass the owner-facing rules.
            'cnic' => \App\Services\LoginIdentifierResolver::cnicRules(),
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'business_activity' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'status' => 'required|in:approved,pending',
            'franchise_id' => 'nullable|exists:franchises,id',
            'agent_id' => 'nullable|exists:agents,id',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:6',
            'admin_name' => 'required|string|max:255',
        ], \App\Services\LoginIdentifierResolver::cnicMessages());

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
            'company_status' => 'active',
            'standard_tax_rate' => $request->product_type === 'di' ? 18.00 : 16.00,
            'fbr_pos_enabled' => $request->product_type === 'fbrpos',
            'fbr_pos_environment' => $request->product_type === 'fbrpos' ? 'sandbox' : null,
            'fbr_reporting_enabled' => $request->product_type === 'fbrpos',
        ];

        if ($request->product_type === 'pos') {
            $companyData['pra_reporting_enabled'] = false;
            $companyData['pra_environment'] = 'sandbox';
        }

        // Only super admin sees/sets the agent dropdown (Agents section is
        // super-admin-only); key added conditionally so older schemas without
        // the column never receive it.
        if (auth('admin')->user()?->isSuperAdmin()
            && $request->filled('agent_id')
            && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'agent_id')) {
            $companyData['agent_id'] = $request->agent_id;
        }

        $company = Company::create($companyData);

        User::create([
            'name' => $request->admin_name,
            'email' => $request->admin_email,
            'password' => Hash::make($request->admin_password),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'is_active' => true,
            'pos_role' => in_array($request->product_type, ['pos', 'fbrpos']) ? 'pos_admin' : null,
        ]);

        // Every company must start with a subscription row — admin-created ones
        // get the standard 14-day trial (admin can assign a real plan after).
        \App\Services\TrialSubscriptionService::ensureTrial($company->id, $request->product_type, 14);

        // Record credentials in the anti-reuse ledger (admin creation is never blocked).
        CredentialLedgerService::record([
            'email' => $request->admin_email,
            'phone' => $request->phone ?: $request->mobile,
            'ntn' => $request->ntn,
            'cnic' => $normalizedCnic,
        ], $company->id, $request->product_type);

        AdminAuditLog::log(auth('admin')->id(), 'Company created', 'Company', $company->id, [
            'name' => $company->name,
            'type' => $request->product_type,
            'admin_email' => $request->admin_email,
        ]);

        return redirect()->route('saas.admin.companies.show', $company->id)->with('success', "Company '{$company->name}' created successfully with admin account.");
    }

    public function edit($id)
    {
        $company = Company::findOrFail($id);
        $franchises = Franchise::where('status', 'active')->get();
        $companyAdmin = User::where('company_id', $id)->where('role', 'company_admin')->first();
        $agents = $this->activeAgents();
        return view('saas-admin.companies.edit', compact('company', 'franchises', 'companyAdmin', 'agents'));
    }

    public function update(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        $request->validate([
            'name' => 'required|string|max:255',
            'owner_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'ntn' => 'nullable|string|max:50',
            // Shared CNIC truth (Task 580); own row exempt from the dupe check.
            'cnic' => \App\Services\LoginIdentifierResolver::cnicRules($company->id),
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'business_activity' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'franchise_id' => 'nullable|exists:franchises,id',
            'agent_id' => 'nullable|exists:agents,id',
            'standard_tax_rate' => 'nullable|numeric|min:0|max:100',
            'invoice_number_prefix' => 'nullable|string|max:20',
            'fbr_environment' => 'nullable|in:sandbox,production',
            'fbr_registration_no' => 'nullable|string|max:100',
            'fbr_business_name' => 'nullable|string|max:255',
            'pra_environment' => 'nullable|string|max:50',
            'pra_pos_id' => 'nullable|string|max:100',
            'fbr_pos_enabled' => 'nullable|boolean',
            'fbr_pos_environment' => 'nullable|in:sandbox,production',
            'fbr_pos_id' => 'nullable|string|max:100',
            'admin_password' => 'nullable|string|min:6|max:100',
            'admin_email' => 'nullable|email|max:255',
        ], \App\Services\LoginIdentifierResolver::cnicMessages());

        if ($request->filled('admin_password') || $request->filled('admin_email')) {
            $companyAdmin = User::where('company_id', $id)->where('role', 'company_admin')->first();
            if ($companyAdmin) {
                if ($request->filled('admin_password')) {
                    $companyAdmin->password = Hash::make($request->admin_password);
                    // Rotate remember token so old "remember me" cookies die too.
                    $companyAdmin->setRememberToken(Str::random(60));
                }
                if ($request->filled('admin_email')) {
                    $emailTaken = User::where('email', $request->admin_email)
                        ->where('id', '!=', $companyAdmin->id)->exists();
                    if ($emailTaken) {
                        return back()->withErrors(['admin_email' => 'This email is already used by another user.'])->withInput();
                    }
                    $companyAdmin->email = $request->admin_email;
                }
                $companyAdmin->save();
                AdminAuditLog::log(auth('admin')->id(), 'Company admin credentials updated', 'User', $companyAdmin->id, ['company' => $company->name]);
            }
        }

        $fields = [
            'name', 'owner_name', 'email', 'ntn', 'cnic', 'phone', 'mobile',
            'address', 'city', 'province', 'business_activity', 'website',
            'franchise_id', 'standard_tax_rate', 'invoice_number_prefix',
        ];

        if ($company->product_type === 'di') {
            $fields = array_merge($fields, ['fbr_environment', 'fbr_registration_no', 'fbr_business_name', 'fbr_pos_enabled', 'fbr_pos_environment', 'fbr_pos_id']);
        } else {
            $fields = array_merge($fields, ['pra_environment', 'pra_pos_id']);
        }

        // Agent link is super-admin-only (the field is hidden from other admins).
        if (auth('admin')->user()?->isSuperAdmin()
            && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'agent_id')
            && $request->has('agent_id')) {
            $fields[] = 'agent_id';
        }

        $data = $request->only($fields);
        if (array_key_exists('cnic', $data)) {
            // Always store plain digits (empty clears to NULL).
            $data['cnic'] = \App\Services\LoginIdentifierResolver::normalizeCnic($data['cnic']);
        }

        $company->update($data);

        AdminAuditLog::log(auth('admin')->id(), 'Company profile updated', 'Company', $id, ['name' => $company->name]);
        return redirect()->route('saas.admin.companies.show', $id)->with('success', "Company '{$company->name}' updated successfully.");
    }

    public function index(Request $request)
    {
        // Lazily lock any company whose date-based grant has expired (cron-safe).
        \App\Services\SubscriptionAccessService::reconcileExpiredGrants();

        $query = Company::query()->with(['franchise', 'activeSubscription', 'requestedPlan']);

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->product_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                  ->orWhere('ntn', $like, "%{$search}%")
                  ->orWhere('owner_name', $like, "%{$search}%");
            });
        }

        $companies = $query->orderBy('created_at', 'desc')->paginate(20)->appends($request->all());

        // Agent Health (Task 629): shops that RELY on the Desktop Agent for silent
        // printing but whose agent has been offline for hours — cashiers there are
        // silently degraded to Chrome print popups (Frost & Brew incident, Aug 2026).
        // silent_print_enabled lives inside the pos_printer_settings JSON, so the
        // SQL narrows to agent-enabled active shops and PHP applies the JSON gate.
        $offlineAgents = Company::query()
            ->where('agent_enabled', true)
            ->whereIn('status', ['approved', 'active'])
            ->where(function ($q) {
                $q->whereNull('agent_last_seen')
                  ->orWhere('agent_last_seen', '<', now()->subHours(2));
            })
            ->orderBy('agent_last_seen')
            ->get(['id', 'name', 'product_type', 'agent_enabled', 'agent_last_seen', 'agent_version', 'pos_printer_settings'])
            ->filter(fn ($c) => $c->agentLongOffline())
            ->values();

        // Task 1066: latest agent version for the outdated-agent badge in the list.
        // One cached call (600s) — never per-row.
        $releaseInfo = \App\Http\Controllers\AgentManagementController::latestReleaseInfo();
        $latestAgentVersion = null;
        if (!empty($releaseInfo['tag']) && preg_match('/^v?(\d{1,2})\.(\d+)\.(\d+)$/', $releaseInfo['tag'], $m)) {
            $latestAgentVersion = "{$m[1]}.{$m[2]}.{$m[3]}";
        }

        return view('saas-admin.companies.index', compact('companies', 'offlineAgents', 'latestAgentVersion'));
    }

    public function show($id)
    {
        $company = Company::withTrashed()->with(['franchise', 'activeSubscription', 'requestedPlan'])->findOrFail($id);
        $usageStats = CompanyUsageStat::refreshForCompany($id);

        $extraStats = [];
        if ($company->product_type === 'di') {
            $extraStats['total_invoices'] = Invoice::where('company_id', $id)->count();
            // Invoice lifecycle lives in `status` (draft/locked/...); `fbr_status`
            // only holds submission state (NULL/production/submitted/failed) and
            // NEVER 'locked' — filtering on it silently yields zero.
            $extraStats['locked_invoices'] = Invoice::where('company_id', $id)->where('status', 'locked')->count();
            $extraStats['total_revenue'] = Invoice::where('company_id', $id)->where('status', 'locked')->sum('total_amount');
            $extraStats['draft_invoices'] = Invoice::where('company_id', $id)->where('status', 'draft')->count();
        } elseif ($company->product_type === 'fbrpos') {
            // FBR POS sales live in fbr_pos_transactions — NOT pos_transactions.
            // Querying the PRA table here always returned zero for FBR companies.
            $base = FbrPosTransaction::where('company_id', $id)->where('status', 'completed');
            $extraStats['total_transactions'] = (clone $base)->count();
            $extraStats['total_revenue'] = (clone $base)->sum('total_amount');
            $extraStats['today_transactions'] = (clone $base)->whereDate('created_at', today())->count();
            $extraStats['today_revenue'] = (clone $base)->whereDate('created_at', today())->sum('total_amount');
            $extraStats['month_revenue'] = (clone $base)->whereBetween('created_at', [now()->startOfMonth(), now()])->sum('total_amount');
            $extraStats['last_sale_at'] = (clone $base)->max('created_at');
        } else {
            $base = PosTransaction::where('company_id', $id)->where('status', 'completed');
            $extraStats['total_transactions'] = (clone $base)->count();
            $extraStats['total_revenue'] = (clone $base)->sum('total_amount');
            $extraStats['today_transactions'] = (clone $base)->whereDate('created_at', today())->count();
            $extraStats['today_revenue'] = (clone $base)->whereDate('created_at', today())->sum('total_amount');
            $extraStats['month_revenue'] = (clone $base)->whereBetween('created_at', [now()->startOfMonth(), now()])->sum('total_amount');
            $extraStats['last_sale_at'] = (clone $base)->max('created_at');
        }

        $extraStats['total_users'] = User::where('company_id', $id)->count();
        $extraStats['active_subscription'] = Subscription::where('company_id', $id)->where('active', true)->with('pricingPlan')->first();

        // Archive Viewer accounts (only meaningful for POS companies).
        $archiveViewers = User::where('company_id', $id)
            ->where('pos_role', 'archive_viewer')
            ->orderBy('id', 'desc')
            ->get();

        // Local Bills Viewer accounts (only meaningful for POS companies).
        $localViewers = User::where('company_id', $id)
            ->where('pos_role', 'local_viewer')
            ->orderBy('id', 'desc')
            ->get();

        // Main login account for the "Login Credentials" card (password reset).
        $companyAdmin = $this->findCompanyAdmin($id);

        // Team & Last Logins card: every login (DI/POS/FBR POS guards) stamps
        // users.last_login_at via the Login event listener in AppServiceProvider.
        $teamUsers = User::where('company_id', $id)
            ->orderByRaw('last_login_at IS NULL, last_login_at DESC')
            ->get(['id', 'name', 'email', 'role', 'pos_role', 'last_login_at', 'last_login_ip']);

        // Exempt-internal PRA bills: historical bills stamped 'exempt_internal'
        // that were never submitted to PRA (pre-Task-760). Super-admin can re-queue
        // them from the UI instead of needing SSH/artisan access.
        $exemptInternalBills = collect();
        if ($company->product_type === 'pos' && auth('admin')->user()?->isSuperAdmin()) {
            $exemptInternalBills = PosTransaction::where('company_id', $id)
                ->where('pra_status', 'exempt_internal')
                ->whereNull('pra_invoice_number')
                ->orderBy('id')
                ->get(['id', 'invoice_number', 'total_amount', 'created_at', 'pra_status']);
        }

        return view('saas-admin.companies.show', compact('company', 'usageStats', 'extraStats', 'archiveViewers', 'localViewers', 'companyAdmin', 'teamUsers', 'exemptInternalBills'));
    }

    /**
     * Locate the company's main login account: the company_admin user,
     * falling back to a pos_admin (some legacy POS registrations).
     */
    private function findCompanyAdmin($companyId): ?User
    {
        return User::where('company_id', $companyId)->where('role', 'company_admin')->orderBy('id')->first()
            ?? User::where('company_id', $companyId)->where('pos_role', 'pos_admin')->orderBy('id')->first();
    }

    /**
     * Reset the company's main login password from the company details page.
     * Passwords are one-way hashed and can never be displayed — only replaced.
     * Super-admin action; audit-logged.
     */
    public function resetAdminPassword(Request $request, $id)
    {
        $this->assertSuperAdmin();

        $company = Company::withTrashed()->findOrFail($id);

        $request->validate([
            'new_password' => 'required|string|min:6|max:100',
        ]);

        $companyAdmin = $this->findCompanyAdmin($id);
        if (!$companyAdmin) {
            return back()->with('error', 'No admin login account found for this company.');
        }

        $companyAdmin->password = Hash::make($request->new_password);
        // Rotate the remember token so any "remember me" cookies issued with
        // the OLD password stop working — otherwise the previous holder stays
        // logged in indefinitely after a reset.
        $companyAdmin->setRememberToken(Str::random(60));
        $companyAdmin->save();

        AdminAuditLog::log(auth('admin')->id(), 'Company admin password reset', 'User', $companyAdmin->id, [
            'company' => $company->name,
            'email' => $companyAdmin->email,
        ]);

        return back()->with('success', "Password updated for {$companyAdmin->email}. All new logins now require the new password.");
    }

    /**
     * Reveal the (encrypted) FBR IRIS Access Code for VPS/FBRIMS setup.
     * Super-admin only; every reveal is audit-logged.
     */
    public function revealFbrAccessCode($id)
    {
        $company = Company::withTrashed()->findOrFail($id);

        if (empty($company->fbr_access_code)) {
            return response()->json(['error' => 'No access code saved for this company.'], 404);
        }

        try {
            $code = \Illuminate\Support\Facades\Crypt::decryptString($company->fbr_access_code);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Decryption failed — stored value is unreadable (check APP_KEY).'], 500);
        }

        AdminAuditLog::log(auth('admin')->id(), 'FBR Access Code revealed', 'Company', $company->id, [
            'company' => $company->name,
        ]);

        return response()->json(['code' => $code]);
    }

    /**
     * View as Company — start a VIEW-ONLY impersonation session.
     *
     * The admin guard uses a separate provider (admin_users), so logging the
     * company's admin user into that company's panel guard (web/pos/fbrpos) leaves
     * the admin session fully intact. All state-changing requests are then blocked
     * by ReadOnlyImpersonation while the session flag is set.
     */
    public function impersonate(Request $request, $id)
    {
        $this->assertSuperAdmin();

        $company = Company::findOrFail($id);

        // Only active companies can be viewed — CompanyIsolation force-logs-out any
        // non-active company_status, which would instantly bounce the session.
        if (($company->company_status ?? null) !== 'active') {
            return back()->with('error', 'Only active companies can be viewed. Approve/activate this company first.');
        }

        // Map product type → panel guard + dashboard.
        $guard = match ($company->product_type) {
            'pos' => 'pos',
            'fbrpos' => 'fbrpos',
            default => 'web',
        };

        if ($guard === 'fbrpos' && !$company->fbr_pos_enabled) {
            return back()->with('error', 'FBR POS is not enabled for this company — cannot view its FBR POS panel.');
        }

        // Find the company's admin user for this panel (must be active or the panel
        // middleware will immediately log it out).
        $user = User::where('company_id', $company->id)
            ->where('role', 'company_admin')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$user && $guard === 'pos') {
            $user = User::where('company_id', $company->id)
                ->where('pos_role', 'pos_admin')
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
        }

        if (!$user) {
            return back()->with('error', 'No active admin user found for this company — cannot start view-only session.');
        }

        // If a previous view-only session is still open, close its guard first so we
        // never leave a stale panel login behind when switching companies/guards.
        $existing = $request->session()->get('impersonation');
        if (is_array($existing) && !empty($existing['guard']) && auth($existing['guard'])->check()) {
            auth($existing['guard'])->logout();
        }

        // Log the company user into the panel guard (admin guard survives untouched).
        auth($guard)->login($user);

        // Anti-fixation: rotate the session id, preserving all session data (both the
        // admin login and the freshly-added panel login migrate to the new id).
        $request->session()->regenerate();

        // Access mode: 'full' lets the admin make REAL changes as the company;
        // anything else (missing / tampered) falls back to safe read-only 'view'.
        $mode = $request->input('mode') === 'full' ? 'full' : 'view';
        $readonly = $mode !== 'full';

        $request->session()->put('impersonation', [
            'admin_id' => auth('admin')->id(),
            'company_id' => $company->id,
            'company_name' => $company->name,
            'guard' => $guard,
            'mode' => $mode,
            'readonly' => $readonly,
        ]);

        AdminAuditLog::log(
            auth('admin')->id(),
            $readonly ? 'Started view-as (read-only)' : 'Started manage-as (FULL ACCESS)',
            'Company',
            $company->id,
            [
                'company' => $company->name,
                'guard' => $guard,
                'mode' => $mode,
            ]
        );

        $dashboard = match ($guard) {
            'pos' => '/pos/dashboard',
            'fbrpos' => '/fbr-pos/dashboard',
            default => '/dashboard',
        };

        return redirect($dashboard);
    }

    /**
     * Stop view-as — log out ONLY the panel guard and clear the flag.
     * NEVER session()->invalidate() here — that would destroy the admin session too.
     */
    public function stopImpersonation(Request $request)
    {
        $imp = $request->session()->get('impersonation');

        if (is_array($imp)) {
            $guard = $imp['guard'] ?? 'web';
            if (auth($guard)->check()) {
                auth($guard)->logout();
            }
            AdminAuditLog::log(auth('admin')->id(), 'Stopped view-as', 'Company', $imp['company_id'] ?? null, [
                'company' => $imp['company_name'] ?? null,
            ]);
        }

        $request->session()->forget('impersonation');

        $companyId = $imp['company_id'] ?? null;

        return redirect($companyId ? route('saas.admin.companies.show', $companyId) : route('saas.admin.companies'))
            ->with('success', 'Impersonation session ended.');
    }

    /**
     * Downgrade an active FULL-ACCESS impersonation to safe view-only WITHOUT
     * leaving the company panel. This only ever tightens access (never upgrades),
     * so it is always safe to expose from the in-panel banner.
     */
    public function lockImpersonation(Request $request)
    {
        $imp = $request->session()->get('impersonation');

        if (is_array($imp)) {
            $imp['mode'] = 'view';
            $imp['readonly'] = true;
            $request->session()->put('impersonation', $imp);

            AdminAuditLog::log(
                $imp['admin_id'] ?? auth('admin')->id(),
                'Locked view-as to read-only',
                'Company',
                $imp['company_id'] ?? null,
                ['company' => $imp['company_name'] ?? null]
            );
        }

        return redirect()->back()->with('success', 'Switched to view-only mode.');
    }

    /**
     * Archive Viewer Account — Super-admin creates a dedicated read-only login for the
     * company's "Local Bills Archive". This account uses the SAME /pos/login URL (auto-detected
     * by pos_role) and is invisible to POS admin/cashier (Team page filters it out).
     */
    /** Super-admin gate — shared by every archive-viewer admin action. */
    private function assertSuperAdmin(): void
    {
        $admin = auth('admin')->user();
        if (!$admin || !$admin->isSuperAdmin()) {
            abort(403, 'Super admin only.');
        }
    }

    public function storeArchiveViewer(Request $request, $id)
    {
        $this->assertSuperAdmin();
        $company = Company::findOrFail($id);
        if ($company->product_type !== 'pos') {
            return back()->with('error', 'Archive Viewer only available for POS companies.');
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'company_id' => $company->id,
            'role' => 'employee',
            'pos_role' => 'archive_viewer',
            'is_active' => true,
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Archive Viewer created', 'User', $user->id, [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'email' => $user->email,
        ]);

        return back()->with('success', "Archive Viewer account created for {$company->name}. They can now log in at /pos/login.");
    }

    public function updateArchiveViewer(Request $request, $id, $userId)
    {
        $this->assertSuperAdmin();
        $company = Company::findOrFail($id);
        $user = User::where('company_id', $company->id)
            ->where('pos_role', 'archive_viewer')
            ->findOrFail($userId);

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
        ]);

        $update = [
            'name' => $request->name,
            'email' => $request->email,
        ];
        if ($request->filled('password')) {
            $update['password'] = bcrypt($request->password);
        }
        $user->update($update);

        AdminAuditLog::log(auth('admin')->id(), 'Archive Viewer updated', 'User', $user->id, [
            'company_id' => $company->id,
            'password_changed' => $request->filled('password'),
        ]);

        return back()->with('success', 'Archive Viewer credentials updated.');
    }

    public function toggleArchiveViewer($id, $userId)
    {
        $this->assertSuperAdmin();
        $user = User::where('company_id', $id)
            ->where('pos_role', 'archive_viewer')
            ->findOrFail($userId);
        $user->update(['is_active' => !$user->is_active]);

        AdminAuditLog::log(auth('admin')->id(), $user->is_active ? 'Archive Viewer activated' : 'Archive Viewer deactivated', 'User', $user->id, ['company_id' => $id]);

        return back()->with('success', $user->is_active ? 'Archive Viewer activated.' : 'Archive Viewer deactivated.');
    }

    public function deleteArchiveViewer($id, $userId)
    {
        $this->assertSuperAdmin();
        $user = User::where('company_id', $id)
            ->where('pos_role', 'archive_viewer')
            ->findOrFail($userId);

        AdminAuditLog::log(auth('admin')->id(), 'Archive Viewer deleted', 'User', $user->id, [
            'company_id' => $id,
            'email' => $user->email,
        ]);

        $user->delete();
        return back()->with('success', 'Archive Viewer account removed.');
    }

    /**
     * Local Bills Viewer Account — Super-admin creates a dedicated read-only login for the
     * company's "Local Bills Portal" (the ONLY surface where local/non-PRA bills are visible).
     * Same /pos/login URL (auto-detected by pos_role); invisible to POS admin/cashier.
     */
    public function storeLocalViewer(Request $request, $id)
    {
        $this->assertSuperAdmin();
        $company = Company::findOrFail($id);
        if ($company->product_type !== 'pos') {
            return back()->with('error', 'Local Bills Viewer only available for POS companies.');
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'company_id' => $company->id,
            'role' => 'employee',
            'pos_role' => 'local_viewer',
            'is_active' => true,
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Local Bills Viewer created', 'User', $user->id, [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'email' => $user->email,
        ]);

        return back()->with('success', "Local Bills Viewer account created for {$company->name}. They can now log in at /pos/login.");
    }

    public function updateLocalViewer(Request $request, $id, $userId)
    {
        $this->assertSuperAdmin();
        $company = Company::findOrFail($id);
        $user = User::where('company_id', $company->id)
            ->where('pos_role', 'local_viewer')
            ->findOrFail($userId);

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
        ]);

        $update = [
            'name' => $request->name,
            'email' => $request->email,
        ];
        if ($request->filled('password')) {
            $update['password'] = bcrypt($request->password);
        }
        $user->update($update);

        AdminAuditLog::log(auth('admin')->id(), 'Local Bills Viewer updated', 'User', $user->id, [
            'company_id' => $company->id,
            'password_changed' => $request->filled('password'),
        ]);

        return back()->with('success', 'Local Bills Viewer credentials updated.');
    }

    public function toggleLocalViewer($id, $userId)
    {
        $this->assertSuperAdmin();
        $user = User::where('company_id', $id)
            ->where('pos_role', 'local_viewer')
            ->findOrFail($userId);
        $user->update(['is_active' => !$user->is_active]);

        AdminAuditLog::log(auth('admin')->id(), $user->is_active ? 'Local Bills Viewer activated' : 'Local Bills Viewer deactivated', 'User', $user->id, ['company_id' => $id]);

        return back()->with('success', $user->is_active ? 'Local Bills Viewer activated.' : 'Local Bills Viewer deactivated.');
    }

    public function deleteLocalViewer($id, $userId)
    {
        $this->assertSuperAdmin();
        $user = User::where('company_id', $id)
            ->where('pos_role', 'local_viewer')
            ->findOrFail($userId);

        AdminAuditLog::log(auth('admin')->id(), 'Local Bills Viewer deleted', 'User', $user->id, [
            'company_id' => $id,
            'email' => $user->email,
        ]);

        $user->delete();
        return back()->with('success', 'Local Bills Viewer account removed.');
    }

    public function approve($id)
    {
        $company = Company::findOrFail($id);
        // Flip BOTH status columns: `status` drives the CheckCompanyApproval
        // view-only gate + admin UI badges; `company_status` drives login-time
        // checks (CompanyIsolation) — leaving it 'pending' would strand the company.
        $company->update(['status' => 'approved', 'company_status' => 'active']);

        // Owner rule (Jul 2026): approval activates the package the shop picked
        // at registration — a 1-year subscription of exactly that plan.
        $assigned = \App\Services\SubscriptionAssignmentService::assignRequestedPlanOnApproval($company);

        AdminAuditLog::log(auth('admin')->id(), 'Company approved', 'Company', $id, [
            'name' => $company->name,
            'assigned_plan' => $assigned?->pricingPlan?->name,
        ]);

        $this->sendActivationNotification($company);

        $msg = "Company '{$company->name}' has been approved.";
        if ($assigned) {
            $msg .= " {$assigned->pricingPlan->name} package activated for 1 year (until {$assigned->end_date->format('d M Y')}).";
        }
        return back()->with('success', $msg);
    }

    /**
     * Send an in-app notification row and activation email to the company after
     * approval. Mirrors AdminPaymentProofController::notifyCompany — failure here
     * is non-fatal and NEVER blocks the approval action.
     */
    private function sendActivationNotification(Company $company): void
    {
        try {
            [$productLabel, $panelName, $ctaUrl] = match ($company->product_type) {
                'pos'    => ['NestPOS', 'NestPOS — PRA Point of Sale', url('/pos/login')],
                'fbrpos' => ['FBR POS', 'Nest FBR POS', url('/fbr-pos/login')],
                default  => ['TaxNest Digital Invoice', 'Digital Invoicing', url('/login')],
            };

            $title   = 'Account approved — welcome to ' . $productLabel;
            $message = "Your {$productLabel} account has been approved. You can now log in and start using the system.";

            Notification::create([
                'company_id' => $company->id,
                'type'       => 'account_approved',
                'title'      => $title,
                'message'    => $message,
                'read'       => false,
                'metadata'   => ['product_type' => $company->product_type],
            ]);

            $email = $this->companyRecipientEmail($company);
            if ($email) {
                try {
                    Mail::to($email)->send(new \App\Mail\TrialReminderMail(
                        subjectLine: 'Your TaxNest account has been approved',
                        companyName: $company->name ?? 'your company',
                        headline: 'Your account is approved — you\'re all set!',
                        paragraphs: [
                            "We are pleased to inform you that your {$productLabel} account for {$company->name} has been approved.",
                            "You can now log in and start using {$productLabel} right away.",
                            'Thank you for choosing TaxNest.',
                        ],
                        ctaUrl: $ctaUrl,
                        ctaLabel: 'Log In Now',
                        panelName: $panelName,
                    ));

                    \App\Services\MailHealth::recordSuccess();
                } catch (\Throwable $e) {
                    Log::warning('Activation email failed after company approval', [
                        'company_id' => $company->id,
                        'error'      => $e->getMessage(),
                    ]);

                    \App\Services\MailHealth::recordFailure('Activation email on approval', $e);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('sendActivationNotification failed', [
                'company_id' => $company->id ?? null,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the best recipient email for a company: company_admin user first,
     * then the company email, then any user with an email.
     */
    private function companyRecipientEmail(Company $company): ?string
    {
        $admin = $company->users()->where('role', 'company_admin')->orderBy('id')->first();
        if ($admin && $admin->email) {
            return $admin->email;
        }
        if ($company->email) {
            return $company->email;
        }
        $any = $company->users()->whereNotNull('email')->orderBy('id')->first();

        return $any->email ?? null;
    }

    public function reject($id)
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'rejected', 'company_status' => 'rejected']);
        AdminAuditLog::log(auth('admin')->id(), 'Company rejected', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been rejected.");
    }

    public function suspend($id)
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'suspended', 'company_status' => 'suspended', 'suspended_at' => now()]);
        AdminAuditLog::log(auth('admin')->id(), 'Company suspended', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been suspended.");
    }

    public function activate($id)
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'approved', 'company_status' => 'active', 'suspended_at' => null]);
        AdminAuditLog::log(auth('admin')->id(), 'Company activated', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been activated.");
    }

    public function updateLimits(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        $request->validate([
            'invoice_limit_override' => 'nullable|integer|min:0',
            'user_limit_override' => 'nullable|integer|min:0',
            'branch_limit_override' => 'nullable|integer|min:0',
        ]);

        $company->update($request->only(['invoice_limit_override', 'user_limit_override', 'branch_limit_override']));
        AdminAuditLog::log(auth('admin')->id(), 'Company limits updated', 'Company', $id, [
            'name' => $company->name,
            'invoice_limit' => $request->invoice_limit_override,
            'user_limit' => $request->user_limit_override,
            'branch_limit' => $request->branch_limit_override,
        ]);
        return back()->with('success', "Limits updated for '{$company->name}'.");
    }

    public function softDelete(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        $company->update(['deleted_reason' => $request->input('reason', 'Moved to bin by admin')]);
        $company->delete();
        AdminAuditLog::log(auth('admin')->id(), 'Company moved to bin', 'Company', $id, ['name' => $company->name, 'reason' => $request->input('reason')]);
        return redirect()->route('saas.admin.companies')->with('success', "Company '{$company->name}' moved to bin.");
    }

    public function bin(Request $request)
    {
        $query = Company::onlyTrashed()->with('franchise');

        if ($request->filled('search')) {
            $search = $request->search;
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                  ->orWhere('ntn', $like, "%{$search}%");
            });
        }

        $companies = $query->orderBy('deleted_at', 'desc')->paginate(20)->appends($request->all());
        return view('saas-admin.companies.bin', compact('companies'));
    }

    public function restore($id)
    {
        $company = Company::onlyTrashed()->findOrFail($id);
        $company->restore();
        $company->update(['deleted_reason' => null]);
        AdminAuditLog::log(auth('admin')->id(), 'Company restored from bin', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been restored.");
    }

    public function forceDelete($id)
    {
        $company = Company::onlyTrashed()->findOrFail($id);
        $companyName = $company->name;

        // Purge company-scoped OPERATIONAL rows in tables that have no DB-level
        // company FK cascade (newer tables were created without the FK, so a
        // hard delete used to leave orphan rows behind). Deliberately EXCLUDED:
        // audit_logs (immutable audit chain), registered_credentials (ledger
        // that blocks re-registration), hs_* logs (global HS intelligence).
        // Schema guards keep this safe during the deploy-before-migrate window.
        $orphanTables = [
            'pos_riders', 'pos_rider_settlements', 'pos_day_close_reports',
            'pos_deals', 'pos_stations', 'pos_menu_items', 'pos_print_jobs',
            'pos_customer_addresses', 'pos_customer_spend_snapshots',
            'fbr_day_close_reports', 'fbr_pos_held_sales',
            'fbr_pos_loyalty_ledger', 'fbr_pos_loyalty_settings',
            'fbr_pos_promotions', 'fbr_pos_shifts', 'fbr_pos_terminals',
            'push_subscriptions', 'payment_proofs', 'feature_suggestions',
            'madadgar_messages', 'invoice_import_batches', 'invoice_deliveries', 'audit_packs',
            // Consultant console: operational rows die with the company (FK
            // cascade exists on MySQL, but prod drift makes belt+braces cheap).
            // consultant_commissions is deliberately EXCLUDED — money ledger
            // keeps history via nullable FK + company_name snapshot.
            'consultant_client_links', 'consultant_invites',
        ];
        DB::transaction(function () use ($orphanTables, $id, $company) {
            // pos_deal_items hangs off pos_deals (deal_id, no company_id) — purge
            // by parent deal ids BEFORE the deals themselves are removed.
            if (\Illuminate\Support\Facades\Schema::hasTable('pos_deals')
                && \Illuminate\Support\Facades\Schema::hasTable('pos_deal_items')) {
                $dealIds = DB::table('pos_deals')->where('company_id', $id)->pluck('id');
                if ($dealIds->isNotEmpty()) {
                    DB::table('pos_deal_items')->whereIn('deal_id', $dealIds)->delete();
                }
            }
            foreach ($orphanTables as $tbl) {
                if (\Illuminate\Support\Facades\Schema::hasTable($tbl)
                    && \Illuminate\Support\Facades\Schema::hasColumn($tbl, 'company_id')) {
                    DB::table($tbl)->where('company_id', $id)->delete();
                }
            }

            $company->forceDelete();
        });
        // Remove generated Audit Pack ZIPs for this company from disk.
        try {
            \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory('audit-packs/company_' . $id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Audit pack directory cleanup failed on company purge', ['company_id' => $id, 'error' => $e->getMessage()]);
        }
        AdminAuditLog::log(auth('admin')->id(), 'Company permanently deleted', 'Company', $id, ['name' => $companyName]);
        return redirect()->route('saas.admin.companies.bin')->with('success', "Company '{$companyName}' has been permanently deleted.");
    }

    public function changeProductType(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        $request->validate(['product_type' => 'required|in:di,pos,fbrpos']);
        $old = $company->product_type;
        $company->update(['product_type' => $request->product_type]);
        AdminAuditLog::log(auth('admin')->id(), 'Company type changed', 'Company', $id, [
            'name' => $company->name, 'from' => $old, 'to' => $request->product_type
        ]);
        return back()->with('success', "Company type changed to " . strtoupper($request->product_type) . ".");
    }

    // ------------------------------------------------------------------------
    // SUBSCRIPTION OVERRIDE + USAGE LIMIT — admin-only actions
    // Rule: only ONE override active at a time; override always supersedes
    // subscription expiry; never modifies expires_at / never deletes the subscription.
    // ------------------------------------------------------------------------

    /**
     * Find the company's active subscription (or most-recent inactive one) and
     * force it back to active=true so an override always lands on the same row
     * that SubscriptionAccessService::hasAccess() reads. Wrapped in a transaction
     * + lockForUpdate to prevent concurrent admin requests from creating duplicates.
     */
    private function getOrCreateActiveSubscription(int $companyId): Subscription
    {
        return DB::transaction(function () use ($companyId) {
            $sub = Subscription::where('company_id', $companyId)
                ->orderByDesc('active')   // prefer active rows
                ->orderByDesc('id')        // then most recent
                ->lockForUpdate()
                ->first();
            if (!$sub) {
                $sub = Subscription::create([
                    'company_id' => $companyId,
                    'pricing_plan_id' => null,
                    'billing_cycle' => 'monthly',
                    'discount_percent' => 0,
                    'final_price' => 0,
                    'start_date' => now()->toDateString(),
                    'end_date' => null,
                    'active' => true,
                ]);
            } elseif (!$sub->active) {
                // Re-activate so hasAccess() (which filters active=true) reads it.
                $sub->update(['active' => true]);
            }
            return $sub;
        });
    }

    /**
     * A subscription grant should unlock the company it is given to. Lift a
     * pending / not-yet-approved company to approved + active so the grant
     * takes effect immediately. Never reverses a deliberate suspension or
     * rejection — use unsuspend / approve for those.
     */
    private function activateForGrant(Company $company): void
    {
        // Old trial-nag notifications are stale the moment an override is granted —
        // clear them so the dashboard doesn't keep showing "trial ending" messages.
        \App\Models\Notification::where('company_id', $company->id)
            ->whereIn('type', ['trial_reminder_day_1', 'trial_reminder_inv_5'])
            ->delete();

        if (in_array($company->status, ['suspended', 'rejected'], true)
            || in_array($company->company_status, ['suspended', 'rejected'], true)) {
            return;
        }
        if ($company->status !== 'approved' || $company->company_status !== 'active') {
            $company->update(['status' => 'approved', 'company_status' => 'active']);
        }
    }

    public function grantLifetime(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);
        $company = Company::findOrFail($id);
        $sub = $this->getOrCreateActiveSubscription($company->id);
        $sub->update([
            'override_type' => 'lifetime',
            'override_until' => null,
            'override_granted_at' => now(),
            'free_invoice_limit' => null,
            'override_reason' => $request->input('reason', 'Lifetime free access granted by admin'),
            'override_by' => auth('admin')->id(),
        ]);
        $this->activateForGrant($company);
        AdminAuditLog::log(auth('admin')->id(), 'Override granted: LIFETIME', 'Subscription', $sub->id, [
            'company' => $company->name, 'reason' => $sub->override_reason,
        ]);
        return back()->with('success', "Lifetime free access granted to '{$company->name}'.");
    }

    public function grantTemporary(Request $request, $id)
    {
        $request->validate([
            'until' => 'required|date|after:today',
            'free_invoice_limit' => 'nullable|integer|min:1|max:1000000',
            'reason' => 'nullable|string|max:255',
        ]);
        $company = Company::findOrFail($id);
        $limit = $request->filled('free_invoice_limit') ? (int) $request->input('free_invoice_limit') : null;
        $limitLabel = $limit === null ? 'unlimited' : (string) $limit;
        $sub = $this->getOrCreateActiveSubscription($company->id);
        $sub->update([
            'override_type' => 'temporary',
            'override_until' => $request->input('until'),
            'override_granted_at' => now(),
            'free_invoice_limit' => $limit,
            'override_reason' => $request->input('reason') ?: 'Temporary access granted by admin',
            'override_by' => auth('admin')->id(),
        ]);
        $this->activateForGrant($company);
        AdminAuditLog::log(auth('admin')->id(), 'Override granted: TEMPORARY', 'Subscription', $sub->id, [
            'company' => $company->name, 'until' => $sub->override_until?->toDateString(), 'invoices' => $limitLabel, 'reason' => $sub->override_reason,
        ]);
        return back()->with('success', "Temporary access granted to '{$company->name}' until " . $sub->override_until->format('Y-m-d') . " ({$limitLabel} invoices).");
    }

    public function removeOverride($id)
    {
        $company = Company::findOrFail($id);
        $sub = Subscription::where('company_id', $company->id)->orderByDesc('id')->first();
        if (!$sub) {
            return back()->with('error', 'No subscription found for this company.');
        }
        $oldType = $sub->override_type;
        $sub->update([
            'override_type' => 'none',
            'override_until' => null,
            'override_granted_at' => null,
            'free_invoice_limit' => null,
            'override_reason' => null,
            'override_by' => null,
        ]);
        AdminAuditLog::log(auth('admin')->id(), 'Override REMOVED', 'Subscription', $sub->id, [
            'company' => $company->name, 'previous_type' => $oldType,
        ]);
        return back()->with('success', "Override removed for '{$company->name}'. Normal subscription rules now apply.");
    }

    /**
     * Re-queue one or more exempt_internal PRA bills as 'pending' so the
     * Desktop Agent can submit them at TaxRate 0.
     *
     * POST /admin/companies/{id}/requeue-exempt-internal
     * Body: ids[] (required — comma-separated or array of pos_transaction IDs)
     *
     * Safety invariants (same as the artisan command):
     *   - Only touches rows that are STILL exempt_internal AND have no PRA invoice number.
     *   - Only touches rows belonging to this company.
     *   - Super-admin only.
     */
    public function requeueExemptInternal(\Illuminate\Http\Request $request, $id)
    {
        $this->assertSuperAdmin();

        $company = Company::findOrFail($id);
        if ($company->product_type !== 'pos') {
            return back()->with('error', 'This action is only available for PRA POS companies.');
        }

        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|min:1',
        ]);

        $requestedIds = array_map('intval', $request->input('ids'));

        $updated = \Illuminate\Support\Facades\DB::table('pos_transactions')
            ->where('company_id', $company->id)          // scope to this company only
            ->whereIn('id', $requestedIds)
            ->where('pra_status', 'exempt_internal')       // safety: never touch other statuses
            ->whereNull('pra_invoice_number')              // safety: never overwrite a submitted row
            ->update([
                'pra_status' => 'pending',
                'updated_at' => now(),
            ]);

        AdminAuditLog::log(auth('admin')->id(), 'PRA exempt_internal re-queued', 'PosTransaction', null, [
            'company'      => $company->name,
            'company_id'   => $company->id,
            'requested_ids' => $requestedIds,
            'updated_count' => $updated,
        ]);

        if ($updated === 0) {
            return back()->with('error', 'No eligible bills found — they may have already been re-queued or submitted.');
        }

        return back()->with('success', "{$updated} bill(s) set to pending. The Desktop Agent will submit them at TaxRate 0 on its next poll.");
    }
}
