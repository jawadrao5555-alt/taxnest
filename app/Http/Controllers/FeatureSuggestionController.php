<?php

namespace App\Http\Controllers;

use App\Models\FeatureSuggestion;
use Illuminate\Http\Request;

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

        return view('admin.feature-suggestions', compact('suggestions', 'counts', 'status', 'hotGroups'));
    }

    /**
     * "High demand" detector: groups similar OPEN (pending/planned) suggestions by
     * shared title keywords and counts DISTINCT companies per group. Groups asked
     * by 2+ companies surface in a "Zyada Demand" panel; 3+ companies = build-now
     * recommendation (the owner's "3 customer rule").
     */
    private function computeHotGroups(): array
    {
        $open = FeatureSuggestion::whereIn('status', ['pending', 'planned'])
            ->orderByDesc('created_at')->limit(500)
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
