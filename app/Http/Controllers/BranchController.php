<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');
        $branches = Branch::where('company_id', $companyId)
            ->withCount('invoices')
            ->orderBy('is_head_office', 'desc')
            ->orderBy('name')
            ->get();

        return view('branches.index', compact('branches'));
    }

    public function create()
    {
        return view('branches.create');
    }

    public function store(Request $request)
    {
        $companyId = app('currentCompanyId');
        $limitCheck = \App\Services\PlanLimitService::canAddBranch($companyId);
        if (!$limitCheck['allowed']) {
            return back()->with('error', $limitCheck['reason']);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:1000',
            'is_head_office' => 'nullable|boolean',
        ]);

        if ($request->is_head_office) {
            Branch::where('company_id', $companyId)->update(['is_head_office' => false]);
        }

        $branch = Branch::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'address' => $request->address,
            'is_head_office' => $request->boolean('is_head_office'),
        ]);

        AuditLogService::log('branch_created', 'Branch', $branch->id, null, [
            'name' => $request->name,
        ]);

        return redirect('/branches')->with('success', 'Branch created successfully.');
    }

    public function edit(Branch $branch)
    {
        $companyId = app('currentCompanyId');
        if ($branch->company_id !== $companyId) {
            abort(403);
        }

        return view('branches.edit', compact('branch'));
    }

    public function update(Request $request, Branch $branch)
    {
        $companyId = app('currentCompanyId');
        if ($branch->company_id !== $companyId) {
            abort(403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:1000',
            'is_head_office' => 'nullable|boolean',
        ]);

        if ($request->is_head_office) {
            Branch::where('company_id', $companyId)->where('id', '!=', $branch->id)->update(['is_head_office' => false]);
        }

        $oldName = $branch->name;
        $branch->update([
            'name' => $request->name,
            'address' => $request->address,
            'is_head_office' => $request->boolean('is_head_office'),
        ]);

        AuditLogService::log('branch_edited', 'Branch', $branch->id, ['name' => $oldName], ['name' => $request->name]);

        return redirect('/branches')->with('success', 'Branch updated successfully.');
    }

    public function destroy(Branch $branch)
    {
        $companyId = app('currentCompanyId');
        if ($branch->company_id !== $companyId) {
            abort(403);
        }

        if ($branch->invoices()->count() > 0) {
            return redirect('/branches')->with('error', 'Cannot delete branch with existing invoices.');
        }

        $branchName = $branch->name;
        $branchId = $branch->id;
        $branch->delete();

        AuditLogService::log('branch_deleted', 'Branch', $branchId, ['name' => $branchName], null);

        return redirect('/branches')->with('success', 'Branch deleted successfully.');
    }
}
