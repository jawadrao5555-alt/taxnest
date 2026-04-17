<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Company;

class AgentManagementController extends Controller
{
    private function posUser()
    {
        return auth('pos')->user();
    }

    public function show(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user, 403, 'POS authentication required.');
        $company = Company::findOrFail($user->company_id);

        $stats = [
            'pending' => \DB::table('pos_transactions')
                ->where('company_id', $company->id)
                ->whereIn('pra_status', ['offline', 'pending', 'failed'])
                ->whereNull('pra_invoice_number')
                ->count(),
            'submitted_today' => \DB::table('pos_transactions')
                ->where('company_id', $company->id)
                ->where('pra_status', 'submitted')
                ->whereDate('updated_at', today())
                ->count(),
            'failed_today' => \DB::table('pos_transactions')
                ->where('company_id', $company->id)
                ->where('pra_status', 'failed')
                ->whereDate('updated_at', today())
                ->count(),
        ];

        $isOnline = $company->agent_last_seen
            && \Carbon\Carbon::parse($company->agent_last_seen)->gt(now()->subMinutes(2));

        return view('company.agent', compact('company', 'stats', 'isOnline'));
    }

    public function generateKey(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user, 403);
        $company = Company::findOrFail($user->company_id);

        $company->update([
            'agent_api_key' => 'tnk_' . Str::random(48),
            'agent_enabled' => true,
        ]);

        return back()->with('success', 'Agent API key generated successfully.');
    }

    public function regenerateKey(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user, 403);
        $company = Company::findOrFail($user->company_id);

        $company->update([
            'agent_api_key' => 'tnk_' . Str::random(48),
        ]);

        return back()->with('success', 'Agent API key regenerated. Update your installed agent.');
    }

    public function toggle(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user, 403);
        $company = Company::findOrFail($user->company_id);

        $company->update([
            'agent_enabled' => !$company->agent_enabled,
        ]);

        return back()->with('success', $company->agent_enabled
            ? 'Agent enabled.'
            : 'Agent disabled.');
    }

    public function downloadAgent()
    {
        $path = public_path('downloads/TaxNest-Agent-Setup.exe');

        if (!file_exists($path)) {
            return back()->with('error', 'Agent installer not yet available. Please contact support.');
        }

        return response()->download($path, 'TaxNest-PRA-Agent-Setup.exe');
    }
}
