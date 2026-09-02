<?php

namespace App\Http\Controllers;

use App\Models\PosTransaction;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchContextService;
use App\Services\LocalViewerService;
use App\Services\ViewablePasswordService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PosLocalBillsController extends Controller
{
    /**
     * Local Bills Portal — accessible by pos_role='local_viewer' accounts AND by
     * POS admins of the company (owner request Jul 2026). The ONLY surface where
     * local (non-PRA) bills are visible: live local bills plus those already
     * archived at day-close / Local Final. Cashiers cannot see this data
     * (PosAuth confines access).
     */
    /**
     * Every local bill this portal may show — the SINGLE choke point for the
     * list, its totals (including the "Aaj" figures), the bill page and the CSV
     * export, so the screen and the export can never disagree (Task 1361).
     *
     * Branch scoping rides here too: a local bill is real money that never
     * reaches PRA, so a branch's audit login must not see (or export) another
     * branch's takings. applyToQuery is a no-op for a single-branch shop and for
     * the company-wide view, and legacy pre-branch rows (branch_id NULL) always
     * stay visible.
     */
    private function baseQuery(int $companyId)
    {
        $query = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('status', 'completed')
            // The portal is the local *stream*, not merely the old L-series
            // shape. Reporting-OFF finals retain a PRA/NULL invoice_mode but
            // have neither PRA status nor fiscal number. Keep this deliberately
            // explicit: no row that entered the PRA pipeline may leak here.
            ->where(function ($stream) {
                $stream->where(function ($local) {
                    $local->where('invoice_mode', 'local')
                        ->whereNull('pra_invoice_number')
                        ->where(function ($status) {
                            $status->whereNull('pra_status')
                                ->orWhere('pra_status', 'local');
                        });
                })
                    ->orWhere(function ($final) {
                        $final->whereNull('pra_status')
                            ->whereNull('pra_invoice_number')
                            ->where(function ($mode) {
                                $mode->where('invoice_mode', 'pra')
                                    ->orWhereNull('invoice_mode');
                            });
                    });
            });

        app(BranchContextService::class)->applyToQuery($query, 'branch_id');

        return $query;
    }

    public function index(Request $request)
    {
        $companyId = app('currentCompanyId');

        $query = $this->baseQuery($companyId);

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('invoice_number', 'like', "%{$q}%")
                  ->orWhere('customer_name', 'like', "%{$q}%")
                  ->orWhere('customer_phone', 'like', "%{$q}%");
            });
        }
        // Date filters + "Aaj" stats follow the BUSINESS day (owner rule 26 Jul
        // 2026): after-midnight bills (before 6 AM, day un-closed) belong to the
        // previous trading day.
        if ($from = $request->input('from')) {
            $query->where('business_date', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('business_date', '<=', $to);
        }
        if ($cashier = $request->input('cashier')) {
            $query->where('created_by', $cashier);
        }

        $bizToday = \App\Services\PosBusinessDay::current($companyId);
        $stats = [
            'total' => (clone $query)->count(),
            'sum' => (clone $query)->sum('total_amount'),
            'today' => $this->baseQuery($companyId)->where('business_date', $bizToday)->count(),
            'today_sum' => $this->baseQuery($companyId)->where('business_date', $bizToday)->sum('total_amount'),
        ];

        $bills = $query->with(['creator', 'items'])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        $cashiers = User::where('company_id', $companyId)
            ->whereIn('pos_role', ['pos_admin', 'pos_cashier'])
            ->orderBy('name')
            ->get(['id', 'name']);

        // ── Viewer Account management (Task 665) — OWNER ONLY ──────────────
        // Only the real owner (base role company_admin) gets the section and
        // its decrypted passwords. A pos_manager viewing this portal sees the
        // bills exactly as before and nothing else; the endpoints 403 for him
        // too (hiding UI alone is never the gate).
        $canManageViewers = (bool) auth('pos')->user()?->canManageLocalViewers();
        $viewers = collect();
        $viewerPasswords = [];
        if ($canManageViewers) {
            $viewers = $this->viewerQuery($companyId)->orderBy('id', 'desc')->get();
            foreach ($viewers as $v) {
                // reveal() = the single read path (APP_KEY rotation / corrupt
                // payload degrades to "not stored", never a 500).
                $plain = ViewablePasswordService::reveal($v->pos_team_password_enc);
                if ($plain !== null) {
                    $viewerPasswords[$v->id] = $plain;
                }
            }
        }
        $viewerCap = LocalViewerService::MAX_PER_COMPANY;

        return view('pos.local.index', compact(
            'bills', 'stats', 'cashiers',
            'canManageViewers', 'viewers', 'viewerPasswords', 'viewerCap'
        ));
    }

    public function show($id)
    {
        $companyId = app('currentCompanyId');
        $bill = $this->baseQuery($companyId)
            ->with(['items', 'creator', 'company'])
            ->findOrFail($id);

        return view('pos.local.show', compact('bill'));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $companyId = app('currentCompanyId');
        $query = $this->baseQuery($companyId);

        if ($from = $request->input('from')) {
            $query->where('business_date', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('business_date', '<=', $to);
        }

        $bills = $query->with('creator')->orderBy('created_at', 'desc')->get();
        $filename = 'local-bills-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($bills) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Invoice #', 'Created At', 'Cashier', 'Customer', 'Phone',
                'Subtotal', 'Discount', 'Tax', 'Total',
                'Payment Method', 'Status', 'Archived',
            ]);
            foreach ($bills as $b) {
                fputcsv($out, [
                    $b->invoice_number,
                    $b->created_at?->format('Y-m-d H:i:s'),
                    $b->creator->name ?? 'N/A',
                    $b->customer_name ?? '',
                    $b->customer_phone ?? '',
                    $b->subtotal,
                    $b->discount_amount,
                    $b->tax_amount,
                    $b->total_amount,
                    $b->payment_method,
                    $b->pra_status,
                    $b->is_archived ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Viewer Accounts — self-service (Task 665, owner request)
    //
    // The read-only local_viewer login used to be super-admin-only. The company
    // OWNER can now create/manage it himself from this portal. Mirrors the SaaS
    // flow (AdminCompanyController::storeLocalViewer et al) exactly — same
    // role/pos_role/is_active shape — so both panels manage the SAME accounts.
    //
    // Gate: role === 'company_admin' on EVERY endpoint (never isPosAdmin(),
    // which counts pos_manager as admin). PosAuth already lets POS admins into
    // this prefix, so the manager reaches these routes and MUST be stopped here.
    // ══════════════════════════════════════════════════════════════════════

    /** Company-scoped viewer-account query (shared with the SaaS admin panel). */
    private function viewerQuery(int $companyId)
    {
        return LocalViewerService::query($companyId);
    }

    /** Owner-only guard: anyone else (manager, cashier, the viewer itself) → 403. */
    private function assertOwner(): void
    {
        $user = auth('pos')->user();
        if (!$user || !$user->canManageLocalViewers()) {
            abort(403);
        }
    }

    public function storeViewer(Request $request)
    {
        $this->assertOwner();
        $companyId = app('currentCompanyId');

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        // Team-account plan quota does NOT apply: PlanLimitService::canAddPosUser
        // counts pos_manager + pos_cashier only, so read-only portal logins have
        // always been quota-exempt (SaaS-created ones too). Nothing to charge here.
        //
        // The cap is company-wide (shared with the SaaS admin panel) and checked
        // inside the service's locked transaction — null = already at capacity.
        $user = LocalViewerService::create($companyId, $request->name, $request->email, $request->password);
        if (!$user) {
            return back()->with('error', LocalViewerService::capacityMessage());
        }

        AuditLogService::log(
            'pos_local_viewer_created',
            'User',
            $user->id,
            null,
            ['name' => $user->name, 'email' => $user->email],
            $companyId,
            auth('pos')->id()
        );

        return back()->with('success', "Viewer account created — {$user->email} can now sign in at /pos/login.");
    }

    public function updateViewer(Request $request, $userId)
    {
        $this->assertOwner();
        $companyId = app('currentCompanyId');
        $viewer = $this->viewerQuery($companyId)->findOrFail($userId);

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:190|unique:users,email,' . $viewer->id,
            'password' => 'nullable|string|min:8',
        ]);

        $old = ['name' => $viewer->name, 'email' => $viewer->email];
        $update = ['name' => $request->name, 'email' => $request->email];
        if ($request->filled('password')) {
            $update['password'] = bcrypt($request->password);
            $update = ViewablePasswordService::apply($update, $request->password);
        }
        $viewer->update($update);

        AuditLogService::log(
            'pos_local_viewer_updated',
            'User',
            $viewer->id,
            $old,
            ['name' => $viewer->name, 'email' => $viewer->email, 'password_changed' => $request->filled('password')],
            $companyId,
            auth('pos')->id()
        );

        return back()->with('success', 'Viewer account updated.');
    }

    public function toggleViewer($userId)
    {
        $this->assertOwner();
        $companyId = app('currentCompanyId');
        $viewer = $this->viewerQuery($companyId)->findOrFail($userId);

        $viewer->update(['is_active' => !$viewer->is_active]);

        AuditLogService::log(
            $viewer->is_active ? 'pos_local_viewer_activated' : 'pos_local_viewer_deactivated',
            'User',
            $viewer->id,
            ['is_active' => !$viewer->is_active],
            ['is_active' => (bool) $viewer->is_active],
            $companyId,
            auth('pos')->id()
        );

        return back()->with('success', $viewer->is_active ? 'Viewer account enabled.' : 'Viewer account disabled.');
    }

    public function deleteViewer($userId)
    {
        $this->assertOwner();
        $companyId = app('currentCompanyId');
        $viewer = $this->viewerQuery($companyId)->findOrFail($userId);

        AuditLogService::log(
            'pos_local_viewer_deleted',
            'User',
            $viewer->id,
            ['name' => $viewer->name, 'email' => $viewer->email],
            null,
            $companyId,
            auth('pos')->id()
        );

        $viewer->delete();

        return back()->with('success', 'Viewer account removed.');
    }
}
