<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\PosCustomerPlace;
use App\Models\PosDeliveryCompletion;
use App\Models\PosRider;
use App\Models\PosTransaction;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PosCustomerPlaceController extends Controller
{
    private function companyId(): int
    {
        return (int) app('currentCompanyId');
    }

    private function gate(): ?\Illuminate\Http\JsonResponse
    {
        $user = auth('pos')->user();
        if ($user && !$user->isPosAdmin()) {
            return response()->json(['ok' => false, 'error' => 'admin_required'], 403);
        }
        $company = Company::find($this->companyId());
        if (!PosFeatureService::planAllows($company, 'riders_enabled')
            || !PosFeatureService::planAllows($company, 'rider_tracking_enabled')) {
            return response()->json(['ok' => false, 'error' => 'plan_locked'], 403);
        }
        if (!Schema::hasTable('pos_customer_places')
            || !Schema::hasTable('pos_delivery_completions')) {
            return response()->json(['ok' => false, 'error' => 'schema_not_ready'], 503);
        }
        return null;
    }

    private function place(int $id): PosCustomerPlace
    {
        return PosCustomerPlace::where('company_id', $this->companyId())->findOrFail($id);
    }

    public function index(Request $request)
    {
        if ($err = $this->gate()) {
            return $request->expectsJson()
                ? $err
                : redirect()->route('pos.riders.tracking')->with('error', __('pos.places_unavailable'));
        }

        $q = trim((string) $request->query('q', ''));
        $places = PosCustomerPlace::where('company_id', $this->companyId())
            ->with('customer:id,company_id,name,phone')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                $query->where(function ($w) use ($like) {
                    $w->where('label', 'like', $like)
                        ->orWhere('address', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like)
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like));
                });
            })
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $mergeTargets = PosCustomerPlace::where('company_id', $this->companyId())
            ->orderBy('label')->orderByDesc('last_used_at')
            ->limit(1000)
            ->get(['id', 'label', 'place_type', 'customer_id', 'customer_phone', 'lat', 'lng']);

        return view('pos.customer-places', compact('places', 'mergeTargets', 'q'));
    }

    public function data()
    {
        if ($err = $this->gate()) return $err;
        $companyId = $this->companyId();

        $places = PosCustomerPlace::where('company_id', $companyId)
            // Map context, not the management archive: cap recent pins so an
            // established shop does not create 1,000 Leaflet DOM markers at
            // startup. The management page still exposes the full collection.
            ->orderByDesc('last_used_at')->limit(300)
            ->get(['id', 'place_type', 'label', 'address', 'lat', 'lng', 'is_verified', 'usage_count', 'last_used_at'])
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'type' => $p->place_type,
                'label' => $p->label,
                'address' => $p->address,
                'lat' => (float) $p->lat,
                'lng' => (float) $p->lng,
                'verified' => (bool) $p->is_verified,
                'uses' => (int) $p->usage_count,
                'last_used_at' => $p->last_used_at?->toIso8601String(),
            ])->values();

        $completionRows = PosDeliveryCompletion::where('company_id', $companyId)
            ->whereNotNull('completed_lat')
            ->where('captured_at', '>=', now()->subDays(30))
            ->with('rider:id,name')
            ->orderByDesc('captured_at')->limit(500)
            ->get();

        $arrivals = $completionRows->map(fn ($c) => [
                'id' => (int) $c->id,
                'place_id' => $c->customer_place_id ? (int) $c->customer_place_id : null,
                'type' => $c->place_type,
                'label' => $c->place_label,
                'lat' => (float) $c->completed_lat,
                'lng' => (float) $c->completed_lng,
                'rider' => $c->rider?->name,
                'captured_at' => $c->captured_at?->toIso8601String(),
                'distance_m' => $c->distance_m,
                'verified' => (bool) $c->proximity_verified,
            ])->values();

        // Private learned approaches: reconstruct the final recorded stretch
        // before the newest confirmed arrival at each place. This uses the
        // company's own rider fixes only; it adds no third-party road data and
        // never enters the public tracking payload.
        $approaches = collect();
        if (Schema::hasTable('pos_rider_locations')) {
            $seenPlaces = [];
            $attempts = 0;
            foreach ($completionRows as $completion) {
                $placeId = (int) ($completion->customer_place_id ?? 0);
                if (!$placeId || isset($seenPlaces[$placeId])
                    || !$completion->proximity_verified || !$completion->captured_at) {
                    continue;
                }
                if (++$attempts > 60 || $approaches->count() >= 24) break;

                $fixes = DB::table('pos_rider_locations')
                    ->where('company_id', $companyId)
                    ->where('rider_id', $completion->rider_id)
                    ->whereBetween('recorded_at', [
                        $completion->captured_at->copy()->subMinutes(12),
                        $completion->captured_at->copy()->addMinute(),
                    ])
                    ->orderBy('recorded_at')
                    ->limit(300)
                    ->get(['lat', 'lng'])
                    ->filter(function ($point) use ($completion) {
                        return PosRider::haversineKm(
                            (float) $point->lat,
                            (float) $point->lng,
                            (float) $completion->completed_lat,
                            (float) $completion->completed_lng
                        ) <= 2.0;
                    })
                    ->map(fn ($point) => [(float) $point->lat, (float) $point->lng])
                    ->values();

                if ($fixes->count() < 2) continue;
                $stride = max(1, (int) ceil($fixes->count() / 40));
                $points = $fixes->filter(fn ($point, $index) => $index % $stride === 0)->values();
                $lastFix = $fixes->last();
                if ($points->last() !== $lastFix) $points->push($lastFix);

                // The completion point is trusted evidence, but only connect it
                // when the final rider fix was already close. Never invent a
                // long straight "lane" across missing GPS.
                $finalDistanceKm = PosRider::haversineKm(
                    (float) $lastFix[0],
                    (float) $lastFix[1],
                    (float) $completion->completed_lat,
                    (float) $completion->completed_lng
                );
                if ($finalDistanceKm > 0.003 && $finalDistanceKm <= 0.25) {
                    $points->push([
                        (float) $completion->completed_lat,
                        (float) $completion->completed_lng,
                    ]);
                }

                $approaches->push([
                    'place_id' => $placeId,
                    'captured_at' => $completion->captured_at->toIso8601String(),
                    'points' => $points,
                ]);
                $seenPlaces[$placeId] = true;
            }
        }

        return response()->json([
            'ok' => true,
            'places' => $places,
            'arrivals' => $arrivals,
            'approaches' => $approaches,
        ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        if ($err = $this->gate()) return $err;
        $companyId = $this->companyId();
        $data = $request->validate([
            'customer_id' => 'nullable|integer',
            'customer_phone' => 'nullable|string|max:40',
            'place_type' => ['required', Rule::in(['home', 'business', 'other'])],
            'label' => 'nullable|string|max:80',
            'address' => 'nullable|string|max:500',
            'lat' => 'required|numeric|between:22.8,37.5',
            'lng' => 'required|numeric|between:60.4,77.6',
        ]);
        if (!empty($data['customer_id'])) {
            PosCustomer::where('company_id', $companyId)->findOrFail((int) $data['customer_id']);
        }

        $place = PosCustomerPlace::create([
            'company_id' => $companyId,
            'customer_id' => $data['customer_id'] ?? null,
            'customer_phone' => trim((string) ($data['customer_phone'] ?? '')) ?: null,
            'place_type' => $data['place_type'],
            'label' => trim((string) ($data['label'] ?? '')) ?: null,
            'address' => trim((string) ($data['address'] ?? '')) ?: null,
            'lat' => round((float) $data['lat'], 7),
            'lng' => round((float) $data['lng'], 7),
            'is_verified' => true,
            'verified_at' => now(),
            'created_from' => 'manager',
            'updated_by' => auth('pos')->id(),
        ]);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'place' => $place], 201)
            : back()->with('success', __('pos.place_created'));
    }

    public function update(Request $request, int $id)
    {
        if ($err = $this->gate()) return $err;
        $place = $this->place($id);
        $data = $request->validate([
            'place_type' => ['required', Rule::in(['home', 'business', 'other'])],
            'label' => 'nullable|string|max:80',
            'address' => 'nullable|string|max:500',
            'lat' => 'required|numeric|between:22.8,37.5',
            'lng' => 'required|numeric|between:60.4,77.6',
        ]);
        $place->update([
            'place_type' => $data['place_type'],
            'label' => trim((string) ($data['label'] ?? '')) ?: null,
            'address' => trim((string) ($data['address'] ?? '')) ?: null,
            'lat' => round((float) $data['lat'], 7),
            'lng' => round((float) $data['lng'], 7),
            'is_verified' => true,
            'verified_at' => now(),
            'updated_by' => auth('pos')->id(),
        ]);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'place' => $place->fresh()])
            : back()->with('success', __('pos.place_updated'));
    }

    public function merge(Request $request, int $id)
    {
        if ($err = $this->gate()) return $err;
        $data = $request->validate(['target_id' => 'required|integer|different:' . $id]);
        $companyId = $this->companyId();

        DB::transaction(function () use ($companyId, $id, $data) {
            $source = PosCustomerPlace::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $target = PosCustomerPlace::where('company_id', $companyId)->lockForUpdate()->findOrFail((int) $data['target_id']);
            if ((int) $source->id === (int) $target->id) abort(422);
            $sameCustomer = ($source->customer_id && $target->customer_id
                    && (int) $source->customer_id === (int) $target->customer_id)
                || (!$source->customer_id && !$target->customer_id
                    && filled($source->customer_phone)
                    && $source->customer_phone === $target->customer_phone);
            if (!$sameCustomer) {
                abort(422, __('pos.place_merge_customer_mismatch'));
            }

            PosDeliveryCompletion::where('company_id', $companyId)
                ->where('customer_place_id', $source->id)
                ->update(['customer_place_id' => $target->id]);
            if (Schema::hasColumn('pos_transactions', 'customer_place_id')) {
                PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->where('customer_place_id', $source->id)
                    ->update(['customer_place_id' => $target->id]);
            }

            $target->update([
                'usage_count' => (int) $target->usage_count + (int) $source->usage_count,
                'last_used_at' => collect([$target->last_used_at, $source->last_used_at])->filter()->max(),
                'updated_by' => auth('pos')->id(),
            ]);
            $source->update(['merged_into_id' => $target->id, 'merged_by' => auth('pos')->id()]);
            $source->delete();
        });

        return back()->with('success', __('pos.place_merged'));
    }

    public function destroy(Request $request, int $id)
    {
        if ($err = $this->gate()) return $err;
        $place = $this->place($id);
        $place->update(['deleted_by' => auth('pos')->id()]);
        $place->delete();

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('success', __('pos.place_deleted'));
    }
}