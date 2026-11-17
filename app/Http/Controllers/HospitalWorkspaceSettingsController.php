<?php
namespace App\Http\Controllers;

use App\Models\{Company, Branch};
use App\Services\{BranchContextService, PlanLimitService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class HospitalWorkspaceSettingsController extends Controller
{
    private function company(): Company
    {
        $user = Auth::guard('fbrpos')->user();
        abort_unless($user && $user->is_active && app(BranchContextService::class)->isOwner($user), 403);
        $company = Company::findOrFail($user->company_id);
        abort_unless($company->isHospitalCollectionPilot(), 404);
        view()->share('hospitalIsOwner', true);
        return $company;
    }
    public function index()
    {
        $company = $this->company();
        return response()->view('fbr-pos.hospital.settings', [
            'company' => $company, 'campuses' => Branch::where('company_id', $company->id)->get(),
            'services' => DB::table('hospital_services')->where('company_id', $company->id)->orderBy('label')->get(),
            'imsServiceSettingsAvailable' => Schema::hasColumns('hospital_services', ['ims_pct_code', 'ims_tax_rate']),
        ])->header('Cache-Control', 'private, no-store');
    }
    public function saveService(Request $request)
    {
        $company = $this->company();
        abort_if((bool) $request->session()->get('impersonation.readonly'), 403);
        if ($request->filled('ims_pct_code') && is_string($request->input('ims_pct_code'))) {
            $request->merge(['ims_pct_code' => str_replace('.', '', trim($request->input('ims_pct_code')))]);
        }
        $d = $request->validate(['code' => 'nullable|string|max:20', 'label' => 'required|string|max:80',
            'service_head' => 'required|in:consultation,lab,procedure', 'rate' => ['required','regex:/^\d{1,8}(?:\.\d{1,2})?$/'], 'active' => 'required|boolean',
            'ims_pct_code' => ['nullable', 'string', 'regex:/^[0-9]{8}$/D'],
            'ims_tax_rate' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
        ]);
        $d['label'] = trim($d['label']);
        if ($d['label'] === '') throw ValidationException::withMessages(['label' => __('hospital_workspace.label_required')]);
        [$whole, $fraction] = array_pad(explode('.', $d['rate'], 2), 2, '');
        $rate = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($rate < 1) throw ValidationException::withMessages(['rate' => __('hospital.positive_amount')]);
        DB::transaction(function () use ($company, $d, $rate) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $code = ($d['code'] ?? '') ?: null;
            if ($code) abort_unless(DB::table('hospital_services')->where('company_id',$company->id)->where('code',$code)->exists(),404);
            $code ??= 'h'.strtolower(Str::random(12));
            $imsSettings = [];
            foreach (['ims_pct_code', 'ims_tax_rate'] as $field) {
                if (array_key_exists($field, $d)) {
                    abort_unless(Schema::hasColumn('hospital_services', $field), 503);
                    $imsSettings[$field] = $d[$field] === '' ? null : $d[$field];
                }
            }
            DB::table('hospital_services')->updateOrInsert(['company_id'=>$company->id,'code'=>$code], $imsSettings + [
                'label'=>$d['label'],'service_head'=>$d['service_head'],'rate_paisa'=>$rate,'active'=>(bool)$d['active'],'updated_at'=>now(),
            ]);
        });
        return redirect()->away('/fbr-pos/hospital/settings');
    }
    public function addCampus(Request $request)
    {
        $company = $this->company();
        abort_if((bool) $request->session()->get('impersonation.readonly'),403);
        $d = $request->validate(['name'=>'required|string|max:255','city'=>'nullable|string|max:100','address'=>'nullable|string|max:1000']);
        $d['name']=trim($d['name']);
        if ($d['name']==='') throw ValidationException::withMessages(['name'=>__('hospital_workspace.label_required')]);
        DB::transaction(function() use ($company,$d) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $limit=PlanLimitService::canAddBranch($company->id);
            if (!($limit['allowed']??false)) throw ValidationException::withMessages(['name'=>$limit['reason']??__('school.campus_limit')]);
            if (Branch::where('company_id',$company->id)->where('name',$d['name'])->exists()) throw ValidationException::withMessages(['name'=>__('school.campus_duplicate')]);
            Branch::create(['company_id'=>$company->id,'name'=>$d['name'],'city'=>$d['city']??null,'address'=>$d['address']??null,'is_active'=>true,'is_head_office'=>!Branch::where('company_id',$company->id)->exists()]);
        });
        return redirect()->away('/fbr-pos/hospital/settings');
    }
}
