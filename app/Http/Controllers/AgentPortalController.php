<?php

namespace App\Http\Controllers;

use App\Models\AgentCommission;
use App\Models\AgentSaleClaim;
use App\Models\Company;
use Illuminate\Http\Request;

class AgentPortalController extends Controller
{
    public function dashboard()
    {
        $agent = auth('agent')->user();
        $base = AgentCommission::where('agent_id', $agent->id);
        $totals = [
            'companies' => Company::where('agent_id', $agent->id)->count(),
            'earned' => (float) (clone $base)->sum('amount'),
            'pending' => (float) (clone $base)->where('status', 'pending')->sum('amount'),
            'paid' => (float) (clone $base)->where('status', 'paid')->sum('amount'),
        ];

        return view('agent.dashboard', compact('agent', 'totals'));
    }

    public function companies()
    {
        $agent = auth('agent')->user();
        $companies = Company::with('activeSubscription.pricingPlan')
            ->where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->paginate(25);

        abort_if($companies->contains(fn ($company) => (int) $company->agent_id !== (int) $agent->id), 403);

        return view('agent.companies', compact('companies'));
    }

    public function commissions()
    {
        $agent = auth('agent')->user();
        $commissions = AgentCommission::with('company')
            ->where('agent_id', $agent->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(30);

        abort_if($commissions->contains(fn ($line) => (int) $line->agent_id !== (int) $agent->id), 403);

        $running = (float) AgentCommission::where('agent_id', $agent->id)
            ->where('id', '<', optional($commissions->first())->id ?? 0)
            ->sum('amount');

        return view('agent.commissions', compact('commissions', 'running'));
    }

    public function claims()
    {
        $agent = auth('agent')->user();
        $claims = AgentSaleClaim::with('company')
            ->where('agent_id', $agent->id)
            ->orderByDesc('id')
            ->paginate(25);

        abort_if($claims->contains(fn ($claim) => (int) $claim->agent_id !== (int) $agent->id), 403);

        return view('agent.claims', compact('claims'));
    }

    public function storeClaim(Request $request)
    {
        $data = $request->validate([
            'identifier_type' => ['required', 'in:ntn,email'],
            'identifier' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        AgentSaleClaim::create($data + [
            'agent_id' => auth('agent')->id(),
            'identifier' => trim($data['identifier']),
            'status' => 'pending',
        ]);

        return back()->with('success', 'Sale claim submitted for super-admin review.');
    }
}