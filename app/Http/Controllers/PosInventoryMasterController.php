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
}
