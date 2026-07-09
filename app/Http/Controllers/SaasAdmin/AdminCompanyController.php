<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\AdminAuditLog;
use App\Models\CompanyUsageStat;
use App\Models\Invoice;
use App\Models\PosTransaction;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Franchise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Services\CredentialLedgerService;

class AdminCompanyController extends Controller
{
    public function create()
    {
        $franchises = Franchise::where('status', 'active')->get();
        return view('saas-admin.companies.create', compact('franchises'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'product_type' => 'required|in:di,pos,fbrpos',
            'email' => 'required|email|max:255',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'business_activity' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'status' => 'required|in:approved,pending',
            'franchise_id' => 'nullable|exists:franchises,id',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:6',
            'admin_name' => 'required|string|max:255',
        ]);

        $companyData = [
            'name' => $request->name,
            'owner_name' => $request->owner_name,
            'product_type' => $request->product_type,
            'email' => $request->email,
            'ntn' => $request->ntn,
            'cnic' => $request->cnic,
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

        // Record credentials in the anti-reuse ledger (admin creation is never blocked).
        CredentialLedgerService::record([
            'email' => $request->admin_email,
            'phone' => $request->phone ?: $request->mobile,
            'ntn' => $request->ntn,
            'cnic' => $request->cnic,
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
        return view('saas-admin.companies.edit', compact('company', 'franchises', 'companyAdmin'));
    }

    public function update(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        $request->validate([
            'name' => 'required|string|max:255',
            'owner_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'business_activity' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'franchise_id' => 'nullable|exists:franchises,id',
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
        ]);

        if ($request->filled('admin_password') || $request->filled('admin_email')) {
            $companyAdmin = User::where('company_id', $id)->where('role', 'company_admin')->first();
            if ($companyAdmin) {
                if ($request->filled('admin_password')) {
                    $companyAdmin->password = Hash::make($request->admin_password);
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

        $company->update($request->only($fields));

        AdminAuditLog::log(auth('admin')->id(), 'Company profile updated', 'Company', $id, ['name' => $company->name]);
        return redirect()->route('saas.admin.companies.show', $id)->with('success', "Company '{$company->name}' updated successfully.");
    }

    public function index(Request $request)
    {
        $query = Company::query()->with(['franchise', 'activeSubscription']);

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
        return view('saas-admin.companies.index', compact('companies'));
    }

    public function show($id)
    {
        $company = Company::withTrashed()->with(['franchise', 'activeSubscription'])->findOrFail($id);
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
        } else {
            $extraStats['total_transactions'] = PosTransaction::where('company_id', $id)->where('status', 'completed')->count();
            $extraStats['total_revenue'] = PosTransaction::where('company_id', $id)->where('status', 'completed')->sum('total_amount');
            $extraStats['today_transactions'] = PosTransaction::where('company_id', $id)->where('status', 'completed')->whereDate('created_at', today())->count();
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

        return view('saas-admin.companies.show', compact('company', 'usageStats', 'extraStats', 'archiveViewers', 'localViewers'));
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
        AdminAuditLog::log(auth('admin')->id(), 'Company approved', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been approved.");
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
        $company->forceDelete();
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

    // ========================================================================
    // SUBSCRIPTION OVERRIDE + USAGE LIMIT — admin-only actions
    // Rule: only ONE override active at a time; override always supersedes
    // subscription expiry; never modifies expires_at / never deletes the subscription.
    // ========================================================================

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

    public function grantLifetime(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);
        $company = Company::findOrFail($id);
        $sub = $this->getOrCreateActiveSubscription($company->id);
        $sub->update([
            'override_type' => 'lifetime',
            'override_until' => null,
            'free_invoice_limit' => null,
            'override_reason' => $request->input('reason', 'Lifetime free access granted by admin'),
            'override_by' => auth('admin')->id(),
        ]);
        AdminAuditLog::log(auth('admin')->id(), 'Override granted: LIFETIME', 'Subscription', $sub->id, [
            'company' => $company->name, 'reason' => $sub->override_reason,
        ]);
        return back()->with('success', "Lifetime free access granted to '{$company->name}'.");
    }

    public function grantTemporary(Request $request, $id)
    {
        $request->validate([
            'until' => 'required|date|after:today',
            'reason' => 'nullable|string|max:255',
        ]);
        $company = Company::findOrFail($id);
        $sub = $this->getOrCreateActiveSubscription($company->id);
        $sub->update([
            'override_type' => 'temporary',
            'override_until' => $request->input('until'),
            'free_invoice_limit' => null,
            'override_reason' => $request->input('reason', 'Temporary access granted by admin'),
            'override_by' => auth('admin')->id(),
        ]);
        AdminAuditLog::log(auth('admin')->id(), 'Override granted: TEMPORARY', 'Subscription', $sub->id, [
            'company' => $company->name, 'until' => $sub->override_until?->toDateString(), 'reason' => $sub->override_reason,
        ]);
        return back()->with('success', "Temporary access granted to '{$company->name}' until " . $sub->override_until->format('Y-m-d') . '.');
    }

    public function grantGrace(Request $request, $id)
    {
        $request->validate([
            'days' => 'required|integer|min:1|max:90',
            'reason' => 'nullable|string|max:255',
        ]);
        $company = Company::findOrFail($id);
        $sub = $this->getOrCreateActiveSubscription($company->id);
        $until = now()->addDays((int) $request->input('days'));
        $sub->update([
            'override_type' => 'grace',
            'override_until' => $until,
            'free_invoice_limit' => null,
            'override_reason' => $request->input('reason', $request->input('days') . '-day grace period'),
            'override_by' => auth('admin')->id(),
        ]);
        AdminAuditLog::log(auth('admin')->id(), 'Override granted: GRACE', 'Subscription', $sub->id, [
            'company' => $company->name, 'days' => $request->input('days'), 'until' => $until->toDateString(),
        ]);
        return back()->with('success', "Grace period of {$request->input('days')} days granted to '{$company->name}'.");
    }

    public function grantUsageFree(Request $request, $id)
    {
        $request->validate([
            'free_invoice_limit' => 'required|integer|min:1|max:1000000',
            'reason' => 'nullable|string|max:255',
        ]);
        $company = Company::findOrFail($id);
        $sub = $this->getOrCreateActiveSubscription($company->id);
        $sub->update([
            'override_type' => 'usage_free',
            'override_until' => null,
            'free_invoice_limit' => (int) $request->input('free_invoice_limit'),
            'override_reason' => $request->input('reason', "Free invoice limit: " . $request->input('free_invoice_limit')),
            'override_by' => auth('admin')->id(),
        ]);
        AdminAuditLog::log(auth('admin')->id(), 'Override granted: USAGE_FREE', 'Subscription', $sub->id, [
            'company' => $company->name, 'limit' => $sub->free_invoice_limit,
        ]);
        return back()->with('success', "Free invoice limit of {$sub->free_invoice_limit} granted to '{$company->name}'.");
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
            'free_invoice_limit' => null,
            'override_reason' => null,
            'override_by' => null,
        ]);
        AdminAuditLog::log(auth('admin')->id(), 'Override REMOVED', 'Subscription', $sub->id, [
            'company' => $company->name, 'previous_type' => $oldType,
        ]);
        return back()->with('success', "Override removed for '{$company->name}'. Normal subscription rules now apply.");
    }
}
