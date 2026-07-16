<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Services\SubscriptionAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AdminPaymentProofController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('payment_proofs')) {
            return view('saas-admin.payment-proofs', [
                'proofs' => collect(),
                'plans' => collect(),
                'status' => 'pending',
                'tableMissing' => true,
            ]);
        }

        $status = $request->get('status', 'pending');

        $query = PaymentProof::with(['company', 'pricingPlan'])->orderByDesc('created_at');
        if (in_array($status, ['pending', 'verified', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $proofs = $query->paginate(20)->appends($request->all());
        $plans = PricingPlan::where('is_trial', false)->orderBy('price')->get();

        return view('saas-admin.payment-proofs', [
            'proofs' => $proofs,
            'plans' => $plans,
            'status' => $status,
            'tableMissing' => false,
        ]);
    }

    public function approve(Request $request, $id)
    {
        $request->validate([
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual,yearly',
        ]);

        $proof = PaymentProof::findOrFail($id);

        // Race-safe: lock the row, bail out if another admin already processed it.
        $subscription = DB::transaction(function () use ($proof, $request) {
            $locked = PaymentProof::where('id', $proof->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                return null;
            }

            $sub = SubscriptionAssignmentService::assign(
                $locked->company_id,
                (int) $request->pricing_plan_id,
                $request->billing_cycle
            );

            $locked->update([
                'status' => 'verified',
                'pricing_plan_id' => $request->pricing_plan_id,
                'billing_cycle' => SubscriptionAssignmentService::normalizeCycle($request->billing_cycle),
                'subscription_id' => $sub->id,
                'verified_by' => auth('admin')->id(),
                'verified_at' => now(),
                'reject_reason' => null,
            ]);

            return $sub;
        });

        if (!$subscription) {
            return back()->with('error', 'This payment proof was already processed.');
        }

        AdminAuditLog::log(auth('admin')->id(), 'Payment proof approved', 'PaymentProof', $proof->id, [
            'company_id' => $proof->company_id,
            'subscription_id' => $subscription->id,
        ]);

        return back()->with('success', 'Payment approved & subscription activated — company is now unlocked.');
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'reject_reason' => 'nullable|string|max:255',
        ]);

        $proof = PaymentProof::findOrFail($id);

        $updated = PaymentProof::where('id', $proof->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'reject_reason' => $request->reject_reason,
                'verified_by' => auth('admin')->id(),
                'verified_at' => now(),
            ]);

        if (!$updated) {
            return back()->with('error', 'This payment proof was already processed.');
        }

        AdminAuditLog::log(auth('admin')->id(), 'Payment proof rejected', 'PaymentProof', $proof->id, [
            'company_id' => $proof->company_id,
            'reason' => $request->reject_reason,
        ]);

        return back()->with('success', 'Payment proof rejected. The company can submit a new one.');
    }

    public function download($id)
    {
        $proof = PaymentProof::findOrFail($id);

        // Retention cleanup removed the file — the row is audit-only now.
        if ($proof->file_pruned_at) {
            abort(410, 'This receipt file was removed by the retention cleanup. The record is kept for audit.');
        }

        if (!$proof->proof_path || !Storage::disk('local')->exists($proof->proof_path)) {
            abort(404, 'Proof file not found.');
        }

        return Storage::disk('local')->download($proof->proof_path);
    }
}
