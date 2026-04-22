<?php

namespace App\Http\Controllers;

use App\Services\BranchContextService;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    public function switch(Request $request, BranchContextService $svc)
    {
        $request->validate(['branch_id' => 'required|integer']);
        $branchId = (int) $request->branch_id;

        if (!$svc->setActiveBranch($branchId)) {
            return back()->with('error', 'You do not have access to that branch.');
        }

        return back()->with('success', 'Switched branch successfully.');
    }
}
