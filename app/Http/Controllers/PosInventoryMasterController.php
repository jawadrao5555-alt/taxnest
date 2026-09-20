<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\PosFeatureService;
use App\Services\PosInventoryMasterExcelService;
use App\Services\SubscriptionAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PosInventoryMasterController extends Controller
{
    public function __construct(private PosInventoryMasterExcelService $master)
    {
    }

    public function index()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $excelOn = $this->master->excelAllowed($company);
        $recipesOn = $this->master->recipesAllowed($company);
        if (!$excelOn && !$recipesOn) {
            return redirect()->route('pos.billing')->with('error', __('pos.plan_locked_feature'));
        }

        $inventoryOn = (bool) ($company?->inventory_enabled)
            && PosFeatureService::moduleAvailable($company, 'inventory');

        return view('pos.inventory.master', compact(
            'company', 'excelOn', 'recipesOn', 'inventoryOn'
        ));
    }

    public function downloadTemplate()
    {
        $company = Company::find(app('currentCompanyId'));
        if (!$this->master->excelAllowed($company) && !$this->master->recipesAllowed($company)) {
            return redirect()->route('pos.billing')->with('error', __('pos.plan_locked_feature'));
        }

        return $this->master->streamTemplate();
    }

    public function import(Request $request)
    {
        $companyId = (int) app('currentCompanyId');
        $company = Company::find($companyId);

        if (Schema::hasTable('subscriptions') && $company) {
            $access = SubscriptionAccessService::hasAccess($company);
            if (!$access['allowed']) {
                return back()->with('error', SubscriptionAccessService::localizedLockReason($access['reason']));
            }
        }

        if (!$this->master->excelAllowed($company) && !$this->master->recipesAllowed($company)) {
            return redirect()->route('pos.billing')->with('error', __('pos.plan_locked_feature'));
        }

        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls|max:5120',
        ], [
            'excel_file.required' => __('pos.inventory_master_file_required'),
            'excel_file.mimes' => __('pos.inventory_master_file_mimes'),
            'excel_file.max' => __('pos.inventory_master_file_max'),
        ]);

        $result = $this->master->import(
            $request->file('excel_file')->getRealPath(),
            $companyId,
            $company
        );

        $flash = $result['errors'] ?: [];
        if ($result['ok']) {
            return back()->with('success', $result['message'])->with('inventory_master_errors', $flash);
        }

        return back()->with('error', $result['message'])->with('inventory_master_errors', $flash);
    }

    public function preview(Request $request)
    {
        $company = Company::find((int) app('currentCompanyId'));
        if (!$this->hasImportAccess($company)) {
            return back()->with('error', __('pos.plan_locked_feature'));
        }
        $request->validate(['excel_file'=>'required|file|mimes:xlsx|max:5120','mode'=>'nullable|in:create_only,update_existing']);
        $actorId = $this->actorId();
        abort_if($actorId === null, 403);
        $result=$this->master->preview($request->file('excel_file')->getRealPath(),(int)app('currentCompanyId'),$request->input('mode','create_only'),$actorId);
        $flashResult = $result;
        $flashResult['errors'] = array_slice($result['errors'] ?? [], 0, 100);
        $flashResult['warnings'] = array_slice($result['warnings'] ?? [], 0, 100);
        return back()->with('inventory_master_preview', $flashResult)
            ->with($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Preview ready. Confirm to apply.' : 'Preview contains blocking errors.');
    }

    public function confirm(Request $request)
    {
        $company = Company::find((int) app('currentCompanyId'));
        if (!$this->hasImportAccess($company)) {
            return back()->with('error', __('pos.plan_locked_feature'));
        }
        $request->validate(['token'=>'required|string','confirmed'=>'accepted']);
        $actorId = $this->actorId();
        abort_if($actorId === null, 403);
        $result=$this->master->confirm($request->input('token'),(int)app('currentCompanyId'),true,$actorId);
        return back()->with($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : implode(' ', array_map('strval', $result['errors'] ?? [])))
            ->with('inventory_master_errors', $result['errors'] ?? []);
    }

    public function export()
    {
        if (!$this->hasImportAccess(Company::find((int) app('currentCompanyId')))) {
            return redirect()->route('pos.billing')->with('error', __('pos.plan_locked_feature'));
        }
        return $this->master->exportWorkbook((int)app('currentCompanyId'));
    }

    public function errorReport(Request $request)
    {
        if (!$this->hasImportAccess(Company::find((int) app('currentCompanyId')))) {
            return redirect()->route('pos.billing')->with('error', __('pos.plan_locked_feature'));
        }
        return $this->master->errorReport((string)$request->input('token'), (int) app('currentCompanyId'), $this->actorId());
    }

    private function hasImportAccess(?Company $company): bool
    {
        if (auth('pos')->user()?->posCashierBlocked()) {
            return false;
        }
        if (!$company || (!$this->master->excelAllowed($company) && !$this->master->recipesAllowed($company))) {
            return false;
        }
        if (Schema::hasTable('subscriptions')) {
            $access = SubscriptionAccessService::hasAccess($company);
            return (bool) $access['allowed'];
        }
        return true;
    }

    private function actorId(): ?int
    {
        $id = auth('pos')->id() ?: auth()->id();

        return $id ? (int) $id : null;
    }
}
