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

        // Company-scoped: the whole team sees their own shop's suggestions + status.
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

        return view('admin.feature-suggestions', compact('suggestions', 'counts', 'status'));
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
