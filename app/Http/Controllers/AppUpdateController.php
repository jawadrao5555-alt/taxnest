<?php

namespace App\Http\Controllers;

use App\Models\AppUpdate;
use App\Models\AppUpdateSeen;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class AppUpdateController extends Controller
{
    // ============ ADMIN SIDE ============

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

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:150',
            'points_text' => 'required|string|max:3000',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:3072',
            // Audience (Aug 2026): 'pos' = PRA POS, 'fbr_pos' = FBR POS, 'all' = both panels.
            'audience' => 'nullable|in:pos,fbr_pos,all',
            // Type (Task 1286): 'feature' = Naya Feature, 'improvement' = Behtari / Masla Hal.
            'type' => 'nullable|in:feature,improvement',
        ]);

        $points = $this->parsePoints($request->points_text);
        if (empty($points)) {
            return redirect('/admin/app-updates')->with('error', 'At least one feature point is required.');
        }

        AppUpdate::create([
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
            ? ['type' => $request->input('type') ?: 'improvement'] : []));

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
            'type' => 'nullable|in:feature,improvement',
        ]);

        $points = $this->parsePoints($request->points_text);
        if (empty($points)) {
            return redirect('/admin/app-updates')->with('error', 'At least one feature point is required.');
        }

        $data = [
            'title' => $request->title,
            'points' => $points,
        ];
        if ($request->filled('audience')) {
            $data['audience'] = $request->input('audience');
        }
        // Unchecked checkbox = false (edit form always sends the field's state).
        if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'is_featured')) {
            $data['is_featured'] = $request->boolean('is_featured');
        }
        if ($request->filled('type') && \Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'type')) {
            $data['type'] = $request->input('type');
        }

        if ($request->boolean('remove_image')) {
            $this->deleteImage($appUpdate->image_path);
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
        // out). Treat that publish as a RE-ANNOUNCE: restart the clock and
        // clear seen rows so the popup + bell fire again for everyone.
        if ($publishing && $appUpdate->created_at->lt(now()->subDays(AppUpdate::LIVE_DAYS))) {
            $this->restartLiveWindow($appUpdate);

            return redirect('/admin/app-updates')->with('success', 'Update dobara elaan ho gaya — 7-din ka clock restart, POS users ko popup + bell phir dikhega.');
        }

        $appUpdate->update(['is_published' => $publishing]);

        return redirect('/admin/app-updates')->with('success', 'Update ' . ($appUpdate->is_published ? 'published' : 'unpublished') . '.');
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
     * Restart the POS live window: bump created_at to now (liveWindow reads
     * created_at) and wipe seen rows so the one-time popup fires again even
     * for users who dismissed it the first time. created_at is not fillable,
     * so set it directly and save.
     */
    private function restartLiveWindow(AppUpdate $appUpdate): void
    {
        $appUpdate->is_published = true;
        $appUpdate->created_at = now();
        $appUpdate->save();
        AppUpdateSeen::where('app_update_id', $appUpdate->id)->delete();
    }

    public function destroy($id)
    {
        $appUpdate = AppUpdate::findOrFail($id);
        AppUpdateSeen::where('app_update_id', $appUpdate->id)->delete();
        $this->deleteImage($appUpdate->image_path);
        $appUpdate->delete();

        return redirect('/admin/app-updates')->with('success', 'Update deleted.');
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

    private function deleteImage(?string $path): void
    {
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

    // ============ POS SIDE ============

    /**
     * Mark published POS updates as seen for the logged-in POS user.
     * Called by the one-time popup ("Samajh Gaya") and by opening the bell dropdown.
     */
    public function markSeen(Request $request)
    {
        // Both panels share the 'users' provider, so AppUpdateSeen.user_id is safe
        // for either guard. Audience 'all' targets both panels.
        $user = auth('pos')->user();
        $audiences = ['pos', 'all'];
        if (!$user) {
            $user = auth('fbrpos')->user();
            $audiences = ['fbr_pos', 'all'];
        }
        if (!$user) {
            return response()->json(['ok' => false], 401);
        }

        // Task 1286: only rows inside the 7-day live window are marked seen —
        // mirrors the layout queries (older rows are invisible on POS anyway).
        $request->validate([
            'update_id' => 'nullable|integer|min:1',
        ]);

        $query = AppUpdate::whereIn('audience', $audiences)->published()->liveWindow();
        if ($request->filled('update_id')) {
            $query->whereKey((int) $request->input('update_id'));
        }
        $ids = $query->pluck('id');
        $already = AppUpdateSeen::where('user_id', $user->id)->whereIn('app_update_id', $ids)->pluck('app_update_id')->all();

        foreach ($ids as $id) {
            if (!in_array($id, $already)) {
                try {
                    AppUpdateSeen::create(['app_update_id' => $id, 'user_id' => $user->id]);
                } catch (\Throwable $e) {
                    // Unique-constraint race (double click / two tabs) — already seen, ignore.
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
