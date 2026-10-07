<?php

namespace App\Http\Controllers;

use App\Exceptions\HotelStayException;
use App\Models\HotelCreditNote;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\PosTransaction;
use App\Services\BranchContextService;
use App\Services\HotelAccessService;
use App\Services\HotelCreditNotePolicy;
use App\Services\HotelCreditNoteService;
use Illuminate\Http\Request;

class HotelCreditNoteController extends Controller
{
    private function stay(int $id): HotelStay
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
        $query = HotelStay::where('company_id', (int) app('currentCompanyId'));
        $branch = app(BranchContextService::class)->getActiveBranchId();
        if ($branch) {
            $query->where('branch_id', $branch);
        }
        return $query->findOrFail($id);
    }

    public function index(int $id)
    {
        $stay = $this->stay($id);
        $ids = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)
            ->where('entry_type', 'charge')->pluck('pos_transaction_id')->filter()->unique();
        $bills = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $stay->company_id)
            ->whereIn('id', $ids)->with('items')->get();
        $notes = HotelCreditNote::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->get();
        $credits = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $stay->company_id)
            ->whereIn('id', $notes->pluck('credit_transaction_id'))->get()->keyBy('id');
        $terminals = \App\Models\PosTerminal::where('company_id', $stay->company_id)->where('is_active', true)->get();
        return view('pos.hotel.credit-notes', compact('stay', 'bills', 'notes', 'credits', 'terminals'));
    }

    public function activation(Request $request, int $id)
    {
        $stay = $this->stay($id);
        $data = $request->validate([
            'issuance' => 'required|boolean', 'refunds' => 'required|boolean',
            'confirmed' => 'accepted',
        ]);
        abort_unless(config('hotel_credit_notes.enabled', false), 422);
        \Illuminate\Support\Facades\DB::transaction(function () use ($stay, $data) {
            $company = \App\Models\Company::whereKey($stay->company_id)->lockForUpdate()->firstOrFail();
            $flags = $company->feature_flags ?? [];
            $before = [
                'hotel_credit_issuance' => $flags['hotel_credit_issuance'] ?? false,
                'hotel_credit_refunds' => $flags['hotel_credit_refunds'] ?? false,
            ];
            $after = [
                'hotel_credit_issuance' => (bool) $data['issuance'],
                'hotel_credit_refunds' => (bool) $data['refunds'],
            ];
            $company->update(['feature_flags' => array_merge($flags, $after)]);
            \App\Services\AuditLogService::log('hotel_credit_activation_changed', 'company', $company->id,
                $before, $after, (int) $company->id, (int) auth('pos')->id());
        });
        return redirect()->route('pos.hotel.credit-notes', $stay->id)->with('success', __('hotel_credit.activation_saved'));
    }

    private function selection(Request $request): ?array
    {
        $data = $request->validate(['mode' => 'required|in:full,partial', 'quantities' => 'nullable|array',
            'quantities.*' => 'numeric|min:0']);
        if ($data['mode'] === 'full') {
            return null;
        }
        return array_map(fn ($q) => (float) $q, array_filter($data['quantities'] ?? [], fn ($q) => (float) $q > 0));
    }

    public function review(Request $request, int $id, int $bill)
    {
        $stay = $this->stay($id);
        $selection = $this->selection($request);
        try {
            $plan = app(HotelCreditNotePolicy::class)->review($stay, auth('pos')->user(), $bill, $selection);
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }
        return view('pos.hotel.credit-note-review', compact('stay', 'plan', 'selection'));
    }

    public function issue(Request $request, int $id, int $bill)
    {
        $stay = $this->stay($id);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255', 'request_key' => 'required|string|min:16|max:64',
            'fingerprint' => 'required|string|size:64', 'confirmed' => 'accepted']);
        $selection = $this->selection($request);
        try {
            app(HotelCreditNoteService::class)->issue($stay, auth('pos')->user(), $bill, $selection,
                $data['reason'], $data['request_key'], $data['fingerprint']);
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }
        return redirect()->route('pos.hotel.credit-notes', $id)->with('success', __('hotel_credit.created'));
    }

    public function refund(Request $request, int $id, int $note)
    {
        $stay = $this->stay($id);
        $data = $request->validate(['amount' => 'required|numeric|gt:0', 'method' => 'required|in:cash,card',
            'request_key' => 'required|string|min:16|max:64', 'terminal_id' => 'nullable|integer|min:0', 'confirmed' => 'accepted']);
        try {
            app(HotelCreditNoteService::class)->refund($stay, auth('pos')->user(), $note,
                (float) $data['amount'], $data['method'], $data['request_key'], (int) ($data['terminal_id'] ?? 0));
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', __('hotel_credit.refunded'));
    }
}
