<?php

namespace App\Http\Controllers;

use App\Services\BranchContextService;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    public function switch(Request $request, BranchContextService $svc)
    {
        // Task 1347: 'all' is the owner-only company-wide view (PRA POS panel);
        // every other value must be a branch id the user may actually reach.
        $request->validate(['branch_id' => 'required']);
        $raw = $request->input('branch_id');
        $target = $raw === BranchContextService::ALL ? BranchContextService::ALL : (int) $raw;

        if ($target !== BranchContextService::ALL && $target <= 0) {
            return back()->with('error', __('pos.branch_switch_denied'));
        }

        if (!$svc->setActiveBranch($target)) {
            return back()->with('error', __('pos.branch_switch_denied'));
        }

        return back()->with('success', $target === BranchContextService::ALL
            ? __('pos.branch_switched_all')
            : __('pos.branch_switched'));
    }
}
