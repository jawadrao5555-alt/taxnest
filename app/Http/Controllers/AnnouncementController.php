<?php

namespace App\Http\Controllers;

use App\Models\AdminAnnouncement;
use App\Models\AnnouncementDismissal;
use App\Models\Company;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $query = AdminAnnouncement::with(['creator', 'targetCompany'])->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } else {
                $query->where('is_active', false);
            }
        }

        $announcements = $query->paginate(20);
        $companies = Company::orderBy('name')->get(['id', 'name']);
        $categoryGroups = [
            'pra' => PosFeatureService::categoryGroups('pra'),
            'fbr' => PosFeatureService::categoryGroups('fbr'),
        ];

        return view('admin.announcements', compact('announcements', 'companies', 'categoryGroups'));
    }

    public function store(Request $request)
    {
        $this->validateAudience($request);
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:2000',
            'type' => 'required|in:info,warning,urgent,success',
            'expires_at' => 'nullable|date|after:now',
        ]);

        AdminAnnouncement::create([
            'title' => $request->title,
            'message' => $request->message,
            'type' => $request->type,
            'target' => $request->target,
            'target_company_id' => $request->target === 'specific' ? $request->target_company_id : null,
            'audience_panel' => $request->target === 'specific' ? 'all' : $request->input('audience_panel', 'all'),
            'target_categories' => $this->categories($request),
            'expires_at' => $request->expires_at,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        return redirect('/admin/announcements')->with('success', 'Announcement published successfully.');
    }

    public function audiencePreview(Request $request)
    {
        $this->validateAudience($request);
        $announcement = new AdminAnnouncement([
            'target' => $request->input('target'),
            'target_company_id' => $request->input('target') === 'specific' ? $request->input('target_company_id') : null,
            'audience_panel' => $request->input('target') === 'specific' ? 'all' : $request->input('audience_panel', 'all'),
            'target_categories' => $this->categories($request),
        ]);

        $count = 0;
        $examples = [];
        foreach (Company::select(['id', 'name', 'product_type', 'business_category', 'pos_type'])->cursor() as $company) {
            if (!$announcement->reachesCompany($company)) {
                continue;
            }
            $count++;
            if (count($examples) < 5) {
                $examples[] = $company->name;
            }
        }

        return response()->json(['count' => $count, 'examples' => $examples]);
    }

    private function validateAudience(Request $request): void
    {
        $request->validate([
            'target' => 'required|in:all,specific',
            'target_company_id' => 'required_if:target,specific|nullable|exists:companies,id',
            'audience_panel' => 'required_if:target,all|nullable|in:all,pra,fbr',
            'audience_scope' => 'required_if:target,all|nullable|in:all,categories',
            'target_categories' => 'nullable|array',
            'target_categories.*' => 'string|max:60',
        ]);
        if ($request->input('target') !== 'all' || $request->input('audience_scope') !== 'categories') {
            return;
        }
        $selected = $request->input('target_categories', []);
        $panels = $request->input('audience_panel') === 'all' ? ['pra', 'fbr'] : [$request->input('audience_panel')];
        $allowed = [];
        foreach ($panels as $panel) {
            $allowed = array_merge($allowed, PosFeatureService::categories($panel));
        }
        if (!$selected || count($selected) !== count(array_unique($selected)) || array_diff($selected, $allowed)) {
            throw ValidationException::withMessages([
                'target_categories' => 'Select at least one category in the chosen panel before publishing.',
            ]);
        }
    }

    private function categories(Request $request): ?array
    {
        return $request->input('target') === 'all' && $request->input('audience_scope') === 'categories'
            ? array_values($request->input('target_categories')) : null;
    }

    public function toggle($id)
    {
        $announcement = AdminAnnouncement::findOrFail($id);
        $announcement->update(['is_active' => !$announcement->is_active]);
        return redirect('/admin/announcements')->with('success', 'Announcement ' . ($announcement->is_active ? 'activated' : 'deactivated') . '.');
    }

    public function destroy($id)
    {
        AdminAnnouncement::findOrFail($id)->delete();
        return redirect('/admin/announcements')->with('success', 'Announcement deleted.');
    }

    public function dismiss(Request $request, $id)
    {
        AnnouncementDismissal::firstOrCreate([
            'announcement_id' => $id,
            'user_id' => auth()->id(),
        ]);
        return response()->json(['success' => true]);
    }
}
