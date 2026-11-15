<?php
namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\HospitalReceiptPreferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class HospitalReceiptSettingsController extends Controller
{
    private function company(): Company
    {
        $user = Auth::guard('fbrpos')->user();
        abort_unless($user && $user->is_active && ($user->role === 'company_admin' || in_array($user->pos_role, ['pos_admin','pos_manager','pos_cashier'], true)), 403);
        abort_unless(app(\App\Services\BranchContextService::class)->isOwner($user), 403);
        $company = Company::findOrFail($user->company_id);
        abort_unless($company->isHospitalCollectionPilot(), 404);
        view()->share('hospitalCanConfigure', true);
        return $company;
    }

    public function index()
    {
        $company = $this->company();
        $preferences = HospitalReceiptPreferences::for($company);
        return response()->view('fbr-pos.hospital.printing', compact('company', 'preferences'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request)
    {
        $company = $this->company();
        abort_if((bool) $request->session()->get('impersonation.readonly'), 403);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'paper' => 'required|in:58mm,80mm,a4', 'margin_mm' => 'required|integer|min:0|max:10',
            'printer_label' => 'nullable|string|max:100',
            'show_logo' => 'nullable|boolean', 'show_contact' => 'nullable|boolean', 'center' => 'nullable|boolean',
        ]);
        $company->fill(collect($data)->only(['name', 'address', 'phone', 'email'])->all());
        if ($request->hasFile('logo')) {
            // Do not remove older files: historical/other product references may still use them.
            $company->logo_path = $request->file('logo')->store('company-logos', 'public');
        }
        $company->save();
        $company->mergeJsonColumn('invoice_display_prefs', function (array $prefs) use ($data, $request) {
            $prefs['hospital_receipt'] = array_merge(is_array($prefs['hospital_receipt'] ?? null) ? $prefs['hospital_receipt'] : [], [
                'paper' => $data['paper'], 'margin_mm' => (int) $data['margin_mm'],
                'printer_label' => $data['printer_label'] ?? '',
                'show_logo' => $request->boolean('show_logo'), 'show_contact' => $request->boolean('show_contact'),
                'center' => $request->boolean('center'),
            ]);
            return $prefs;
        });
        return redirect('/fbr-pos/hospital/printing')->with('status', __('hospital_print.saved'));
    }

    public function preview()
    {
        $company = $this->company();
        // Synthetic sample only. Never insert a bill, receipt, payment or fiscal request.
        $key = (string) Str::uuid();
        $invoice = (object) ['id'=>'PREVIEW', 'request_key'=>$key, 'patient_reference'=>'DEMO-001',
            'service_head'=>'consultation', 'total_paisa'=>200000];
        $payment = (object) ['id'=>'PREVIEW', 'request_key'=>$key, 'branch_id'=>null,
            'collected_at'=>now()->format('Y-m-d H:i:s'), 'payment_method'=>'bank',
            'campus_name'=>__('hospital_print.sample_campus'), 'cashier_name'=>__('hospital_print.sample_cashier'),
            'amount_paisa'=>150000, 'balance_paisa'=>50000];
        return response()->view('fbr-pos.hospital.receipt', compact('company','invoice','payment') + ['preview'=>true])
            ->header('Cache-Control', 'private, no-store');
    }
}
