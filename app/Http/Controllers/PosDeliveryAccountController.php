<?php

namespace App\Http\Controllers;

use App\Services\PosDeliveryAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosDeliveryAccountController extends Controller
{
    private function authorizeAccounts(): int
    {
        $user = auth('pos')->user();
        abort_unless($user && ($user->role === 'company_admin' || $user->pos_role === 'pos_admin'), 403);
        abort_unless(PosDeliveryAccount::ready(), 503);
        return (int) app('currentCompanyId');
    }

    public function index(Request $request)
    {
        $companyId = $this->authorizeAccounts();
        $data = $request->validate(['search' => 'nullable|string|max:100']);
        $accounts = PosDeliveryAccount::scopedAccounts($companyId)->where('credit_amount', '>', 0);
        if (filled($data['search'] ?? null)) {
            $search = '%' . $data['search'] . '%';
            $accounts->where(fn ($query) => $query->where('customer_name', 'like', $search)
                ->orWhere('customer_phone', 'like', $search)->orWhere('rider_name', 'like', $search));
        }
        $accounts = $accounts->orderByDesc('id')->paginate(25)->withQueryString();
        $events = DB::table('pos_delivery_account_events')->whereIn('account_id', $accounts->pluck('id'))
            ->orderByDesc('id')->get()->groupBy('account_id');

        return view('pos.delivery-accounts', compact('accounts', 'events'));
    }

    public function collect(Request $request, int $accountId)
    {
        $companyId = $this->authorizeAccounts();
        $data = $request->validate([
            'request_id' => 'required|uuid',
            'amount' => 'required|numeric|decimal:0,2|min:0.01|max:999999999999.99',
        ]);
        PosDeliveryAccount::collect($companyId, $accountId, (int) auth('pos')->id(),
            $data['request_id'], (string) $data['amount']);

        return back()->with('success', 'Customer payment recorded.');
    }
}
