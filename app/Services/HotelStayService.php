<?php

namespace App\Services;

use App\Exceptions\HotelStayException;
use App\Models\HotelRoom;
use App\Models\HotelStay;
use App\Models\HotelStayAssignment;
use App\Models\HotelStayGuest;
use App\Models\PosCustomer;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HotelStayService
{
    public function __construct(
        private HotelFolioService $folio,
        private BranchContextService $branches,
    ) {
    }

    public static function nights(string $checkIn, string $checkOut): int
    {
        $in = Carbon::parse($checkIn)->startOfDay();
        $out = Carbon::parse($checkOut)->startOfDay();
        if ($out->lte($in)) {
            throw new HotelStayException(__('pos.hotel_dates_invalid'));
        }

        return (int) $in->diffInDays($out);
    }

    public function createRoom(int $companyId, array $data): HotelRoom
    {
        $branchId = array_key_exists('branch_id', $data)
            ? ($data['branch_id'] !== null ? (int) $data['branch_id'] : null)
            : $this->branches->stampBranchId();
        $number = trim((string) ($data['room_number'] ?? ''));
        if ($number === '') {
            throw new HotelStayException(__('pos.hotel_room_number_required'));
        }
        $this->assertRoomNumberFree($companyId, $branchId, $number);

        $unit = PosUnitCatalog::normalize($data['rate_unit'] ?? 'NGT') ?: 'NGT';
        if (!PosUnitCatalog::isValid($unit)) {
            $unit = 'NGT';
        }

        return HotelRoom::create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'room_number' => $number,
            'room_type' => trim((string) ($data['room_type'] ?? 'Standard')) ?: 'Standard',
            'capacity' => max(1, (int) ($data['capacity'] ?? 2)),
            'rate_amount' => round((float) ($data['rate_amount'] ?? 0), 2),
            'rate_unit' => $unit,
            'charging_rule' => HotelRoom::CHARGING_NIGHTLY,
            'service_state' => ($data['service_state'] ?? HotelRoom::SERVICE_IN) === HotelRoom::SERVICE_OUT
                ? HotelRoom::SERVICE_OUT
                : HotelRoom::SERVICE_IN,
            'housekeeping' => $this->normalizeHousekeeping($data['housekeeping'] ?? HotelRoom::HK_CLEAN),
            'notes' => $data['notes'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    public function updateRoom(HotelRoom $room, array $data): HotelRoom
    {
        if (isset($data['room_number']) && trim((string) $data['room_number']) !== $room->room_number) {
            $this->assertRoomNumberFree(
                (int) $room->company_id,
                $room->branch_id ? (int) $room->branch_id : null,
                trim((string) $data['room_number']),
                (int) $room->id
            );
            $room->room_number = trim((string) $data['room_number']);
        }
        foreach (['room_type', 'notes'] as $k) {
            if (array_key_exists($k, $data)) {
                $room->{$k} = $data[$k];
            }
        }
        if (isset($data['capacity'])) {
            $room->capacity = max(1, (int) $data['capacity']);
        }
        if (isset($data['rate_amount'])) {
            $room->rate_amount = round((float) $data['rate_amount'], 2);
        }
        if (isset($data['rate_unit'])) {
            $unit = PosUnitCatalog::normalize($data['rate_unit']) ?: $room->rate_unit;
            if (PosUnitCatalog::isValid($unit)) {
                $room->rate_unit = $unit;
            }
        }
        if (isset($data['service_state'])) {
            $room->service_state = $data['service_state'] === HotelRoom::SERVICE_OUT
                ? HotelRoom::SERVICE_OUT
                : HotelRoom::SERVICE_IN;
        }
        if (isset($data['housekeeping'])) {
            $room->housekeeping = $this->normalizeHousekeeping($data['housekeeping']);
        }
        if (isset($data['is_active'])) {
            $room->is_active = (bool) $data['is_active'];
        }
        $room->charging_rule = HotelRoom::CHARGING_NIGHTLY;
        $room->save();

        return $room;
    }

    public function setHousekeeping(HotelRoom $room, string $state): HotelRoom
    {
        $room->housekeeping = $this->normalizeHousekeeping($state);
        $room->save();

        return $room;
    }

    /**
     * @param  array{
     *   room_id:int, check_in_date:string, check_out_date:string,
     *   guest_name:string, guest_phone?:?string, guest_cnic?:?string,
     *   guest_customer_id?:?int, payer_customer_id?:?int,
     *   adult_count?:int, child_count?:int, notes?:?string,
     *   walk_in?:bool, idempotency_key?:?string
     * }  $data
     */
    public function book(int $companyId, int $userId, array $data): HotelStay
    {
        $last = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($companyId, $userId, $data) {
                    return $this->bookInsideTransaction($companyId, $userId, $data);
                });
            } catch (QueryException $e) {
                $last = $e;
                if (!$this->isRetryableConcurrency($e) || $attempt === 4) {
                    throw $e;
                }
                usleep(25000 * ($attempt + 1));
            }
        }

        throw $last ?? new HotelStayException(__('pos.hotel_room_overlap'));
    }

    /**
     * @param  array{
     *   room_id:int, check_in_date:string, check_out_date:string,
     *   guest_name:string, guest_phone?:?string, guest_cnic?:?string,
     *   guest_customer_id?:?int, payer_customer_id?:?int,
     *   adult_count?:int, child_count?:int, notes?:?string,
     *   walk_in?:bool, idempotency_key?:?string
     * }  $data
     */
    private function bookInsideTransaction(int $companyId, int $userId, array $data): HotelStay
    {
            $key = $this->usableIdempotency($companyId, $data['idempotency_key'] ?? null);
            if ($key) {
                $existing = HotelStay::where('company_id', $companyId)->where('idempotency_key', $key)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $nights = self::nights($data['check_in_date'], $data['check_out_date']);
            $room = HotelRoom::where('company_id', $companyId)
                ->where('id', (int) $data['room_id'])
                ->lockForUpdate()
                ->first();
            if (!$room) {
                throw new HotelStayException(__('pos.hotel_room_not_found'));
            }
            if ($room->isOutOfService()) {
                throw new HotelStayException(__('pos.hotel_room_out_of_service'));
            }
            $adults = max(1, (int) ($data['adult_count'] ?? 1));
            $children = max(0, (int) ($data['child_count'] ?? 0));
            if (($adults + $children) > (int) $room->capacity) {
                throw new HotelStayException(__('pos.hotel_capacity_exceeded'));
            }
            $this->assertRoomFree(
                $companyId,
                (int) $room->id,
                (string) $data['check_in_date'],
                (string) $data['check_out_date']
            );

            $guest = $this->resolveCustomer($companyId, $data['guest_customer_id'] ?? null);
            $payer = $this->resolveCustomer($companyId, $data['payer_customer_id'] ?? null);
            $walkIn = (bool) ($data['walk_in'] ?? false);

            $payload = [
                'company_id' => $companyId,
                'branch_id' => $room->branch_id,
                'stay_number' => HotelStaySeries::issueNext($companyId),
                'status' => $walkIn ? HotelStay::STATUS_CHECKED_IN : HotelStay::STATUS_RESERVED,
                'room_id' => $room->id,
                'guest_customer_id' => $guest?->id,
                'payer_customer_id' => $payer?->id,
                'guest_name' => trim((string) ($data['guest_name'] ?? $guest?->name ?? '')),
                'guest_phone' => $data['guest_phone'] ?? $guest?->phone,
                'guest_cnic' => $data['guest_cnic'] ?? $guest?->cnic,
                'check_in_date' => $data['check_in_date'],
                'check_out_date' => $data['check_out_date'],
                'actual_check_in_at' => $walkIn ? now() : null,
                'adult_count' => $adults,
                'child_count' => $children,
                'nights' => $nights,
                'rate_amount' => (float) $room->rate_amount,
                'rate_unit' => $room->rate_unit ?: 'NGT',
                'charging_rule' => HotelRoom::CHARGING_NIGHTLY,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $key,
                'created_by' => $userId,
            ];
            try {
                $stay = HotelStay::create($payload);
            } catch (QueryException $e) {
                if ($key) {
                    $existing = HotelStay::where('company_id', $companyId)->where('idempotency_key', $key)->first();
                    if ($existing) {
                        return $existing->load(['room', 'occupants', 'folioEntries']);
                    }
                }
                throw $e;
            }
            if ($stay->guest_name === '') {
                throw new HotelStayException(__('pos.hotel_guest_required'));
            }

            HotelStayAssignment::create([
                'company_id' => $companyId,
                'stay_id' => $stay->id,
                'room_id' => $room->id,
                'from_date' => $stay->check_in_date,
                'to_date' => $stay->check_out_date,
                'rate_amount' => $stay->rate_amount,
                'rate_unit' => $stay->rate_unit,
            ]);
            HotelStayGuest::create([
                'company_id' => $companyId,
                'stay_id' => $stay->id,
                'customer_id' => $guest?->id,
                'is_primary' => true,
                'name' => $stay->guest_name,
                'phone' => $stay->guest_phone,
                'cnic' => $stay->guest_cnic,
            ]);

            $stay->setRelation('room', $room);
            if ($walkIn) {
                $this->postNightlyCharge($stay, $nights, 'check-in', $userId);
            }

            AuditLogService::log('hotel_stay_created', 'hotel_stay', $stay->id, null, [
                'stay_number' => $stay->stay_number,
                'status' => $stay->status,
                'walk_in' => $walkIn,
            ], $companyId, $userId);

            return $stay->fresh(['room', 'occupants', 'folioEntries']);
    }

    private function isRetryableConcurrency(QueryException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, '1020')
            || str_contains($msg, '1213')
            || str_contains($msg, 'Deadlock')
            || str_contains($msg, 'try restarting transaction');
    }

    public function checkIn(HotelStay $stay, int $userId, ?string $idempotencyKey = null): HotelStay
    {
        return DB::transaction(function () use ($stay, $userId, $idempotencyKey) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);
            if ($stay->status === HotelStay::STATUS_CHECKED_IN) {
                return $stay;
            }
            if ($stay->status !== HotelStay::STATUS_RESERVED) {
                throw new HotelStayException(__('pos.hotel_transition_blocked'));
            }
            $room = HotelRoom::where('company_id', $stay->company_id)
                ->where('id', $stay->room_id)
                ->lockForUpdate()
                ->first();
            if (!$room || $room->isOutOfService()) {
                throw new HotelStayException(__('pos.hotel_room_out_of_service'));
            }
            $this->assertRoomFree(
                (int) $stay->company_id,
                (int) $stay->room_id,
                $stay->check_in_date->toDateString(),
                $stay->check_out_date->toDateString(),
                (int) $stay->id
            );
            $stay->status = HotelStay::STATUS_CHECKED_IN;
            $stay->actual_check_in_at = now();
            $stay->save();
            $stay->setRelation('room', $room);
            $this->postNightlyCharge($stay, (int) $stay->nights, 'check-in', $userId, $idempotencyKey);
            AuditLogService::log('hotel_checked_in', 'hotel_stay', $stay->id, null, [
                'stay_number' => $stay->stay_number,
            ], (int) $stay->company_id, $userId);

            return $stay->fresh(['room', 'folioEntries']);
        });
    }

    public function checkOut(HotelStay $stay, int $userId): HotelStay
    {
        return DB::transaction(function () use ($stay, $userId) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);
            if ($stay->status === HotelStay::STATUS_CHECKED_OUT) {
                return $stay;
            }
            if ($stay->status !== HotelStay::STATUS_CHECKED_IN) {
                throw new HotelStayException(__('pos.hotel_transition_blocked'));
            }
            $stay->status = HotelStay::STATUS_CHECKED_OUT;
            $stay->actual_check_out_at = now();
            $stay->save();
            if ($stay->room_id) {
                HotelRoom::where('company_id', $stay->company_id)
                    ->where('id', $stay->room_id)
                    ->update(['housekeeping' => HotelRoom::HK_DIRTY]);
            }
            AuditLogService::log('hotel_checked_out', 'hotel_stay', $stay->id, null, [
                'stay_number' => $stay->stay_number,
            ], (int) $stay->company_id, $userId);

            return $stay->fresh(['room', 'folioEntries']);
        });
    }

    public function extend(HotelStay $stay, string $newCheckOut, int $userId): HotelStay
    {
        return DB::transaction(function () use ($stay, $newCheckOut, $userId) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);
            if (!in_array($stay->status, [HotelStay::STATUS_RESERVED, HotelStay::STATUS_CHECKED_IN], true)) {
                throw new HotelStayException(__('pos.hotel_transition_blocked'));
            }
            $oldNights = (int) $stay->nights;
            $newNights = self::nights($stay->check_in_date->toDateString(), $newCheckOut);
            if ($newNights <= $oldNights) {
                throw new HotelStayException(__('pos.hotel_extend_later_only'));
            }
            HotelRoom::where('company_id', $stay->company_id)->where('id', $stay->room_id)->lockForUpdate()->first();
            $this->assertRoomFree(
                (int) $stay->company_id,
                (int) $stay->room_id,
                $stay->check_in_date->toDateString(),
                $newCheckOut,
                (int) $stay->id
            );
            $extra = $newNights - $oldNights;
            $stay->check_out_date = $newCheckOut;
            $stay->nights = $newNights;
            $stay->save();
            $assignment = HotelStayAssignment::where('company_id', $stay->company_id)
                ->where('stay_id', $stay->id)
                ->where('room_id', $stay->room_id)
                ->orderByDesc('id')
                ->first();
            if ($assignment) {
                $assignment->to_date = $newCheckOut;
                $assignment->save();
            }
            if ($stay->status === HotelStay::STATUS_CHECKED_IN) {
                $this->postNightlyCharge($stay, $extra, 'extension', $userId);
            }
            AuditLogService::log('hotel_extended', 'hotel_stay', $stay->id, [
                'nights' => $oldNights,
            ], [
                'nights' => $newNights,
                'check_out_date' => $newCheckOut,
            ], (int) $stay->company_id, $userId);

            return $stay->fresh(['room', 'folioEntries']);
        });
    }

    public function moveRoom(HotelStay $stay, int $newRoomId, int $userId): HotelStay
    {
        return DB::transaction(function () use ($stay, $newRoomId, $userId) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);
            if (!in_array($stay->status, [HotelStay::STATUS_RESERVED, HotelStay::STATUS_CHECKED_IN], true)) {
                throw new HotelStayException(__('pos.hotel_transition_blocked'));
            }
            $from = $stay->check_in_date->toDateString();
            $to = $stay->check_out_date->toDateString();
            if ($stay->status === HotelStay::STATUS_CHECKED_IN) {
                $from = now()->toDateString();
            }
            $room = HotelRoom::where('company_id', $stay->company_id)
                ->where('id', $newRoomId)
                ->lockForUpdate()
                ->first();
            if (!$room) {
                throw new HotelStayException(__('pos.hotel_room_not_found'));
            }
            if ($room->isOutOfService()) {
                throw new HotelStayException(__('pos.hotel_room_out_of_service'));
            }
            if (($stay->adult_count + $stay->child_count) > (int) $room->capacity) {
                throw new HotelStayException(__('pos.hotel_capacity_exceeded'));
            }
            $this->assertRoomFree((int) $stay->company_id, (int) $room->id, $from, $to, (int) $stay->id);
            $oldRoomId = (int) $stay->room_id;
            $assignment = HotelStayAssignment::where('company_id', $stay->company_id)
                ->where('stay_id', $stay->id)
                ->where('room_id', $oldRoomId)
                ->orderByDesc('id')
                ->first();
            if ($assignment) {
                $assignment->to_date = $from;
                $assignment->save();
            }
            HotelStayAssignment::create([
                'company_id' => $stay->company_id,
                'stay_id' => $stay->id,
                'room_id' => $room->id,
                'from_date' => $from,
                'to_date' => $to,
                'rate_amount' => $room->rate_amount,
                'rate_unit' => $room->rate_unit,
            ]);
            $stay->room_id = $room->id;
            $stay->branch_id = $room->branch_id;
            $stay->save();
            AuditLogService::log('hotel_room_moved', 'hotel_stay', $stay->id, [
                'room_id' => $oldRoomId,
            ], [
                'room_id' => $room->id,
            ], (int) $stay->company_id, $userId);

            return $stay->fresh(['room']);
        });
    }

    public function cancel(HotelStay $stay, int $userId, ?string $reason = null): HotelStay
    {
        return $this->closeWithoutStay($stay, HotelStay::STATUS_CANCELLED, $userId, $reason, 'hotel_cancelled');
    }

    public function markNoShow(HotelStay $stay, int $userId, ?string $reason = null): HotelStay
    {
        return $this->closeWithoutStay($stay, HotelStay::STATUS_NO_SHOW, $userId, $reason, 'hotel_no_show');
    }

    /**
     * Informational occupancy strip for retail/restaurant dashboards & day-close.
     *
     * Uses the company's *stored* rooms flag + hotel_rooms table presence —
     * not PosFeatureService::moduleAvailable() / forCompany(). Those resolve
     * plan gates (subscriptions) and break intentional minimal-schema
     * dashboard tests. Hotel routes still sit behind feature:rooms middleware.
     *
     * @return array<string,int|float>|null null when Rooms is off / unmigrated
     */
    public function occupancyForCompany(?\App\Models\Company $company, ?int $branchId = null, ?string $onDate = null): ?array
    {
        if (!$company || !Schema::hasTable('hotel_rooms')) {
            return null;
        }
        $flags = is_array($company->feature_flags) ? $company->feature_flags : [];
        if (empty($flags['rooms'])) {
            return null;
        }

        return $this->occupancy((int) $company->id, $branchId, $onDate);
    }

    public function occupancy(int $companyId, ?int $branchId = null, ?string $onDate = null): array
    {
        if (!Schema::hasTable('hotel_rooms')) {
            return [
                'rooms' => 0, 'in_house' => 0, 'arrivals' => 0, 'departures' => 0,
                'out_of_service' => 0, 'dirty' => 0, 'available' => 0, 'reserved' => 0,
                'pending_due_count' => 0, 'pending_due_amount' => 0.0,
            ];
        }
        $onDate = $onDate ?: now()->toDateString();
        $rooms = HotelRoom::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true);
        $roomCount = (clone $rooms)->count();
        $oos = (clone $rooms)->where('service_state', HotelRoom::SERVICE_OUT)->count();
        $dirty = (clone $rooms)->where('housekeeping', HotelRoom::HK_DIRTY)->count();

        $stays = HotelStay::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $inHouse = (clone $stays)->where('status', HotelStay::STATUS_CHECKED_IN)->count();
        $reserved = (clone $stays)->where('status', HotelStay::STATUS_RESERVED)
            ->whereDate('check_in_date', '<=', $onDate)
            ->whereDate('check_out_date', '>', $onDate)
            ->count();
        $arrivals = (clone $stays)->whereIn('status', HotelStay::OPEN_STATUSES)
            ->whereDate('check_in_date', $onDate)
            ->count();
        $departures = (clone $stays)->where('status', HotelStay::STATUS_CHECKED_IN)
            ->whereDate('check_out_date', $onDate)
            ->count();

        return [
            'rooms' => $roomCount,
            'in_house' => $inHouse,
            'arrivals' => $arrivals,
            'departures' => $departures,
            'out_of_service' => $oos,
            'dirty' => $dirty,
            'reserved' => $reserved,
            'available' => max(0, $roomCount - $oos - $inHouse - $reserved),
            'pending_due_count' => 0,
            'pending_due_amount' => 0.0,
        ];
    }

    /**
     * Front-desk board: occupancy plus the lists the dashboard actually shows.
     *
     * @return array{
     *   occupancy:array, available:\Illuminate\Support\Collection,
     *   dirty:\Illuminate\Support\Collection, inHouse:\Illuminate\Support\Collection,
     *   arrivals:\Illuminate\Support\Collection, departures:\Illuminate\Support\Collection,
     *   pending:\Illuminate\Support\Collection, dues:array<int,float>
     * }
     */
    public function board(int $companyId, ?int $branchId = null): array
    {
        $occupancy = $this->occupancy($companyId, $branchId);
        $today = now()->toDateString();
        $rooms = HotelRoom::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->orderBy('room_number')
            ->get();
        $open = HotelStay::where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', HotelStay::OPEN_STATUSES)
            ->with('room')
            ->orderBy('stay_number')
            ->get();
        $occupiedIds = $open->pluck('room_id')->filter()->unique();
        $available = $rooms->filter(
            fn ($room) => !$room->isOutOfService() && !$occupiedIds->contains($room->id)
        )->values();
        $dirty = $rooms->filter(fn ($room) => $room->housekeeping === HotelRoom::HK_DIRTY)->values();
        $inHouse = $open->where('status', HotelStay::STATUS_CHECKED_IN)
            ->sortBy('check_out_date')
            ->take(20)
            ->values();
        $arrivals = $open->filter(
            fn ($stay) => $stay->check_in_date->toDateString() === $today
        )->values();
        $departures = $open->filter(
            fn ($stay) => $stay->status === HotelStay::STATUS_CHECKED_IN
                && $stay->check_out_date->toDateString() === $today
        )->values();
        $dues = $this->folio->chargeDuesForStayIds($companyId, $inHouse->pluck('id')->all());
        $pending = $inHouse->filter(fn ($stay) => ($dues[$stay->id] ?? 0) > 0.009)->values();
        $occupancy['pending_due_count'] = $pending->count();
        $occupancy['pending_due_amount'] = round((float) array_sum($pending->map(fn ($s) => $dues[$s->id] ?? 0)->all()), 2);
        $occupancy['available'] = $available->count();

        return compact('occupancy', 'available', 'dirty', 'inHouse', 'arrivals', 'departures', 'pending', 'dues');
    }

    public function assertRoomFree(int $companyId, int $roomId, string $from, string $to, ?int $ignoreStayId = null): void
    {
        $stayOverlap = HotelStay::where('company_id', $companyId)
            ->where('room_id', $roomId)
            ->whereIn('status', HotelStay::OPEN_STATUSES)
            ->whereDate('check_in_date', '<', $to)
            ->whereDate('check_out_date', '>', $from);
        if ($ignoreStayId) {
            $stayOverlap->where('id', '!=', $ignoreStayId);
        }
        if ($stayOverlap->exists()) {
            throw new HotelStayException(__('pos.hotel_room_overlap'));
        }

        $assignmentOverlap = HotelStayAssignment::where('company_id', $companyId)
            ->where('room_id', $roomId)
            ->whereDate('from_date', '<', $to)
            ->whereDate('to_date', '>', $from)
            ->whereHas('stay', function ($q) use ($companyId, $ignoreStayId) {
                $q->where('company_id', $companyId)
                    ->whereIn('status', HotelStay::OPEN_STATUSES);
                if ($ignoreStayId) {
                    $q->where('id', '!=', $ignoreStayId);
                }
            });
        if ($assignmentOverlap->exists()) {
            throw new HotelStayException(__('pos.hotel_room_overlap'));
        }
    }

    private function closeWithoutStay(HotelStay $stay, string $status, int $userId, ?string $reason, string $audit): HotelStay
    {
        return DB::transaction(function () use ($stay, $status, $userId, $reason, $audit) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);
            if ($stay->status !== HotelStay::STATUS_RESERVED) {
                throw new HotelStayException(__('pos.hotel_transition_blocked'));
            }
            $stay->status = $status;
            $stay->cancel_reason = $reason;
            $stay->save();
            AuditLogService::log($audit, 'hotel_stay', $stay->id, null, [
                'stay_number' => $stay->stay_number,
                'reason' => $reason,
            ], (int) $stay->company_id, $userId);

            return $stay;
        });
    }

    private function postNightlyCharge(HotelStay $stay, int $nights, string $reason, int $userId, ?string $idempotencyKey = null): void
    {
        if ($nights < 1) {
            return;
        }
        $this->folio->postCharge($stay, [
            'category' => 'room',
            'description' => __('pos.hotel_room_charge_line', [
                'room' => $stay->room?->room_number ?? ('#' . $stay->room_id),
                'nights' => $nights,
                'reason' => $reason,
            ]),
            'quantity' => $nights,
            'uom' => $stay->rate_unit ?: 'NGT',
            'unit_amount' => (float) $stay->rate_amount,
            'idempotency_key' => $idempotencyKey,
        ], $userId);
    }

    private function assertRoomNumberFree(int $companyId, ?int $branchId, string $number, ?int $ignoreId = null): void
    {
        $q = HotelRoom::where('company_id', $companyId)->where('room_number', $number);
        if ($branchId) {
            $q->where('branch_id', $branchId);
        } else {
            $q->where(function ($w) {
                $w->whereNull('branch_id')->orWhere('branch_id', 0);
            });
        }
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($q->exists()) {
            throw new HotelStayException(__('pos.hotel_room_number_taken'));
        }
    }

    private function normalizeHousekeeping(string $state): string
    {
        return in_array($state, [HotelRoom::HK_CLEAN, HotelRoom::HK_DIRTY, HotelRoom::HK_INSPECTED], true)
            ? $state
            : HotelRoom::HK_CLEAN;
    }

    private function resolveCustomer(int $companyId, mixed $id): ?PosCustomer
    {
        $id = (int) $id;
        if ($id < 1 || !Schema::hasTable('pos_customers')) {
            return null;
        }

        return PosCustomer::where('company_id', $companyId)->where('id', $id)->first();
    }

    private function usableIdempotency(int $companyId, ?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        return substr($key, 0, 64);
    }
}
