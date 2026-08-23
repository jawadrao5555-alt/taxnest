<?php

namespace App\Http\Controllers;

use App\Models\PosHeldSale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * "Bill rok dein" — park an unfinished PRA retail cart and serve the next
 * customer (owner, 23 Aug 2026).
 *
 * Restaurant shops already had this through held RestaurantOrders (KOT flow).
 * Retail shops had nothing: their HOLD button created a restaurant order they
 * could never see again. Retail now parks a plain JSON cart instead — no
 * invoice number, no PRA submission, no stock movement, no day-close impact
 * until the bill is actually paid.
 */
class PosHeldSaleController extends Controller
{
    /** A counter can hold plenty, but not enough to turn the list into a junkyard. */
    private const MAX_HELD = 40;

    private function companyId(): ?int
    {
        return app()->bound('currentCompanyId') ? app('currentCompanyId') : (auth('pos')->user()->company_id ?? null);
    }

    /** Deploy-before-migrate window: behave like "nothing parked" instead of 500ing. */
    private function ready(): bool
    {
        return Schema::hasTable('pos_held_sales');
    }

    /**
     * Only a plain retail shop parks carts here. The sale screen already hides
     * the button for restaurant-shaped shops, but a hidden button is not an
     * authorization boundary: without this, a crafted request could park a cart
     * that the shop's own screen (which lists held ORDERS) can never show again.
     */
    private function allowed(): bool
    {
        $companyId = $this->companyId();

        return $companyId
            ? \App\Services\PosParkedBills::retailShop(\App\Models\Company::find($companyId))
            : false;
    }

    private function refuse()
    {
        return response()->json([
            'success' => false,
            'message' => __('pos.hs_restaurant_uses_orders'),
        ], 403);
    }

    /** Park the current cart. */
    public function store(Request $request)
    {
        if (!$this->ready()) {
            return response()->json(['success' => false, 'message' => __('pos.hs_unavailable')], 503);
        }
        if (!$this->allowed()) {
            return $this->refuse();
        }

        $data = $request->validate([
            'hold_name' => 'nullable|string|max:60',
            'cart_data' => 'required|array',
            'cart_data.items' => 'required|array|min:1',
            'hold_uuid' => 'nullable|string|max:64',
            'customer_id' => 'nullable|integer',
            'customer_name' => 'nullable|string|max:190',
            'customer_phone' => 'nullable|string|max:30',
            'total_amount' => 'nullable|numeric',
        ]);

        $companyId = $this->companyId();
        $user = auth('pos')->user();

        // Idempotency: the same parked cart must never land twice — a lost
        // response, a retry, or an offline hold syncing after reconnect all
        // carry the same uuid (offline-first billing convention).
        if (!empty($data['hold_uuid'])) {
            $existing = PosHeldSale::forCompany($companyId)->where('hold_uuid', $data['hold_uuid'])->first();
            if ($existing) {
                return response()->json(['success' => true, 'held' => $this->row($existing), 'duplicate' => true]);
            }
        }

        if (PosHeldSale::forCompany($companyId)->count() >= self::MAX_HELD) {
            return response()->json([
                'success' => false,
                'message' => __('pos.hs_limit_reached', ['count' => self::MAX_HELD]),
            ], 422);
        }

        $cart = $data['cart_data'];
        // Anti-autofill: the browser can drop the cashier's own login identity
        // into a note field. A note that IS that identity is never a real
        // instruction — strip it before it is parked.
        if (is_array($cart['items'] ?? null)) {
            foreach ($cart['items'] as $k => $item) {
                if (is_array($item) && array_key_exists('special_notes', $item)) {
                    $cart['items'][$k]['special_notes'] =
                        RestaurantWaiterController::stripIdentityNote($item['special_notes'] ?? null, $user);
                }
            }
        }

        $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];

        try {
            $held = PosHeldSale::create([
                'company_id' => $companyId,
                'user_id' => $user->id ?? null,
                'hold_name' => trim($data['hold_name'] ?? '') !== ''
                ? trim($data['hold_name'])
                : ($data['customer_name'] ?? __('pos.hs_default_name')),
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                // Display only — the real money is recalculated when the bill is paid.
                'total_amount' => round((float) ($data['total_amount'] ?? 0), 2),
                'item_count' => count($items),
                'cart_data' => $cart,
                'hold_uuid' => $data['hold_uuid'] ?? null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Two counters (or a retry racing its own request) parked the SAME
            // uuid: the unique index rejected the loser. Hand back the row that
            // won instead of an error — the cart IS parked, exactly once.
            $winner = !empty($data['hold_uuid'])
                ? PosHeldSale::forCompany($companyId)->where('hold_uuid', $data['hold_uuid'])->first()
                : null;
            if (!$winner) {
                throw $e;
            }

            return response()->json(['success' => true, 'held' => $this->row($winner), 'duplicate' => true]);
        }

        return response()->json(['success' => true, 'held' => $this->row($held)]);
    }

    /** Everything this shop has parked (any counter, newest first). */
    public function index()
    {
        if (!$this->ready()) {
            return response()->json(['success' => true, 'held' => []]);
        }
        if (!$this->allowed()) {
            return $this->refuse();
        }

        $rows = PosHeldSale::forCompany($this->companyId())
            ->orderByDesc('created_at')->limit(50)->get()
            ->map(fn ($h) => $this->row($h))->values();

        return response()->json(['success' => true, 'held' => $rows]);
    }

    /**
     * Take a parked cart back to the counter. The conditional delete IS the
     * claim: when two counters recall the same bill, only the one whose delete
     * removed the row gets the cart — the other is told to refresh instead of
     * silently billing the same items twice.
     */
    public function recall($id)
    {
        if (!$this->ready()) {
            return response()->json(['success' => false, 'message' => __('pos.hs_unavailable')], 503);
        }
        if (!$this->allowed()) {
            return $this->refuse();
        }

        $companyId = $this->companyId();
        $held = PosHeldSale::forCompany($companyId)->find((int) $id);
        if (!$held) {
            return response()->json(['success' => false, 'message' => __('pos.hs_already_taken')], 404);
        }

        $cart = $held->cart_data;
        $claimed = PosHeldSale::forCompany($companyId)->where('id', $held->id)->delete();
        if (!$claimed) {
            return response()->json(['success' => false, 'message' => __('pos.hs_already_taken')], 409);
        }

        return response()->json(['success' => true, 'cart' => $cart, 'hold_name' => $held->hold_name]);
    }

    /** Throw a parked cart away (customer left, wrong entry). */
    public function destroy($id)
    {
        if (!$this->ready()) {
            return response()->json(['success' => false, 'message' => __('pos.hs_unavailable')], 503);
        }
        if (!$this->allowed()) {
            return $this->refuse();
        }

        $deleted = PosHeldSale::forCompany($this->companyId())->where('id', (int) $id)->delete();
        if (!$deleted) {
            // Already recalled by the other counter, or never this shop's row.
            return response()->json(['success' => false, 'message' => __('pos.hs_already_taken')], 404);
        }

        return response()->json(['success' => true]);
    }

    /** One shape for the list and for a fresh hold, so the UI never guesses. */
    private function row(PosHeldSale $h): array
    {
        return [
            'id' => (int) $h->id,
            'name' => $h->hold_name,
            'customer_name' => $h->customer_name,
            'customer_phone' => $h->customer_phone,
            'total' => (float) $h->total_amount,
            'items' => (int) $h->item_count,
            'user_id' => $h->user_id ? (int) $h->user_id : null,
            'at' => optional($h->created_at)->toIso8601String(),
        ];
    }
}
