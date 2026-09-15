<?php

namespace App\Http\Controllers;

use App\Exceptions\HotelStayException;
use App\Models\HotelRoom;
use App\Models\HotelStay;
use App\Models\PosCustomer;
use App\Services\BranchContextService;
use App\Services\HotelAccessService;
use App\Services\HotelCheckoutPolicy;
use App\Services\HotelFolioCatalog;
use App\Services\HotelFolioService;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use App\Services\PosUnitCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class HotelController extends Controller
{
    public function __construct(
        private HotelStayService $stays,
        private HotelFolioService $folio,
        private BranchContextService $branches,
    ) {
    }

    public function dashboard()
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $board = $this->stays->board($companyId, $branchId);
        $money = $this->stays->todayMoney($companyId, $branchId);
        $roomCards = $this->stays->roomCards($companyId, $branchId);

        return view('pos.hotel.dashboard', $board + compact('money', 'roomCards'));
    }

    public function rooms()
    {
        HotelAccessService::abortUnlessHousekeeping(auth('pos')->user());
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $rooms = HotelRoom::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('room_number')
            ->get();
        $openStayByRoom = HotelStay::where('company_id', $companyId)
            ->whereIn('status', HotelStay::OPEN_STATUSES)
            ->whereIn('room_id', $rooms->pluck('id'))
            ->get()
            ->keyBy('room_id');
        $uomGroups = PosUnitCatalog::groupsFor(\App\Models\Company::find($companyId));
        $canManageRooms = HotelAccessService::canManageRooms(auth('pos')->user());
        $canFrontDesk = HotelAccessService::canFrontDesk(auth('pos')->user());
        $checkoutPolicy = HotelCheckoutPolicy::forCompany(\App\Models\Company::find($companyId));
        $roomCards = $this->stays->roomCards($companyId, $branchId);
        $filter = (string) request()->query('filter', '');
        $housekeepingView = false;

        return view('pos.hotel.rooms', compact(
            'rooms', 'openStayByRoom', 'uomGroups', 'canManageRooms', 'canFrontDesk',
            'checkoutPolicy', 'roomCards', 'filter', 'housekeepingView'
        ));
    }

    public function storeRoom(Request $request)
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
        $data = $request->validate([
            'room_number' => 'required|string|max:32',
            'room_type' => 'nullable|string|max:80',
            'capacity' => 'required|integer|min:1|max:50',
            'rate_amount' => 'required|numeric|min:0|max:10000000',
            'rate_unit' => 'nullable|string|max:8',
            'service_state' => 'nullable|in:in_service,out_of_service',
            'housekeeping' => 'nullable|in:clean,dirty,inspected',
            'notes' => 'nullable|string|max:500',
        ]);
        try {
            $this->stays->createRoom((int) app('currentCompanyId'), $data);
        } catch (HotelStayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_room_saved'));
    }

    public function updateRoom(Request $request, int $id)
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
        $room = $this->room((int) $id);
        $data = $request->validate([
            'room_number' => 'required|string|max:32',
            'room_type' => 'nullable|string|max:80',
            'capacity' => 'required|integer|min:1|max:50',
            'rate_amount' => 'required|numeric|min:0|max:10000000',
            'rate_unit' => 'nullable|string|max:8',
            'service_state' => 'nullable|in:in_service,out_of_service',
            'housekeeping' => 'nullable|in:clean,dirty,inspected',
            'notes' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);
        try {
            $this->stays->updateRoom($room, $data);
        } catch (HotelStayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_room_saved'));
    }

    public function housekeeping(Request $request, int $id)
    {
        HotelAccessService::abortUnlessHousekeeping(auth('pos')->user());
        $room = $this->room((int) $id);
        $data = $request->validate([
            'housekeeping' => 'required|in:clean,dirty,inspected',
        ]);
        $this->stays->setHousekeeping($room, $data['housekeeping']);

        return back()->with('success', __('pos.hotel_housekeeping_updated'));
    }

    public function serviceState(Request $request, int $id)
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
        $room = $this->room((int) $id);
        $data = $request->validate([
            'service_state' => 'required|in:in_service,out_of_service',
        ]);
        $this->stays->updateRoom($room, $data);

        return back()->with('success', __('pos.hotel_room_saved'));
    }

    public function staysIndex(Request $request)
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $status = (string) $request->input('status', '');
        $statusFilter = [
            HotelStay::STATUS_RESERVED,
            HotelStay::STATUS_CHECKED_IN,
            HotelStay::STATUS_CHECKED_OUT,
            HotelStay::STATUS_CANCELLED,
            HotelStay::STATUS_NO_SHOW,
        ];
        $stays = HotelStay::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($status !== '' && in_array($status, $statusFilter, true), fn ($q) => $q->where('status', $status))
            ->with('room')
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        $heading = $status === HotelStay::STATUS_RESERVED
            ? __('pos.nav_hotel_reservations')
            : __('pos.hotel_stays');

        return view('pos.hotel.stays-index', compact('stays', 'status', 'heading'));
    }

    public function reservations(Request $request)
    {
        return $this->staysIndex($request->merge(['status' => HotelStay::STATUS_RESERVED]));
    }

    public function createStay()
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $rooms = HotelRoom::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->where('service_state', HotelRoom::SERVICE_IN)
            ->orderBy('room_number')
            ->get();
        $customers = [];
        if (Schema::hasTable('pos_customers')) {
            $customers = PosCustomer::where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'phone']);
        }

        $walkIn = request()->boolean('walk_in');

        return view('pos.hotel.stay-create', compact('rooms', 'customers', 'walkIn'));
    }

    public function storeStay(Request $request)
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $data = $request->validate([
            'room_id' => 'required|integer',
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'guest_name' => 'required|string|max:160',
            'guest_phone' => 'nullable|string|max:40',
            'guest_cnic' => 'nullable|string|max:20',
            'guest_customer_id' => 'nullable|integer',
            'payer_customer_id' => 'nullable|integer',
            'adult_count' => 'nullable|integer|min:1|max:50',
            'child_count' => 'nullable|integer|min:0|max:50',
            'notes' => 'nullable|string|max:500',
            'walk_in' => 'nullable|boolean',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        $data['walk_in'] = $request->boolean('walk_in');
        try {
            $this->room((int) $data['room_id']);
            $stay = $this->stays->book((int) app('currentCompanyId'), (int) auth('pos')->id(), $data);
        } catch (HotelStayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.hotel.stays.show', $stay->id)
            ->with('success', $data['walk_in'] ? __('pos.hotel_checked_in') : __('pos.hotel_reserved'));
    }

    public function showStay(int $id)
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $stay = $this->stay($id);
        $stay->load(['room', 'occupants', 'folioEntries', 'assignments.room']);
        $totals = $this->folio->totals($stay);
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $rooms = HotelRoom::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->orderBy('room_number')
            ->get();
        $catalogBranchId = $stay->branch_id ? (int) $stay->branch_id : $branchId;
        $products = HotelFolioCatalog::products($companyId, $catalogBranchId);
        $services = HotelFolioCatalog::services($companyId, $catalogBranchId);
        $uomGroups = PosUnitCatalog::groupsFor(\App\Models\Company::find($companyId));
        $checkoutPolicy = HotelCheckoutPolicy::forCompany(\App\Models\Company::find($companyId));
        $timeline = $this->stays->stayTimeline($stay);

        return view('pos.hotel.stay-show', compact('stay', 'totals', 'rooms', 'products', 'services', 'uomGroups', 'checkoutPolicy', 'timeline'));
    }

    public function checkIn(Request $request, int $id)
    {
        try {
            $this->stays->checkIn($this->stay($id), (int) auth('pos')->id(), $request->input('idempotency_key'));
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_checked_in'));
    }

    public function checkOut(int $id)
    {
        try {
            $this->stays->checkOut($this->stay($id), (int) auth('pos')->id());
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_checked_out'));
    }

    public function extend(Request $request, int $id)
    {
        $data = $request->validate([
            'check_out_date' => 'required|date',
        ]);
        try {
            $this->stays->extend($this->stay($id), $data['check_out_date'], (int) auth('pos')->id());
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_extended'));
    }

    public function move(Request $request, int $id)
    {
        $data = $request->validate(['room_id' => 'required|integer']);
        try {
            $this->stays->moveRoom($this->stay($id), (int) $data['room_id'], (int) auth('pos')->id());
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_moved'));
    }

    public function cancel(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        try {
            $this->stays->cancel($this->stay($id), (int) auth('pos')->id(), $data['reason'] ?? null);
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_cancelled'));
    }

    public function noShow(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        try {
            $this->stays->markNoShow($this->stay($id), (int) auth('pos')->id(), $data['reason'] ?? null);
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_no_show'));
    }

    public function folioCharge(Request $request, int $id)
    {
        $stay = $this->stay($id);
        $data = $request->validate([
            'description' => 'required_without:product_id|nullable|string|max:255',
            'category' => 'required|in:room,food,laundry,extra,other',
            'quantity' => 'required|numeric|min:0.001|max:9999',
            'uom' => 'nullable|string|max:8',
            'unit_amount' => 'required|numeric|min:0|max:10000000',
            'product_id' => 'nullable|integer',
            'service_id' => 'nullable|integer',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        try {
            $this->folio->postCharge($stay, $data, (int) auth('pos')->id());
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_charge_posted'));
    }

    public function folioPayment(Request $request, int $id)
    {
        $stay = $this->stay($id);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:10000000',
            'payment_method' => 'required|in:cash,card,debit_card,credit_card,qr_payment',
            'kind' => 'required|in:payment,deposit',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        $payload = [
            'description' => $data['kind'] === 'deposit' ? __('pos.hotel_security_deposit') : __('pos.hotel_advance_payment'),
            'quantity' => 1,
            'uom' => 'NOS',
            'unit_amount' => $data['amount'],
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ];
        try {
            if ($data['kind'] === 'deposit') {
                $this->folio->postDeposit($stay, $payload, (int) auth('pos')->id());
            } else {
                $this->folio->postPayment($stay, $payload, (int) auth('pos')->id());
            }
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_payment_posted'));
    }

    public function folioRefund(Request $request, int $id)
    {
        $stay = $this->stay($id);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:10000000',
            'kind' => 'required|in:payment,deposit',
            'payment_method' => 'nullable|in:cash,card,debit_card,credit_card,qr_payment',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        try {
            if ($data['kind'] === 'deposit') {
                $this->folio->refundDeposit($stay, (float) $data['amount'], (int) auth('pos')->id(), $data['payment_method'] ?? null, $data['idempotency_key'] ?? null);
            } else {
                $this->folio->refundPayment($stay, (float) $data['amount'], (int) auth('pos')->id(), $data['payment_method'] ?? null, $data['idempotency_key'] ?? null);
            }
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_refund_posted'));
    }

    public function folioReverse(Request $request, int $id)
    {
        $stay = $this->stay($id);
        $data = $request->validate([
            'entry_id' => 'required|integer',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        try {
            $this->folio->reverseCharge($stay, (int) $data['entry_id'], (int) auth('pos')->id(), $data['idempotency_key'] ?? null);
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_charge_reversed_ok'));
    }

    public function folioSettle(Request $request, int $id)
    {
        $stay = $this->stay($id);
        $data = $request->validate([
            'payment_method' => 'required|in:cash,card,debit_card,credit_card,qr_payment',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        try {
            $result = $this->folio->settleCoveredCharges(
                $stay,
                (int) auth('pos')->id(),
                $data['payment_method'],
                $data['idempotency_key'] ?? null
            );
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }
        if (!$result['transaction']) {
            return back()->with('error', __('pos.hotel_nothing_to_invoice'));
        }

        return back()->with('success', __('pos.hotel_invoiced', [
            'number' => $result['transaction']->invoice_number,
        ]));
    }

    public function updateCheckoutPolicy(Request $request)
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
        $data = $request->validate([
            'hotel_checkout_outstanding' => 'required|in:allow,block',
        ]);
        $company = \App\Models\Company::find((int) app('currentCompanyId'));
        if (!$company) {
            return back()->with('error', __('pos.hotel_company_missing'));
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'hotel_checkout_outstanding')) {
            $company->forceFill([
                'hotel_checkout_outstanding' => $data['hotel_checkout_outstanding'],
            ])->save();
        }

        return back()->with('success', __('pos.hotel_checkout_policy_saved'));
    }

    public function housekeepingBoard()
    {
        HotelAccessService::abortUnlessHousekeeping(auth('pos')->user());
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $roomCards = $this->stays->roomCards($companyId, $branchId);
        $filter = (string) request()->query('filter', 'dirty');
        $canManageRooms = false;
        $canFrontDesk = HotelAccessService::canFrontDesk(auth('pos')->user());
        $checkoutPolicy = null;
        $rooms = collect();
        $openStayByRoom = collect();
        $uomGroups = [];
        $housekeepingView = true;

        return view('pos.hotel.rooms', compact(
            'roomCards', 'filter', 'canManageRooms', 'canFrontDesk', 'checkoutPolicy',
            'rooms', 'openStayByRoom', 'uomGroups', 'housekeepingView'
        ));
    }

    public function guests()
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $guests = HotelStay::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('id')
            ->get(['id', 'guest_name', 'guest_phone', 'stay_number', 'status', 'check_in_date', 'check_out_date', 'room_id'])
            ->unique(fn ($stay) => mb_strtolower(trim((string) $stay->guest_name).'|'.(string) $stay->guest_phone))
            ->values();

        return view('pos.hotel.guests', compact('guests'));
    }

    public function folios()
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $stays = HotelStay::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', HotelStay::OPEN_STATUSES)
            ->with('room')
            ->orderByDesc('id')
            ->get();
        $dues = $this->folio->chargeDuesForStayIds($companyId, $stays->pluck('id')->all());

        return view('pos.hotel.folios', compact('stays', 'dues'));
    }

    public function reports()
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $occupancy = $this->stays->occupancy($companyId, $branchId);
        $money = $this->stays->todayMoney($companyId, $branchId);
        $board = $this->stays->board($companyId, $branchId);

        return view('pos.hotel.reports', [
            'occupancy' => $occupancy,
            'money' => $money,
            'pending' => $board['pending'],
            'dues' => $board['dues'],
        ]);
    }

    private function stay(int $id): HotelStay
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $query = HotelStay::where('company_id', (int) app('currentCompanyId'));
        $branchId = $this->branches->getActiveBranchId();
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->findOrFail($id);
    }

    private function room(int $id): HotelRoom
    {
        $query = HotelRoom::where('company_id', (int) app('currentCompanyId'));
        $branchId = $this->branches->getActiveBranchId();
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->findOrFail($id);
    }
}
