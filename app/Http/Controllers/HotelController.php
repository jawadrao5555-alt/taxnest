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
use App\Services\HotelShell;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use App\Services\PosUnitCatalog;
use App\Models\Company;
use App\Models\PosAgentDevice;
use App\Models\PosPrintJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        HotelShell::leaveRestaurantOutlet();
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $board = $this->stays->board($companyId, $branchId);
        $money = $this->stays->todayMoney($companyId, $branchId);
        $roomCards = $this->stays->roomCards($companyId, $branchId);
        $roomStateCounts = array_count_values(array_column($roomCards, 'state'));

        return view('pos.hotel.dashboard', $board + compact('money', 'roomCards', 'roomStateCounts'));
    }

    /**
     * Open the separated Restaurant Outlet (canonical NestPOS sale engine).
     * Fail-closed when restaurant_mode is OFF or the caller is housekeeping-only.
     */
    public function restaurantOutlet(Request $request)
    {
        $company = Company::find(app('currentCompanyId'));
        $user = auth('pos')->user();
        if (!HotelShell::restaurantOutletOn($company)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => __('pos.hotel_restaurant_outlet_off')], 403);
            }
            $home = HotelAccessService::canFrontDesk($user)
                ? route('pos.hotel.dashboard')
                : (HotelAccessService::canHousekeeping($user) ? route('pos.hotel.housekeeping') : route('pos.dashboard'));

            return redirect($home)->with('error', __('pos.hotel_restaurant_outlet_off'));
        }
        if (!HotelShell::canOpenRestaurantOutlet($user, $company)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => __('pos.custom_access_denied')], 403);
            }
            abort(403, __('pos.custom_access_denied'));
        }
        HotelShell::enterRestaurantOutlet();

        return redirect()->route('pos.invoice.create');
    }

    public function leaveRestaurantOutlet()
    {
        HotelShell::leaveRestaurantOutlet();
        $user = auth('pos')->user();
        if (HotelAccessService::canFrontDesk($user)) {
            return redirect()->route('pos.hotel.dashboard');
        }
        if (HotelAccessService::canHousekeeping($user)) {
            return redirect()->route('pos.hotel.housekeeping');
        }

        return redirect()->route('pos.dashboard');
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
        $activeRooms = $rooms->where('is_active', true);
        $removedRooms = $rooms->where('is_active', false);
        $editRoomId = (int) request()->query('edit_room', 0);
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
            'rooms', 'activeRooms', 'removedRooms', 'editRoomId',
            'openStayByRoom', 'uomGroups', 'canManageRooms', 'canFrontDesk',
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
        ]);
        try {
            $this->stays->updateRoom($room, $data);
        } catch (HotelStayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_room_saved'));
    }

    public function removeRoom(int $id)
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
        try {
            $archived = $this->stays->removeRoom($this->room($id));
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.hotel.rooms')
            ->with('success', __($archived ? 'hotel_rooms_manage.hotel_room_archived' : 'hotel_rooms_manage.hotel_room_deleted'));
    }

    public function restoreRoom(int $id)
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
        $this->stays->restoreRoom($this->room($id));

        return redirect()->route('pos.hotel.rooms')->with('success', __('hotel_rooms_manage.hotel_room_restored'));
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
            ->when($request->filled('q'), fn ($query) => $query->where(function ($q) use ($request) {
                $term = '%'.mb_substr(trim((string) $request->input('q')), 0, 100).'%';
                $q->where('guest_name', 'like', $term)->orWhere('stay_number', 'like', $term)->orWhereHas('room', fn ($room) => $room->where('room_number', 'like', $term));
            }))
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

        $recentGuests = HotelStay::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('id')->limit(200)->get(['id', 'guest_name', 'guest_phone', 'guest_cnic'])
            ->unique(fn ($stay) => mb_strtolower(trim($stay->guest_name)).'|'.$stay->guest_phone)->values();

        $walkIn = request()->boolean('walk_in');
        // Only preselect a room from this tenant's active branch and active rooms.
        // The booking service still checks capacity, overlaps and room state on POST.
        $selectedRoomId = (int) request()->query('room_id', 0);
        if (!$rooms->contains('id', $selectedRoomId)) {
            $selectedRoomId = null;
        }

        return view('pos.hotel.stay-create', compact('rooms', 'customers', 'walkIn', 'selectedRoomId', 'recentGuests'));
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
            'rate_amount' => 'required|numeric|min:0|max:10000000',
            'discount_type' => 'nullable|in:amount,percentage',
            'discount_value' => 'nullable|numeric|min:0|max:10000000',
            'advance_amount' => 'nullable|numeric|min:0|max:10000000',
            'payment_method' => 'nullable|in:cash,card,debit_card,credit_card,qr_payment',
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

        return redirect()->route($data['walk_in'] ? 'pos.hotel.stays.statement' : 'pos.hotel.stays.show',
            $data['walk_in'] ? [$stay->id, 'print' => 1] : [$stay->id])
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
        $deskSummary = app(\App\Services\HotelDeskService::class)->summary($stay, 'cash');

        return view('pos.hotel.stay-show', compact('stay', 'totals', 'rooms', 'products', 'services', 'uomGroups', 'checkoutPolicy', 'timeline', 'deskSummary'));
    }

    public function statement(Request $request, int $id)
    {
        $stay = $this->stay($id)->load(['room', 'folioEntries']);
        $summary = app(\App\Services\HotelDeskService::class)->summary($stay, 'cash');
        $totals = $this->folio->totals($stay);
        $company = Company::findOrFail((int) app('currentCompanyId'));
        $paper = $request->query('paper', $company->receipt_printer_size === '58mm' ? '58mm' : '80mm');
        abort_unless(in_array($paper, ['a4', '58mm', '80mm'], true), 422);

        return view('pos.hotel.statement', compact('stay', 'summary', 'totals', 'paper', 'company'));
    }

    public function silentStatement(Request $request, int $id)
    {
        $stay = $this->stay($id);
        $data = $request->validate([
            'paper' => 'required|in:58mm,80mm',
            'print_attempt_uuid' => 'required|uuid',
        ]);
        $company = Company::findOrFail((int) app('currentCompanyId'));
        $settings = $company->printerSettings();
        $user = auth('pos')->user();
        try {
            $result = DB::transaction(function () use ($stay, $data, $company, $settings, $user) {
                HotelStay::where('company_id', $company->id)->whereKey($stay->id)->lockForUpdate()->firstOrFail();
                $existing = PosPrintJob::where('company_id', $company->id)
                    ->where('print_attempt_uuid', $data['print_attempt_uuid'])->first();
                if ($existing) {
                    return $existing->type === 'hotel_bill' && (int) $existing->hotel_stay_id === (int) $stay->id
                        ? ['job' => $existing, 'deduped' => true] : ['reason' => 'idempotency_conflict'];
                }
                if (!$settings['silent_print_enabled']) return ['reason' => 'disabled'];
                if (!$company->agentOnline()) return ['reason' => 'agent_offline'];

                $deviceUid = null;
                $printer = $settings['receipt_printer'];
                if (\App\Http\Controllers\AgentController::deviceRoutingReady()
                    && Schema::hasColumn('users', 'pos_device_uid') && $user->pos_device_uid) {
                    $device = PosAgentDevice::where('company_id', $company->id)
                        ->where('device_uid', $user->pos_device_uid)->first();
                    if (!$device || !$device->isOnline() || !$device->receipt_printer) {
                        return ['reason' => 'counter_unavailable'];
                    }
                    $deviceUid = $device->device_uid;
                    $printer = $device->receipt_printer;
                }
                if (!$printer) return ['reason' => 'no_printer'];

                return ['job' => PosPrintJob::create([
                    'company_id' => $company->id,
                    'type' => 'hotel_bill',
                    'hotel_stay_id' => $stay->id,
                    'print_attempt_uuid' => $data['print_attempt_uuid'],
                    'render_query' => 'paper=' . $data['paper'],
                    'target_printer' => $printer,
                    'device_uid' => $deviceUid,
                    'status' => 'pending',
                    'created_by' => $user->id,
                ]), 'deduped' => false];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // The unique company/UUID index handles simultaneous requests from different stays.
            $winner = PosPrintJob::where('company_id', $company->id)
                ->where('print_attempt_uuid', $data['print_attempt_uuid'])->first();
            if (!$winner) throw $e;
            $result = $winner->type === 'hotel_bill' && (int) $winner->hotel_stay_id === (int) $stay->id
                ? ['job' => $winner, 'deduped' => true] : ['reason' => 'idempotency_conflict'];
        }
        if (isset($result['reason'])) {
            return response()->json(['success' => false, 'reason' => $result['reason']], 409);
        }
        return response()->json(['success' => true, 'job_id' => $result['job']->id, 'deduped' => $result['deduped']]);
    }

    public function bookingQuote(Request $request)
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $data = $request->validate([
            'room_id' => 'required|integer', 'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'rate_amount' => 'required|numeric|min:0|max:10000000',
            'discount_type' => 'required|in:amount,percentage', 'discount_value' => 'required|numeric|min:0|max:10000000',
            'payment_method' => 'required|in:cash,card,debit_card,credit_card,qr_payment',
        ]);
        $room = $this->room((int) $data['room_id']);
        try {
            $nights = HotelStayService::nights($data['check_in_date'], $data['check_out_date']);
            $discount = \App\Services\HotelPricingService::validateRate((int) $room->company_id, (int) auth('pos')->id(), (float) $room->rate_amount, (float) $data['rate_amount'], $nights, $data['discount_type'], (float) $data['discount_value']);
            $quote = \App\Services\HotelPricingService::quote(Company::findOrFail($room->company_id), round($nights * $data['rate_amount'], 2), $discount, $data['payment_method']);
            $busy = HotelStay::where('company_id', $room->company_id)->where('room_id', $room->id)->whereIn('status', HotelStay::OPEN_STATUSES)
                ->whereDate('check_in_date', '<', $data['check_out_date'])->whereDate('check_out_date', '>', $data['check_in_date'])->exists();
            return response()->json($quote + ['nights' => $nights, 'available' => !$busy && $room->is_active && !$room->isOutOfService(), 'dirty' => $room->housekeeping === HotelRoom::HK_DIRTY]);
        } catch (HotelStayException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function showCheckout(int $id)
    {
        $stay = $this->stay($id)->load(['room', 'folioEntries']);
        abort_unless($stay->status === HotelStay::STATUS_CHECKED_IN, 409);
        $summary = app(\App\Services\HotelDeskService::class)->summary($stay, 'cash');
        $allowBalance = HotelCheckoutPolicy::allowsOutstandingCheckout(Company::find($stay->company_id));
        return view('pos.hotel.checkout', compact('stay', 'summary', 'allowBalance'));
    }

    public function checkoutQuote(Request $request, int $id)
    {
        $data = $request->validate(['payment_method' => 'required|in:cash,card,debit_card,credit_card,qr_payment']);
        return response()->json(app(\App\Services\HotelDeskService::class)->summary($this->stay($id), $data['payment_method']));
    }

    public function completeCheckout(Request $request, int $id)
    {
        $stay = $this->stay($id);
        $data = $request->validate([
            'payment_method' => 'required|in:cash,card,debit_card,credit_card,qr_payment',
            'amount' => 'required|numeric|min:0|max:10000000', 'leave_balance' => 'nullable|boolean',
            'idempotency_key' => 'required|string|max:64',
        ]);
        try {
            app(\App\Services\HotelDeskService::class)->checkout($stay, (int) auth('pos')->id(), $data);
        } catch (HotelStayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        return redirect()->route('pos.hotel.stays.show', $id)->with('success', __('pos.hotel_checked_out'));
    }

    public function discount(Request $request, int $id)
    {
        $stay = $this->stay($id);
        $data = $request->validate(['discount_type' => 'required|in:amount,percentage', 'discount_value' => 'required|numeric|min:0|max:10000000']);
        try {
            app(\App\Services\HotelDeskService::class)->changeDiscount($stay, (int) auth('pos')->id(), $data['discount_type'], (float) $data['discount_value']);
        } catch (HotelStayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        return back()->with('success', __('pos.hotel_pricing_saved'));
    }

    public function checkIn(Request $request, int $id)
    {
        try {
            $this->stays->checkIn($this->stay($id), (int) auth('pos')->id(), $request->input('idempotency_key'));
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.hotel.stays.statement', [$id, 'print' => 1])
            ->with('success', __('pos.hotel_checked_in'));
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

    public function changeQuote(Request $request, int $id)
    {
        $stay = $this->stay($id);
        $data = $request->validate([
            'kind' => 'required|in:extend,move', 'rate_amount' => 'required|numeric|min:0|max:10000000',
            'check_out_date' => 'required_if:kind,extend|nullable|date', 'room_id' => 'required_if:kind,move|nullable|integer',
        ]);
        if ($data['kind'] === 'move') $this->room((int) $data['room_id']);
        try {
            return response()->json(app(\App\Services\HotelDeskService::class)->changeQuote($stay, (int) auth('pos')->id(), $data));
        } catch (HotelStayException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function extend(Request $request, int $id)
    {
        $data = $request->validate(['check_out_date' => 'required|date', 'rate_amount' => 'nullable|numeric|min:0|max:10000000']);
        try {
            $stay = $this->stay($id);
            app(\App\Services\HotelDeskService::class)->changeStay($stay, (int) auth('pos')->id(), $data + ['kind' => 'extend']);
        } catch (HotelStayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        return back()->with('success', __('pos.hotel_extended'));
    }

    public function move(Request $request, int $id)
    {
        $data = $request->validate(['room_id' => 'required|integer', 'rate_amount' => 'nullable|numeric|min:0|max:10000000']);
        $this->room((int) $data['room_id']);
        try {
            app(\App\Services\HotelDeskService::class)->changeStay($this->stay($id), (int) auth('pos')->id(), $data + ['kind' => 'move']);
        } catch (HotelStayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
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

    public function correctionPreview(int $id)
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
        $stay = $this->stay($id);
        $plan = app(\App\Services\HotelCorrectionService::class)->preview($stay, auth('pos')->user());
        return view('pos.hotel.correction', compact('stay', 'plan'));
    }

    public function correctStay(Request $request, int $id)
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:255',
            'fingerprint' => 'required|string|size:64',
            'payment_method' => 'required|in:cash,card',
            'confirmed' => 'accepted',
        ]);
        $stay = $this->stay($id);
        try {
            app(\App\Services\HotelCorrectionService::class)->correct($stay, auth('pos')->user(), $data['fingerprint'], $data['reason'], $data['payment_method']);
        } catch (HotelStayException $e) {
            return redirect()->route('pos.hotel.stays.correction', $stay->id)->with('error', $e->getMessage());
        }
        return redirect()->route('pos.hotel.stays.show', $stay->id)->with('success', __('hotel_correction.done'));
    }

    public function voidErroneousStay(Request $request, int $id)
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);
        try {
            $this->stays->voidErroneousStay($this->stay($id), (int) auth('pos')->id(), $data['reason']);
        } catch (HotelStayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('pos.hotel_erroneous_stay_voided'));
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
            'description' => 'required_without_all:product_id,service_id|nullable|string|max:255',
            'category' => 'required|in:room,food,laundry,extra,other',
            'quantity' => 'required|numeric|min:0.001|max:9999',
            'uom' => 'nullable|string|max:8',
            'unit_amount' => 'required|numeric|min:0|max:10000000',
            'product_id' => 'nullable|integer',
            'service_id' => 'nullable|integer',
            'discount_type' => 'nullable|in:amount,percentage',
            'discount_value' => 'nullable|numeric|min:0|max:10000000',
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
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
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
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
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
        abort_unless(\Illuminate\Support\Facades\Schema::hasColumn('companies', 'hotel_checkout_outstanding'), 503, __('pos.setting_not_available_yet'));
        $company->forceFill([
            'hotel_checkout_outstanding' => $data['hotel_checkout_outstanding'],
        ])->save();

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

    public function folios(Request $request)
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $companyId = (int) app('currentCompanyId');
        $branchId = $this->branches->getActiveBranchId();
        $stays = HotelStay::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
            ->with(['room', 'folioEntries' => fn ($q) => $q->whereNotNull('pos_transaction_id')])
            ->orderByDesc('id')
            ->paginate(30)->withQueryString();
        $dues = [];
        foreach ($stays as $stay) $dues[$stay->id] = app(\App\Services\HotelDeskService::class)->summary($stay, 'cash')['balance'];

        return view('pos.hotel.folios', compact('stays', 'dues'));
    }

    public function reports()
    {
        HotelAccessService::abortUnlessManageRooms(auth('pos')->user());
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

