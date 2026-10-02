<?php

namespace App\Http\Controllers;

use App\Models\AppUpdate;
use App\Models\AppUpdateSeen;
use App\Models\Company;
use App\Models\SystemSetting;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AppUpdateController extends Controller
{
    // ---- ADMIN SIDE ----

    public function index(Request $request)
    {
        $query = AppUpdate::withCount('seens');

        // Search: title OR any feature point (points is a JSON column — LIKE works on the raw text)
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', '%' . $q . '%')
                    ->orWhere('points', 'like', '%' . $q . '%');
            });
        }

        // Status filter
        $status = $request->query('status', '');
        if ($status === 'published') {
            $query->where('is_published', 1);
        } elseif ($status === 'hidden') {
            $query->where('is_published', 0);
        }

        // Date range filter (invalid dates are silently ignored)
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $val = $request->query($key);
            if ($val) {
                try {
                    $query->whereDate('created_at', $op, \Carbon\Carbon::parse($val)->toDateString());
                } catch (\Throwable $e) {
                    // ignore unparseable date input
                }
            }
        }

        $updates = $query->orderByDesc('created_at')->paginate(10)->withQueryString();
        $featureOn = SystemSetting::get('pos_whats_new_enabled', '1') === '1';
        $filtersActive = $q !== '' || in_array($status, ['published', 'hidden'], true) || $request->filled('from') || $request->filled('to');

        return view('admin.app-updates', compact('updates', 'featureOn', 'filtersActive'));
    }

    /** Actual current recipient companies for the existing POS category picker. */
    public function audiencePreview(Request $request)
    {
        $request->validate([
            'audience' => 'required|in:pos,fbr_pos,all',
            'audience_family' => 'required|in:all,food_service,goods_retail,pharmacy,services,accommodation',
            'audience_scope' => 'required|in:all,cats',
            'target_categories' => 'nullable|array',
            'target_categories.*' => 'string|max:50',
        ]);
        $categories = $this->validatedCategories($request);
        $count = 0;
        $examples = [];
        foreach (Company::whereIn('product_type', ['pos', 'fbrpos'])
            ->cursor() as $company) {
            $panel = PosFeatureService::panelFor($company);
            if ($request->input('audience') === 'pos' && $panel !== 'pra'
                || $request->input('audience') === 'fbr_pos' && $panel !== 'fbr'
                || ($request->input('audience_family') !== 'all'
                    && !in_array($request->input('audience_family'), AppUpdate::recipientFamilies($company), true))
                || ($categories && !array_intersect(AppUpdate::recipientCategories($company), $categories))) {
                continue;
            }
            $count++;
            if (count($examples) < 5) {
                $examples[] = $company->name;
            }
        }

        return response()->json(['count' => $count, 'examples' => $examples]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:150',
            'points_text' => 'required|string|max:3000',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:3072',
            // Audience (Aug 2026): 'pos' = PRA POS, 'fbr_pos' = FBR POS, 'all' = both panels.
            'audience' => 'nullable|in:pos,fbr_pos,all',
            'audience_family' => 'nullable|in:all,food_service,goods_retail,pharmacy,services,accommodation',
            'audience_scope' => 'required|in:all,cats',
            // Type (Task 1286): 'feature' = Naya Feature, 'improvement' = Behtari / Masla Hal.
            'type' => 'nullable|in:feature,improvement',
            // Task 1585: optional business-category targeting (empty = all shops).
            'target_categories' => 'nullable|array',
            'target_categories.*' => 'string|max:50',
        ]);
        $categories = $this->validatedCategories($request);

        $points = $this->parsePoints($request->points_text);
        if (empty($points)) {
            return redirect('/admin/app-updates')->with('error', 'At least one feature point is required.');
        }

        if (AppUpdate::containsOperationalDetails((string) $request->title, $points)) {
            return redirect('/admin/app-updates')->withInput()->with(
                'error',
                'Customer update mein SHA, deployment, Live Ops, server, database, workflow ya doosri internal technical details publish nahi ki ja sakti.'
            );
        }

        $attributes = [
            'title' => $request->title,
            'points' => $points,
            'image_path' => $this->storeImage($request),
            'audience' => $request->input('audience') ?: 'pos',
            'is_published' => $request->boolean('is_published', true),
            'created_by' => auth()->id(),
            // Featured "bara elaan" (Task 722) — hasColumn guard: prod schema
            // drift convention (row could be "Ran" without the column).
        ] + (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'is_featured')
            ? ['is_featured' => $request->boolean('is_featured')] : [])
          + (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'type')
            ? ['type' => $request->input('type') ?: 'improvement'] : [])
          + (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'target_categories')
            ? ['target_categories' => $categories] : [])
          + (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'audience_family')
            ? ['audience_family' => $request->input('audience_family', 'all')] : []);

        if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'manual_publish_key')) {
            $key = (new AppUpdate($attributes))->contentKey();
            // Repeated submit is idempotent, including concurrent submissions:
            // firstOrCreate's createOrFirst path relies on the unique DB key.
            $existing = AppUpdate::where('notification_key', $key)->orderBy('id')->first();
            if ($existing) {
                return redirect('/admin/app-updates')->with('success',
                    'Same announcement already exists. Its history and seen state were preserved. Use Reannounce for an intentional reminder.');
            }
            $created = AppUpdate::firstOrCreate(['manual_publish_key' => $key], $attributes);
            if (!$created->wasRecentlyCreated) {
                return redirect('/admin/app-updates')->with('success', 'Duplicate submission ignored; existing announcement preserved.');
            }
        } else {
            AppUpdate::create($attributes);
        }

        return redirect('/admin/app-updates')->with('success', 'Update published. POS users will see it on their next page load.');
    }

    public function update(Request $request, $id)
    {
        $appUpdate = AppUpdate::findOrFail($id);

        $request->validate([
            'title' => 'required|string|max:150',
            'points_text' => 'required|string|max:3000',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:3072',
            'audience' => 'nullable|in:pos,fbr_pos,all',
            'audience_family' => 'nullable|in:all,food_service,goods_retail,pharmacy,services,accommodation',
            'audience_scope' => 'required|in:all,cats',
            'type' => 'nullable|in:feature,improvement',
            'target_categories' => 'nullable|array',
            'target_categories.*' => 'string|max:50',
        ]);
        $categories = $this->validatedCategories($request);

        $points = $this->parsePoints($request->points_text);
        if (empty($points)) {
            return redirect('/admin/app-updates')->with('error', 'At least one feature point is required.');
        }

        if (AppUpdate::containsOperationalDetails((string) $request->title, $points)) {
            return redirect('/admin/app-updates')->withInput()->with(
                'error',
                'Customer update mein SHA, deployment, Live Ops, server, database, workflow ya doosri internal technical details publish nahi ki ja sakti.'
            );
        }

        $data = [
            'title' => $request->title,
            'points' => $points,
        ];
        if ($request->filled('audience')) {
            $data['audience'] = $request->input('audience');
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'audience_family')) {
            $data['audience_family'] = $request->input('audience_family', 'all');
        }
        // Unchecked checkbox = false (edit form always sends the field's state).
        if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'is_featured')) {
            $data['is_featured'] = $request->boolean('is_featured');
        }
        if ($request->filled('type') && \Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'type')) {
            $data['type'] = $request->input('type');
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'target_categories')) {
            $data['target_categories'] = $categories;
        }

        if ($request->boolean('remove_image')) {
            $this->deleteImage($appUpdate->image_path, (int) $appUpdate->id);
            $data['image_path'] = null;
        } elseif ($request->hasFile('image')) {
            $this->deleteImage($appUpdate->image_path);
            $data['image_path'] = $this->storeImage($request);
        }

        $appUpdate->update($data);

        return redirect('/admin/app-updates')->with('success', 'Update saved.');
    }

    public function toggle($id)
    {
        $appUpdate = AppUpdate::findOrFail($id);
        $publishing = !$appUpdate->is_published;

        // Task 1295: publishing a row whose 7-day live window has already
        // expired would be silently invisible on POS (liveWindow filters it
        // out). Publish a new revision; do not erase the original seen history.
        if ($publishing && $appUpdate->created_at->lt(now()->subDays(AppUpdate::LIVE_DAYS))) {
            $this->restartLiveWindow($appUpdate);

            return redirect('/admin/app-updates')->with('success', 'Update dobara elaan ho gaya — nayi announcement revision bani; purana seen/history record mehfooz hai.');
        }

        $appUpdate->update(['is_published' => $publishing]);

        return redirect('/admin/app-updates')->with('success', 'Update ' . ($appUpdate->is_published ? 'published' : 'unpublished') . '.');
    }

    /** An empty or off-panel selection must never become a universal announcement. */
    private function validatedCategories(Request $request): ?array
    {
        if ($request->input('audience_scope') === 'all') {
            return null;
        }

        $selected = $request->input('target_categories', []);
        $categories = AppUpdate::normalizeCategories($selected);
        $panels = $request->input('audience') === 'all' ? ['pra', 'fbr']
            : [$request->input('audience') === 'fbr_pos' ? 'fbr' : 'pra'];
        $allowed = [];
        foreach ($panels as $panel) {
            $allowed = array_merge($allowed, \App\Services\PosFeatureService::categories($panel));
        }
        if (!$categories || count($categories) !== count(array_unique($selected))
            || array_diff($categories, $allowed)) {
            throw ValidationException::withMessages([
                'target_categories' => 'Select at least one valid category for the chosen POS panel, or explicitly choose all shops.',
            ]);
        }

        return $categories;
    }

    /**
     * Task 1295: "Dobara Elaan Karein" — re-announce an already-published row
     * whose 7-day window expired (the toggle only covers hidden rows).
     */
    public function reannounce($id)
    {
        $appUpdate = AppUpdate::findOrFail($id);
        $this->restartLiveWindow($appUpdate);

        return redirect('/admin/app-updates')->with('success', 'Update dobara elaan ho gaya — 7-din ka clock restart, POS users ko popup + bell phir dikhega.');
    }

    /**
     * Publish an explicit new revision, preserving the original timestamps,
     * release provenance and every acknowledgement. Only the revision is unread.
     */
    private function restartLiveWindow(AppUpdate $appUpdate): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($appUpdate) {
            $original = AppUpdate::whereKey($appUpdate->id)->lockForUpdate()->firstOrFail();
            if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'announcement_parent_id')) {
                $recent = AppUpdate::where('announcement_parent_id', $original->id)
                    ->where('created_at', '>=', now()->subMinute())->first();
                if ($recent) {
                    $comparison = clone $recent;
                    $comparison->announcement_revision = $original->announcement_revision;
                    if ($comparison->contentKey() === $original->contentKey()) {
                        return; // repeated click/retry, serialized by the source row lock
                    }
                }
            }
            $revision = $original->replicate(['deployment_key', 'manual_publish_key', 'notification_key', 'announcement_revision', 'announcement_parent_id', 'archived_at']);
            $revision->is_published = true;
            if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'announcement_revision')) {
                $revision->announcement_revision = (string) \Illuminate\Support\Str::uuid();
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'announcement_parent_id')) {
                $revision->announcement_parent_id = $original->id;
            }
            $revision->save();
            // The original identity, timestamp and all acknowledgements survive.
        });
    }

    public function destroy($id)
    {
        $appUpdate = AppUpdate::findOrFail($id);
        if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'archived_at')) {
            $group = AppUpdate::whereKey($appUpdate->id);
            if ($appUpdate->notification_key) {
                $group = AppUpdate::where('notification_key', $appUpdate->notification_key);
            }
            $group->update(['archived_at' => now()]);
        } else {
            $appUpdate->update(['is_published' => false]);
        }

        return redirect('/admin/app-updates')->with('success',
            'Announcement archived. Customer delivery stopped; release receipts, images and seen history were preserved.');
    }

    /**
     * Store the uploaded notification image on the public disk.
     * Returns the relative path (e.g. app-updates/xyz.png) or null.
     */
    private function storeImage(Request $request): ?string
    {
        if (!$request->hasFile('image')) {
            return null;
        }
        $name = time() . '_' . uniqid() . '.' . $request->file('image')->extension();
        $request->file('image')->storeAs('app-updates', $name, 'public');

        return 'app-updates/' . $name;
    }

    private function deleteImage(?string $path, ?int $exceptId = null): void
    {
        if ($path && AppUpdate::where('image_path', $path)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            return; // a history-preserving revision still uses this image
        }
        if ($path && \Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
        }
    }

    public function toggleFeature()
    {
        $on = SystemSetting::get('pos_whats_new_enabled', '1') === '1';
        SystemSetting::set('pos_whats_new_enabled', $on ? '0' : '1', 'POS What\'s New notifications (popup + bell) master switch');

        return redirect('/admin/app-updates')->with('success', 'What\'s New notifications ' . ($on ? 'DISABLED' : 'ENABLED') . ' for all POS users.');
    }

    private function parsePoints(string $text): array
    {
        $points = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text)), fn ($p) => $p !== ''));

        return array_slice($points, 0, 15);
    }

    // ---- POS SIDE ----

    /**
     * Mark published POS updates as seen for the logged-in POS user.
     * Called by the one-time popup ("Samajh Gaya") and by opening the bell dropdown.
     */
    public function markSeen(Request $request)
    {
        // Both panels share the 'users' provider, so AppUpdateSeen.user_id is safe
        // for either guard. Audience 'all' targets both panels.
        // The endpoint determines the panel; a simultaneous login must never
        // consume another account's notifications.
        $panel = $request->is('fbr-pos/*') ? 'fbr' : 'pra';
        $user = auth($panel === 'fbr' ? 'fbrpos' : 'pos')->user();
        if (!$user) {
            return response()->json(['ok' => false], 401);
        }

        // Task 1286: only rows inside the 7-day live window are marked seen —
        // mirrors the layout queries (older rows are invisible on POS anyway).
        $request->validate([
            'update_id' => 'nullable|integer|min:1',
        ]);

        // Task 1585: the same audience + business-category predicate the two
        // POS layouts use — mark-seen must never tick an elaan the shop can't
        // actually see (that would hide it from a shop it IS meant for).
        $company = \App\Models\Company::find($user->company_id);
        $query = AppUpdate::forCompany($company, $panel)->forCompanyFamily($company)->published()->liveWindow();
        if ($request->filled('update_id')) {
            $query->whereKey((int) $request->input('update_id'));
        }
        $ids = $query->pluck('id');
        if ($request->filled('update_id') && $ids->isEmpty()) {
            return response()->json(['ok' => false], 404);
        }
        $already = AppUpdateSeen::where('user_id', $user->id)->whereIn('app_update_id', $ids)->pluck('app_update_id')->all();

        foreach ($ids as $id) {
            if (!in_array($id, $already)) {
                // firstOrCreate handles a unique-key race; other database
                // failures must not be reported to the browser as success.
                AppUpdateSeen::firstOrCreate(['app_update_id' => $id, 'user_id' => $user->id]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
