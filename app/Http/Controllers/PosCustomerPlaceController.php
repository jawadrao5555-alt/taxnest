<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\PosCustomerPlace;
use App\Models\PosDeliveryCompletion;
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
            ->orderByDesc('last_used_at')->limit(1000)
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

        $arrivals = PosDeliveryCompletion::where('company_id', $companyId)
            ->whereNotNull('completed_lat')
            ->where('captured_at', '>=', now()->subDays(30))
            ->with('rider:id,name')
            ->orderByDesc('captured_at')->limit(500)
            ->get()
            ->map(fn ($c) => [
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

        return response()->json(['ok' => true, 'places' => $places, 'arrivals' => $arrivals])
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