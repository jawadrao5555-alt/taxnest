<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

/**
 * Task 1022: POS survey popup (Caller ID elaan / advice collection).
 *
 * POS side: submit answers / persist a per-user "Later" dismissal — admin/manager only
 * (same owner rule as What's New: cashiers/confined roles never see it,
 * so their POSTs are also refused). Admin side: surveys list + results.
 */
class PosSurveyController extends Controller
{
    // ============ POS SIDE ============

    /**
     * Server-side eligibility — the SINGLE gate for both write endpoints.
     * Blade-side filtering is presentation only; a direct POST must never
     * let an out-of-audience company pollute results or seen metrics.
     */
    private function eligibleSurvey($id, $user): ?Survey
    {
        $survey = Survey::find($id);
        if (! $survey || ! $survey->eligibleFor($user)) {
            return null;
        }
        if ($survey->audience === 'pos_restaurant' && ! ((bool) ($user->company?->restaurant_mode ?? false))) {
            return null;
        }

        return $survey;
    }

    /** Submit answers — one response per user, validated against the survey's own options. */
    public function respond(Request $request, $id)
    {
        $user = auth(request()->route('survey_guard', 'pos'))->user();
        if (! $user || (Survey::find($id) && ! Survey::find($id)->respondentRoleAllowed($user))) {
            return response()->json(['ok' => false], 403);
        }

        $survey = $this->eligibleSurvey($id, $user);
        if (! $survey) {
            return response()->json(['ok' => false, 'message' => 'Survey band ho chuka hai.'], 404);
        }

        // Validate against the survey's OWN questions/options — every question
        // required, answer must be one of that question's option keys.
        $answers = $request->input('answers');
        if (! is_array($answers)) {
            return response()->json(['ok' => false, 'message' => 'Jawab ghalat format mein hain.'], 422);
        }
        $clean = [];
        foreach ($survey->questions as $q) {
            $qKey = $q['key'] ?? null;
            $optKeys = array_map(fn ($o) => $o['key'] ?? null, $q['options'] ?? []);
            $given = $answers[$qKey] ?? null;
            $type = $q['type'] ?? 'choice';
            if ($type === 'text') {
                if (! is_string($given) || trim($given) === '' || mb_strlen($given) > 2000) {
                    return response()->json(['ok' => false, 'message' => 'Har sawal ka mukammal jawab dein.'], 422);
                }
                $given = trim($given);
            } elseif ($qKey === null || ! is_string($given) || ! in_array($given, $optKeys, true)) {
                return response()->json(['ok' => false, 'message' => 'Har sawal ka jawab chunein.'], 422);
            }
            $clean[$qKey] = $given;
        }

        $comment = null;
        if ($survey->allow_comment && is_string($request->input('comment'))) {
            $comment = mb_substr(trim($request->input('comment')), 0, 2000) ?: null;
        }

        // Atomic first-write-wins: get-or-create the (survey,user) row, then a
        // CONDITIONAL update guarded on answered_at IS NULL. Two concurrent
        // submits (incl. the dismissed-row path where the row already exists)
        // can both reach here — only one conditional update affects a row; the
        // loser reports 'already' and never overwrites the first answer.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($survey, $user, $clean, $comment) {
            $locked = Survey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            if (! $locked->eligibleFor($user)) {
                return response()->json(['ok' => false], 404);
            }
            if ($locked->questions !== $survey->questions) {
                return response()->json(['ok' => false, 'message' => 'Survey badal gaya hai; page dobara kholain.'], 409);
            }
            if ($locked->answeredBy($user)) {
                return response()->json(['ok' => true, 'already' => true]);
            }
            try {
                $row = SurveyResponse::firstOrCreate(
                    ['survey_id' => $survey->id, 'user_id' => $user->id],
                    ['company_id' => $user->company_id]
                );
            } catch (\Throwable $e) {
                // Unique-constraint race on create — the other request made the row.
                $row = SurveyResponse::where('survey_id', $survey->id)->where('user_id', $user->id)->first();
            }
            if (! $row) {
                return response()->json(['ok' => false], 500);
            }

            $won = SurveyResponse::where('id', $row->id)->whereNull('answered_at')->update([
                'answers' => json_encode($clean, JSON_UNESCAPED_UNICODE),
                'comment' => $comment,
                'answered_at' => now(),
                'company_id' => $user->company_id,
                'updated_at' => now(),
            ]);
            if ($won === 0) {
                // Already answered (double click / two tabs) — idempotent, never overwrite.
                return response()->json(['ok' => true, 'already' => true]);
            }

            return response()->json(['ok' => true]);
        });
    }

    /** "Baad mein" — no auto-popup on later logins; pill remains until answered. */
    public function dismiss(Request $request, $id)
    {
        $user = auth(request()->route('survey_guard', 'pos'))->user();
        if (! $user || (Survey::find($id) && ! Survey::find($id)->respondentRoleAllowed($user))) {
            return response()->json(['ok' => false], 403);
        }

        // Same eligibility gate as respond(): an out-of-audience or closed
        // survey never gets a seen row (would skew "companies saw" metrics).
        $survey = $this->eligibleSurvey($id, $user);
        if ($survey) {
            // Seen tracking for the admin "saw vs answered" counts.
            try {
                SurveyResponse::firstOrCreate(
                    ['survey_id' => $survey->id, 'user_id' => $user->id],
                    ['company_id' => $user->company_id]
                );
            } catch (\Throwable $e) {
                // race — row already exists
            }
            SurveyResponse::where('survey_id', $survey->id)->where('user_id', $user->id)->whereNull('answered_at')->update(['updated_at' => now()]);
            session(['pos_survey_dismissed_'.$survey->id => now()->toDateString()]);
        }

        return response()->json(['ok' => true]);
    }

    // ============ ADMIN SIDE ============

    public function adminIndex()
    {
        $surveys = Survey::withCount([
            'responses as seen_count',
            'responses as answered_count' => fn ($q) => $q->whereNotNull('answered_at'),
        ])->orderByDesc('created_at')->paginate(15);

        $featureOn = SystemSetting::get('pos_surveys_enabled', '1') === '1';
        $canManageRelease = auth('admin')->user()?->fresh()?->canManageRelease() ?? false;

        return view('admin.surveys', compact('surveys', 'featureOn', 'canManageRelease'));
    }

    public function adminShow($id, Request $request)
    {
        $canManageRelease = auth('admin')->user()?->fresh()?->canManageRelease() ?? false;
        $survey = Survey::findOrFail($id);
        $request->validate(['company_id' => 'nullable|integer|min:1', 'answered' => 'nullable|in:yes,no']);
        $responses = $survey->responses()->with(['user:id,name', 'company:id,name,restaurant_mode'])
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->input('answered') === 'yes', fn ($q) => $q->whereNotNull('answered_at'))
            ->when($request->input('answered') === 'no', fn ($q) => $q->whereNull('answered_at'))->get();

        $answered = $responses->filter(fn ($r) => $r->answered_at !== null);

        // Per-question option counts: overall + restaurant-mode companies split.
        $stats = [];
        foreach ($survey->questions as $q) {
            $row = ['text' => $q['text'] ?? '', 'options' => [], 'texts' => ($q['type'] ?? 'choice') === 'text' ? $answered->map(fn ($r) => $r->answers[$q['key']] ?? null)->filter()->values()->all() : []];
            foreach (($q['options'] ?? []) as $opt) {
                $all = $answered->filter(fn ($r) => ($r->answers[$q['key']] ?? null) === $opt['key']);
                $row['options'][] = [
                    'label' => $opt['label'] ?? $opt['key'],
                    'count' => $all->count(), 'percent' => $answered->count() ? round($all->count() * 100 / $answered->count(), 1) : 0,
                    'restaurant' => $all->filter(fn ($r) => (bool) ($r->company->restaurant_mode ?? false))->count(),
                ];
            }
            $stats[] = $row;
        }

        $comments = $answered->filter(fn ($r) => filled($r->comment))->sortByDesc('answered_at')->values();

        $seenCompanies = $responses->pluck('company_id')->unique()->count();
        $answeredCompanies = $answered->pluck('company_id')->unique()->count();

        $eligible = $survey->audienceUsers()->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')));
        $eligibleCount = ($survey->response_policy ?? 'user') === 'company' ? $eligible->distinct()->count('company_id') : $eligible->count();
        $answeredScope = $survey->responses()->whereNotNull('answered_at')->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')));
        $pendingCount = max(0, $eligibleCount - $answeredScope->distinct()->count(($survey->response_policy ?? 'user') === 'company' ? 'company_id' : 'user_id'));

        return view('admin.survey-results', compact(
            'eligibleCount', 'pendingCount', 'survey', 'stats', 'comments', 'seenCompanies', 'answeredCompanies', 'responses', 'answered', 'canManageRelease'
        ));
    }

    public function adminExport(Request $request, $id)
    {
        $survey = Survey::findOrFail($id);
        $request->validate(['company_id' => 'nullable|integer|min:1', 'answered' => 'nullable|in:yes,no']);
        $rows = $survey->responses()->where('survey_id', $survey->id)
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->input('answered') === 'yes', fn ($q) => $q->whereNotNull('answered_at'))
            ->when($request->input('answered') === 'no', fn ($q) => $q->whereNull('answered_at'))->orderBy('id');

        return response()->streamDownload(function () use ($rows) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Company ID', 'User ID', 'Answered at', 'Answers', 'Comment'], ',', '"', '');
            foreach ($rows->cursor() as $row) {
                $cells = [$row->company_id, $row->user_id, $row->answered_at?->toIso8601String(), json_encode($row->answers, JSON_UNESCAPED_UNICODE), $row->comment];
                fputcsv($file, array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : $v, $cells), ',', '"', '');
            }
            fclose($file);
        }, 'survey-'.$survey->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Create a new survey (draft or immediately published). */
    public function adminStore(\Illuminate\Http\Request $request)
    {
        AdminUser::requireReleaseAdministrator();
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'intro' => 'nullable|string|max:1000',
            'questions_json' => 'required|string|max:50000',
            'allow_comment' => 'nullable',
            'audience' => 'required|in:pos_all,pos_restaurant,di,fbrpos,erps',
            'is_published' => 'nullable',
            'starts_at' => 'nullable|date', 'expires_at' => 'nullable|date|after:starts_at',
            'response_policy' => 'nullable|in:user,company',
            'target_companies' => 'nullable|string|max:2000', 'target_users' => 'nullable|string|max:2000', 'target_roles' => 'nullable|string|max:500',
        ]);

        $questions = json_decode($validated['questions_json'], true);
        $error = $this->validateQuestionsPayload($questions);
        if ($error) {
            return back()->with('error', $error)->withInput();
        }

        Survey::create([
            'title' => $validated['title'],
            'intro' => $validated['intro'] ?? null,
            'questions' => $questions,
            'allow_comment' => $request->boolean('allow_comment'),
            'audience' => $validated['audience'],
            'is_published' => $request->boolean('is_published'),
            ...$this->publication($request),
        ]);

        return redirect()->route('admin.surveys')->with('success', 'Survey bana diya gaya!');
    }

    /** Update an existing survey. Blocked once published AND responses exist. */
    public function adminUpdate(\Illuminate\Http\Request $request, $id)
    {
        AdminUser::requireReleaseAdministrator();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $id) {
            $survey = Survey::whereKey($id)->lockForUpdate()->firstOrFail();
            $hasResponses = $survey->responses()->exists();

            if ($hasResponses) {
                return back()->with('error', 'Published survey mein responses aa chuke hain — results consistent rakhne ke liye editing band hai. Pehle survey band karein, phir new survey banayein.');
            }

            $validated = $request->validate([
                'title' => 'required|string|max:200',
                'intro' => 'nullable|string|max:1000',
                'questions_json' => 'required|string|max:50000',
                'allow_comment' => 'nullable',
                'audience' => 'required|in:pos_all,pos_restaurant,di,fbrpos,erps',
                'is_published' => 'nullable',
                'starts_at' => 'nullable|date', 'expires_at' => 'nullable|date|after:starts_at',
                'response_policy' => 'nullable|in:user,company',
                'target_companies' => 'nullable|string|max:2000', 'target_users' => 'nullable|string|max:2000', 'target_roles' => 'nullable|string|max:500',
            ]);

            $questions = json_decode($validated['questions_json'], true);
            $error = $this->validateQuestionsPayload($questions);
            if ($error) {
                return back()->with('error', $error)->withInput();
            }

            $survey->update([
                'title' => $validated['title'],
                'intro' => $validated['intro'] ?? null,
                'questions' => $questions,
                'allow_comment' => $request->boolean('allow_comment'),
                'audience' => $validated['audience'],
                'is_published' => $request->boolean('is_published'),
                ...$this->publication($request),
            ]);

            return redirect()->route('admin.surveys')->with('success', 'Survey update ho gaya!');
        });
    }

    /** Delete a survey — only allowed if no responses exist. */
    public function adminDestroy($id)
    {
        AdminUser::requireReleaseAdministrator();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($id) {
            $survey = Survey::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($survey->responses()->exists()) {
                return back()->with('error', 'Is survey mein responses hain — delete nahi kar sakte. Results ke liye band karein.');
            }
            $survey->delete();

            return redirect()->route('admin.surveys')->with('success', 'Survey delete ho gaya.');
        });
    }

    private function publication(Request $request): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('surveys', 'starts_at')) {
            return [];
        }
        $targets = [];
        foreach (['companies', 'users', 'roles'] as $key) {
            $values = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $request->input('target_'.$key, ''))))));
            if ($key !== 'roles') {
                foreach ($values as $id) {
                    abort_unless(ctype_digit($id) && (int) $id > 0, 422);
                }
            }
            $targets[$key] = $key === 'roles' ? $values : array_map('intval', $values);
        }
        if (! $request->boolean('is_published') && ! $request->filled('starts_at') && ! $request->filled('expires_at')) {
            return ['starts_at' => null, 'expires_at' => null, 'targeting' => $targets, 'response_policy' => $request->input('response_policy', 'user')];
        }
        $start = $request->filled('starts_at') ? \Illuminate\Support\Carbon::parse($request->input('starts_at'), 'Asia/Karachi')->setTimezone(config('app.timezone')) : now();
        $end = $request->filled('expires_at') ? \Illuminate\Support\Carbon::parse($request->input('expires_at'), 'Asia/Karachi')->setTimezone(config('app.timezone')) : $start->copy()->addDays(3);
        abort_unless($end->gt($start), 422);

        return ['starts_at' => $start, 'expires_at' => $end, 'targeting' => $targets, 'response_policy' => $request->input('response_policy', 'user')];
    }

    /** Shared question-structure validator. Returns an error string or null. */
    private function validateQuestionsPayload($questions): ?string
    {
        if (! is_array($questions) || count($questions) < 1 || count($questions) > 20) {
            return 'Kam se kam ek sawal zaroori hai.';
        }

        $qKeys = [];
        foreach ($questions as $i => $q) {
            if (! is_array($q)) {
                return 'Sawal ka format durust nahi.';
            }
            $n = $i + 1;
            if (empty(trim($q['text'] ?? ''))) {
                return "Sawal #{$n} ka text khali hai.";
            }
            $qKey = $q['key'] ?? null;
            if (empty($qKey) || ! is_string($qKey)) {
                return "Sawal #{$n} ki key missing ya invalid hai.";
            }
            if (in_array($qKey, $qKeys, true)) {
                return "Sawal #{$n} ki key duplicate hai — har sawal ki key unique honi chahiye.";
            }
            $qKeys[] = $qKey;

            if (! in_array($q['type'] ?? 'choice', ['choice', 'rating', 'text'], true)) {
                return 'Sawal ki type durust nahi.';
            }
            if (($q['type'] ?? 'choice') === 'text') {
                continue;
            }
            if (($q['type'] ?? 'choice') === 'rating' && array_column($q['options'] ?? [], 'key') !== ['1', '2', '3', '4', '5']) {
                return 'Rating 1 se 5 tak honi chahiye.';
            }
            if (empty($q['options']) || ! is_array($q['options']) || count($q['options']) < 2) {
                return "Sawal #{$n} mein kam se kam 2 options chahiye.";
            }
            $optKeys = [];
            foreach ($q['options'] as $j => $opt) {
                if (empty(trim($opt['label'] ?? ''))) {
                    return "Sawal #{$n}, Option #".($j + 1).' ka label khali hai.';
                }
                $optKey = $opt['key'] ?? null;
                if (empty($optKey) || ! is_string($optKey)) {
                    return "Sawal #{$n}, Option #".($j + 1).' ki key missing ya invalid hai.';
                }
                if (in_array($optKey, $optKeys, true)) {
                    return "Sawal #{$n}, Option #".($j + 1).' ki key duplicate hai.';
                }
                $optKeys[] = $optKey;
            }
        }

        return null;
    }

    /** Close (stop showing on POS, keep results) or reopen. */
    public function toggleClose($id)
    {
        AdminUser::requireReleaseAdministrator();
        $survey = Survey::findOrFail($id);
        $survey->update(['closed_at' => $survey->closed_at ? null : now()]);

        return redirect()->back()->with('success', 'Survey '.($survey->closed_at ? 'closed — POS par ab nahi dikhega (results mehfooz hain).' : 'reopened — POS par dobara dikhega.'));
    }

    /** Master kill switch for ALL survey popups/pills (same convention as What's New). */
    public function toggleFeature()
    {
        AdminUser::requireReleaseAdministrator();
        $on = SystemSetting::get('pos_surveys_enabled', '1') === '1';
        SystemSetting::set('pos_surveys_enabled', $on ? '0' : '1', 'POS survey popups master switch');

        return redirect()->back()->with('success', 'POS surveys '.($on ? 'DISABLED' : 'ENABLED').' for all POS users.');
    }
}
