<?php

namespace App\Http\Controllers;

use App\Models\FeatureSuggestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class FeatureSuggestionController extends Controller
{
    // ============ POS SIDE ============

    public function index()
    {
        $user = auth('pos')->user();

        // ADMIN/MANAGER ONLY (owner rule, Jul 2026): suggestions are the owner's
        // channel — cashiers/confined roles never see the page, even by direct URL.
        if (!$user || !$user->isPosAdmin()) {
            return redirect()->route('pos.dashboard');
        }

        // Company-scoped: admins/managers see their own shop's suggestions + status.
        $suggestions = FeatureSuggestion::with('user')
            ->where('company_id', $user->company_id)
            ->where('product', 'pos')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('pos.suggestions', compact('suggestions'));
    }

    public function store(Request $request)
    {
        $user = auth('pos')->user();

        // ADMIN/MANAGER ONLY (owner rule, Jul 2026) — mirrors index().
        if (!$user || !$user->isPosAdmin()) {
            return redirect()->route('pos.dashboard');
        }

        $request->validate([
            'title' => 'required|string|max:150',
            'details' => 'nullable|string|max:2000',
        ], [
            'title.required' => 'Tajweez ka title likhna zaroori hai.',
            'title.max' => 'Title 150 harf se lamba nahi ho sakta.',
            'details.max' => 'Tafseel 2000 harf se lambi nahi ho sakti.',
        ]);

        // Spam guard: max 10 suggestions per user per day.
        $todayCount = FeatureSuggestion::where('user_id', $user->id)
            ->whereDate('created_at', now()->toDateString())
            ->count();
        if ($todayCount >= 10) {
            return redirect('/pos/suggestions')->with('error', 'Aaj ke liye tajaweez ki limit poori ho gayi hai — kal dobara bhej sakte hain.');
        }

        FeatureSuggestion::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'product' => 'pos',
            'title' => trim($request->title),
            'details' => $request->filled('details') ? trim($request->details) : null,
            'status' => 'pending',
        ]);

        return redirect('/pos/suggestions')->with('success', 'Shukriya! Aap ki tajweez hum tak pohnch gayi hai. Hamari team is par ghor karegi.');
    }

    // ============ PRA ELAAN (Task 1202) ============

    /**
     * PRA provisional-billing elaan popup: quick-choice + optional mashwara
     * from PRA POS admins/managers. ONE answer per user (firstOrCreate on
     * user_id+source — a repeat submit never duplicates or overwrites).
     * Answering also stamps users.pra_elaan_seen_at so the popup never
     * re-appears. Rows land in feature-suggestions with source='pra_elaan'
     * (Madadgar escalation pattern) for the admin tally.
     */
    public function praElaanRespond(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || !$user->isPosAdmin()) {
            return response()->json(['ok' => false], 403);
        }

        $choice = $request->input('choice');
        if (!is_string($choice) || !array_key_exists($choice, FeatureSuggestion::PRA_ELAAN_CHOICES)) {
            return response()->json(['ok' => false, 'message' => 'Pehle ek jawab chunein.'], 422);
        }
        $mashwara = is_string($request->input('mashwara'))
            ? (mb_substr(trim($request->input('mashwara')), 0, 2000) ?: null)
            : null;

        try {
            // source column ships with the Madadgar migration — on a drifted
            // prod schema (column missing) we still record the raay as a plain
            // suggestion rather than 500 the popup.
            if (Schema::hasColumn('feature_suggestions', 'source')) {
                FeatureSuggestion::firstOrCreate(
                    ['user_id' => $user->id, 'source' => FeatureSuggestion::PRA_ELAAN_SOURCE],
                    [
                        'company_id' => $user->company_id,
                        'product' => 'pos',
                        'title' => FeatureSuggestion::PRA_ELAAN_CHOICES[$choice],
                        'details' => $mashwara,
                        'status' => 'pending',
                    ]
                );
            } else {
                FeatureSuggestion::create([
                    'company_id' => $user->company_id,
                    'user_id' => $user->id,
                    'product' => 'pos',
                    'title' => FeatureSuggestion::PRA_ELAAN_CHOICES[$choice],
                    'details' => $mashwara,
                    'status' => 'pending',
                ]);
            }
            $this->praElaanStampSeen($user);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }

    /** "Baad mein" — stamp seen so the popup never re-appears (no dismiss loop). */
    public function praElaanDismiss()
    {
        $user = auth('pos')->user();
        if (!$user || !$user->isPosAdmin()) {
            return response()->json(['ok' => false], 403);
        }

        try {
            $this->praElaanStampSeen($user);
        } catch (\Throwable $e) { /* popup is hidden client-side for this page anyway */
        }

        return response()->json(['ok' => true]);
    }

    private function praElaanStampSeen($user): void
    {
        // DIRECT assignment, not mass assignment: a non-$fillable column would
        // silently drop (Eloquent missing-attribute convention). hasColumn
        // guard: pre-migration prod must not 500.
        if (Schema::hasColumn('users', 'pra_elaan_seen_at') && $user->pra_elaan_seen_at === null) {
            $user->pra_elaan_seen_at = now();
            $user->save();
        }
    }

    // ============ ADMIN SIDE ============

    public function adminIndex(Request $request)
    {
        $status = $request->query('status');
        $query = FeatureSuggestion::with(['user', 'company'])->orderByDesc('created_at');
        if (in_array($status, FeatureSuggestion::STATUSES, true)) {
            $query->where('status', $status);
        }

        $suggestions = $query->paginate(25)->withQueryString();
        $counts = FeatureSuggestion::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $hotGroups = $this->computeHotGroups();
        $praElaanTally = $this->computePraElaanTally();

        return view('admin.feature-suggestions', compact('suggestions', 'counts', 'status', 'hotGroups', 'praElaanTally'));
    }

    /**
     * Task 1202: tally of PRA-elaan raay responses (Haan / Nahi / Kuch aur) +
     * distinct companies + the full rows (for comments). Null when there are
     * no responses yet — the view hides the whole block.
     */
    private function computePraElaanTally(): ?array
    {
        try {
            if (!Schema::hasColumn('feature_suggestions', 'source')) {
                return null;
            }
            // Eager-load user+company: prod runs preventLazyLoading (fatal).
            $rows = FeatureSuggestion::with(['user', 'company'])
                ->where('source', FeatureSuggestion::PRA_ELAAN_SOURCE)
                ->orderByDesc('created_at')
                ->get();
            if ($rows->isEmpty()) {
                return null;
            }
            $countsByChoice = [];
            foreach (FeatureSuggestion::PRA_ELAAN_CHOICES as $key => $title) {
                $countsByChoice[$key] = $rows->where('title', $title)->count();
            }

            return [
                'total' => $rows->count(),
                'companies' => $rows->pluck('company_id')->unique()->count(),
                'counts' => $countsByChoice,
                'rows' => $rows,
            ];
        } catch (\Throwable $e) {
            return null; // never break the admin page over the tally
        }
    }

    /**
     * "High demand" detector: groups similar OPEN (pending/planned) suggestions by
     * shared title keywords and counts DISTINCT companies per group. Groups asked
     * by 2+ companies surface in a "Zyada Demand" panel; 3+ companies = build-now
     * recommendation (the owner's "3 customer rule").
     */
    private function computeHotGroups(): array
    {
        $openQuery = FeatureSuggestion::whereIn('status', ['pending', 'planned']);
        // Task 1202: PRA-elaan raay rows carry IDENTICAL titles across many
        // companies — without this exclusion they would instantly fake a
        // "BUILD NOW" high-demand group. hasColumn guard: prod schema drift.
        if (Schema::hasColumn('feature_suggestions', 'source')) {
            $openQuery->where(function ($q) {
                $q->whereNull('source')->orWhere('source', '!=', FeatureSuggestion::PRA_ELAAN_SOURCE);
            });
        }
        $open = $openQuery->orderByDesc('created_at')->limit(500)
            ->get(['id', 'company_id', 'title', 'status', 'created_at']);
        if ($open->count() < 2) {
            return [];
        }

        // Roman Urdu + English stopwords — generic words that shouldn't link two suggestions.
        $stop = ['the', 'and', 'for', 'with', 'this', 'that', 'hain', 'aur', 'kay', 'kai', 'liye', 'liay',
            'chahiye', 'chaiye', 'chahiay', 'karna', 'karne', 'krna', 'krne', 'karain', 'karein', 'wala',
            'wali', 'walay', 'sath', 'saath', 'mein', 'main', 'howa', 'hona', 'jaye', 'jaey', 'sakte',
            'sakty', 'sakta', 'option', 'feature', 'features', 'system', 'add', 'new', 'naya', 'nayi',
            'please', 'plz', 'hamein', 'hamain', 'humein', 'koi', 'kuch', 'bhi', 'per', 'par', 'pay',
            'apna', 'apni', 'apne', 'wagera', 'etc', 'want', 'need', 'from', 'have', 'has'];

        $items = [];
        foreach ($open as $s) {
            $tokens = array_values(array_unique(array_filter(
                preg_split('/[^a-z0-9\x{0600}-\x{06FF}]+/u', mb_strtolower($s->title)) ?: [],
                fn ($t) => mb_strlen($t) >= 3 && !in_array($t, $stop, true) && !ctype_digit($t)
            )));
            if (!empty($tokens)) {
                $items[] = ['s' => $s, 'tokens' => $tokens];
            }
        }

        // Union-find: link two suggestions if they share 2+ keywords, OR share 1
        // distinctive keyword (6+ chars AND not too common overall — a word like
        // "receipt" appearing in half the requests must not merge everything).
        $n = count($items);
        $df = [];
        foreach ($items as $it) {
            foreach ($it['tokens'] as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        $dfCap = max(3, (int) ceil($n * 0.25));
        $parent = range(0, max($n - 1, 0));
        $find = function ($i) use (&$parent, &$find) {
            return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
        };
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $shared = array_intersect($items[$i]['tokens'], $items[$j]['tokens']);
                $one = count($shared) === 1 ? reset($shared) : null;
                $strong = count($shared) >= 2
                    || ($one !== null && mb_strlen($one) >= 6 && ($df[$one] ?? 0) <= $dfCap);
                if ($strong) {
                    $parent[$find($i)] = $find($j);
                }
            }
        }

        $groups = [];
        for ($i = 0; $i < $n; $i++) {
            $groups[$find($i)][] = $items[$i];
        }

        $result = [];
        foreach ($groups as $members) {
            $companyIds = array_unique(array_map(fn ($m) => $m['s']->company_id, $members));
            if (count($companyIds) < 2) {
                continue; // one company asking repeatedly is not "demand"
            }
            // Representative label = keywords appearing in the most member titles.
            $freq = [];
            foreach ($members as $m) {
                foreach ($m['tokens'] as $t) {
                    $freq[$t] = ($freq[$t] ?? 0) + 1;
                }
            }
            arsort($freq);
            $result[] = [
                'label' => implode(' + ', array_slice(array_keys($freq), 0, 3)),
                'companies' => count($companyIds),
                'requests' => count($members),
                'titles' => array_slice(array_map(fn ($m) => $m['s']->title, $members), 0, 4),
                'ids' => array_map(fn ($m) => $m['s']->id, $members),
            ];
        }

        usort($result, fn ($a, $b) => [$b['companies'], $b['requests']] <=> [$a['companies'], $a['requests']]);

        return array_slice($result, 0, 6);
    }

    public function setStatus(Request $request, $id)
    {
        $suggestion = FeatureSuggestion::findOrFail($id);

        $request->validate([
            'status' => 'required|in:pending,planned,completed,rejected',
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $suggestion->update([
            'status' => $request->status,
            'admin_note' => $request->filled('admin_note') ? trim($request->admin_note) : null,
        ]);

        return redirect()->back()->with('success', 'Suggestion updated.');
    }

    public function destroy($id)
    {
        FeatureSuggestion::findOrFail($id)->delete();

        return redirect()->back()->with('success', 'Suggestion deleted.');
    }
}
