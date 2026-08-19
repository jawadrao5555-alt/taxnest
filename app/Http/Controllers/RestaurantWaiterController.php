<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosProduct;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P7 (F6) — Waiter Tablets.
 *
 * Waiters compose orders on a tablet (customer, table, items) and SEND them to a
 * chosen cashier. Orders are plain RestaurantOrder rows (status 'held',
 * source='waiter', assigned_cashier_id set) so KDS, tables, and day-close all
 * see them with ZERO new lifecycle. The cashier finalizes payment through the
 * normal storeInvoice path — the monthly bill quota applies THERE only; waiter
 * sends are free. Delta-KOT: restaurant_order_items.kot_printed_at is stamped
 * when a ticket actually prints, so appended items print alone.
 */
class RestaurantWaiterController extends Controller
{
    /** Restaurant surfaces are Pro/Unlimited only (P2) — same flags the sale screen uses. */
    private function restaurantOn(Company $company): bool
    {
        $features = PosFeatureService::forCompany($company);
        return (bool) (($features->tables ?? false) || ($features->kot ?? false) || ($features->kitchen ?? false) || ($features->restaurant_mode ?? false));
    }

    /**
     * Per-waiter style pref (owner, 5 Aug 2026): waiter apni marzi se Full/Saaf
     * chun sake — users.pos_personal_style is THIS user's own pick and overrides
     * the company style BOTH directions (NULL = company default). Column is
     * hasColumn-guarded for prod schema drift (503 + friendly message).
     */
    public function saveStyle(Request $request)
    {
        // Allowed styles come from the central catalogue (User::WAITER_STYLES)
        // so a newly added theme is accepted here without touching this file.
        $request->validate(['style' => 'required|in:' . implode(',', array_keys(\App\Models\User::WAITER_STYLES))]);
        $user = auth('pos')->user();
        // Waiter-only (architect review): admins/managers can OPEN the tablet,
        // but the personal-style override is scoped to waiters — everyone else
        // keeps the company style everywhere (no partial-theming states).
        if (($user->pos_role ?? null) !== 'pos_waiter') {
            return response()->json(['ok' => false], 403);
        }
        if (!\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_personal_style')) {
            return response()->json(['ok' => false, 'error' => __('pos.setting_save_failed')], 503);
        }
        $user->pos_personal_style = $request->input('style');
        $user->save();
        return response()->json(['ok' => true]);
    }

    public function index()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = auth('pos')->user();

        // Waiters, admins and managers may open the tablet; cashiers have their own screen.
        if ($user->isPosCashier() || $user->isPosKitchen()) {
            return redirect('/pos/invoice/create');
        }
        if (!$this->restaurantOn($company)) {
            abort(403, 'Restaurant features are not enabled for this company.');
        }

        // Per-USER grid prefs (owner, 25 Jul 2026): ALL active products go to the
        // client (the old hard ->filter(show_on_sale) moved into the view's
        // isItemVisible) so a waiter can un-hide admin-hidden items on THEIR grid.
        // Pref-less output stays identical: default = show_on_sale.
        $products = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) ($p->price ?? 0),
                'category' => $p->category ?: 'General',
                'barcode' => $p->barcode ?: null,
                'show_on_sale' => (bool) ($p->show_on_sale ?? true),
                'is_tax_exempt' => (bool) ($p->is_tax_exempt ?? false),
                'is_third_schedule' => \Illuminate\Support\Facades\Schema::hasColumn('pos_products', 'is_third_schedule') ? (bool) ($p->is_third_schedule ?? false) : false,
            ])
            ->values();

        $userGridPrefs = \App\Models\PosUserItemPref::mapForUser($user->id);

        $cashiers = User::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereIn('pos_role', ['pos_admin', 'pos_manager', 'pos_cashier'])
                  ->orWhere('role', 'company_admin');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'pos_role']);

        // Item #5 (owner, Jul 2026): running total shows an "≈ incl. tax" ESTIMATE.
        // Cash rate only — the waiter never knows the final payment method; the REAL
        // tax is computed by the cashier's settle path (storeInvoice), never here.
        $cashTaxRate = \App\Models\PosTaxRule::getRateForMethod('cash', $company);

        // ZFC issue #13 (28 Jul 2026): tax-INCLUSIVE company => menu price IS the
        // final price — waiter sees ONE "Total", no before-tax / incl-tax split.
        $taxInclusive = (bool) ($company->pos_tax_inclusive ?? false);

        // ZFC (29 Jul 2026): waiter tablets stay open for days and never see new
        // deploys. Page embeds this code-version; the app polls /waiter/api/version
        // and self-refreshes (or shows a banner if a cart is in progress).
        $appVersion = self::codeVersion();

        // Product search mode (owner, 4 Aug 2026): 'any_word' matches the start of
        // ANY word in the name right away; default 'prefix' = strict 24 Jul rule
        // (+ zero-result word rescue). Missing column reads null → 'prefix' (safe).
        $searchAnyWord = (($company->pos_product_search_mode ?? 'prefix') === 'any_word');

        // Table-required guard (owner, 9 Aug 2026): the send() client check only
        // fires when the company really manages tables — otherwise dine-in has no
        // tables to pick and the punch must stay possible.
        $tablesOn = (bool) (PosFeatureService::forCompany($company)->tables ?? false);

        // Task 527 (owner, 12 Aug 2026): admin-controlled waiter permissions.
        // Cancel = default OFF, takeaway punch = default ON. The toggles
        // restrict WAITERS only — an admin/manager opening the tablet keeps
        // both abilities (they ARE the control authority).
        $isWaiter = $user->isPosWaiter();
        $waiterCanCancel = !$isWaiter || (bool) ($company->pos_waiter_cancel_enabled ?? false);
        $waiterCanTakeaway = !$isWaiter || (bool) ($company->pos_waiter_takeaway_enabled ?? true);

        return view('pos.waiter', compact('company', 'products', 'cashiers', 'cashTaxRate', 'userGridPrefs', 'taxInclusive', 'appVersion', 'searchAnyWord', 'tablesOn', 'waiterCanCancel', 'waiterCanTakeaway'));
    }

    /** Live floors + tables — waiter-scoped twin of the sale screen's table-status API. */
    /**
     * Code-version fingerprint for the waiter app (ZFC, 29 Jul 2026): waiter
     * phones keep the tab open for days and never pick up new deploys. Cheap:
     * mtime+size of the waiter blade (changes on every deploy that touches it).
     */
    public static function codeVersion(): string
    {
        $f = resource_path('views/pos/waiter.blade.php');
        return is_file($f) ? md5(filemtime($f) . ':' . filesize($f)) : 'unknown';
    }

    /** Polled by the open waiter app to detect a new deploy. */
    public function version()
    {
        return response()->json(['v' => self::codeVersion()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function tables(?Request $request = null)
    {
        $companyId = app('currentCompanyId');
        RestaurantTable::releaseStaleReservations($companyId);

        // Fast-path for the waiter tablet's frequent floor poll. The fingerprint
        // covers every value exposed below (including preview items), so a 304
        // never leaves a visible table card stale.
        $etag = $this->tablesEtag($companyId);
        if ($request?->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        // Two separate eager-loads:
        //   activeOrders  — all non-completed/cancelled (for active_orders count +
        //                   order_id/order_number used by shift/append logic).
        //   heldOrders.items — held-only with full item rows (for the read-only
        //                   items view on the waiter table-picker; prod-lazy-loading-
        //                   safe: no relation is accessed without an eager load).
        $tables = RestaurantTable::where('company_id', $companyId)
            ->where('is_active', true)
            // 'items' eager-loaded on BOTH relations for the read-only table
            // previews (ZFC, 6 Aug 2026) — production has lazy-loading disabled,
            // never lean on lazy: orders_preview reads activeOrders->items,
            // held_orders reads heldOrders->items.
            ->with(['floor', 'activeOrders.items', 'heldOrders.items'])
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'table_number' => $t->table_number,
                'floor' => $t->floor->name,
                'seats' => $t->seats,
                'status' => $t->status,
                // active_orders still counts ALL non-completed/cancelled orders
                // (held + preparing + ready) — shiftFreeTables() on the waiter
                // tablet uses this to gate which tables are shift targets.
                'active_orders' => $t->activeOrders->count(),
                // Occupied timer on the waiter table-picker (owner, 7 Aug 2026):
                // desktop picker shows "Occupied • 22h 42m" — waiter/mobile needs
                // the same so staff know how long a table/order has been running.
                'occupied_since' => $t->occupied_since,
                // Table Shift from picker (Aug 2026): the shiftable order = a HELD
                // one (shiftTable rejects preparing/ready). No held order → tile
                // stays un-tappable instead of advertising a shift that would fail.
                'order_id' => optional($t->heldOrders->first())->id,
                'order_number' => optional($t->heldOrders->first())->order_number,
                // Multi-order shift/append + read-only items view (ZFC task, Aug 2026):
                // saare HELD orders ki list with full item rows for the waiter
                // table-picker read-only display.
                'held_orders' => $t->heldOrders->values()->map(fn($o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'items_count' => $o->items->count(),
                    'total_amount' => (float) $o->total_amount,
                    'items' => $o->items->map(fn($i) => [
                        'name' => $i->item_name,
                        'quantity' => (float) $i->quantity,
                    ])->values(),
                ])->all(),
                // Read-only table preview (ZFC, 6 Aug 2026): jab order counter/
                // desktop se punch ho to waiter ko sirf OCCUPIED dikhta tha —
                // ab occupied tile par tap se table ke SAARE active orders ke
                // items dikhte hain (kisi ne bhi lagaye hon), sirf dekhne ke liye.
                'orders_preview' => $t->activeOrders->values()->map(fn($o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'status' => $o->status,
                    'total_amount' => (float) ($o->total_amount ?? 0),
                    'items' => $o->items->map(fn($i) => [
                        'name' => $i->item_name,
                        'quantity' => (float) $i->quantity,
                    ])->all(),
                ])->all(),
            ]);

        return response()->json($tables)->header('ETag', $etag);
    }

    /**
     * Lightweight fingerprint for the waiter table-picker/status feed.
     *
     * The full response eagerly loads orders and items. On an unchanged poll,
     * these scalar queries avoid that work while still including every field the
     * waiter can see: table/floor details, active/held order data, and item
     * preview/count data.
     */
    private function tablesEtag(int $companyId): string
    {
        $tableRows = DB::table('restaurant_tables as tables')
            ->leftJoin('restaurant_floors as floors', 'floors.id', '=', 'tables.floor_id')
            ->where('tables.company_id', $companyId)
            ->where('tables.is_active', true)
            ->orderBy('tables.id')
            ->get([
                'tables.id', 'tables.table_number', 'tables.seats', 'tables.status',
                'tables.occupied_since', 'tables.updated_at',
                'floors.name as floor_name', 'floors.updated_at as floor_updated_at',
            ]);

        $orderRows = DB::table('restaurant_orders')
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('table_id')
            ->orderBy('id')
            ->get(['id', 'table_id', 'order_number', 'status', 'total_amount', 'updated_at']);

        $itemRows = DB::table('restaurant_order_items')
            ->whereIn('order_id', function ($query) use ($companyId) {
                $query->select('id')
                    ->from('restaurant_orders')
                    ->where('company_id', $companyId)
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->whereNotNull('table_id');
            })
            ->orderBy('id')
            ->get(['id', 'order_id', 'item_name', 'quantity', 'updated_at']);

        $payload =
            $tableRows->map(fn ($row) => implode(':', [
                $row->id, $row->table_number, $row->seats, $row->status,
                $row->occupied_since, $row->updated_at, $row->floor_name, $row->floor_updated_at,
            ]))->join(',')
            . '|'
            . $orderRows->map(fn ($row) => implode(':', [
                $row->id, $row->table_id, $row->order_number, $row->status,
                $row->total_amount, $row->updated_at,
            ]))->join(',')
            . '|'
            . $itemRows->map(fn ($row) => implode(':', [
                $row->id, $row->order_id, $row->item_name, $row->quantity, $row->updated_at,
            ]))->join(',');

        return '"waiter-tables-' . $companyId . '-' . md5($payload) . '"';
    }

    /** Waiter's own open (still-held) sent orders — for append + status view. */
    public function myOrders()
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        $orders = RestaurantOrder::where('company_id', $companyId)
            ->where('source', 'waiter')
            ->where('status', 'held')
            ->where('created_by', $user->id)
            // 'creator' MUST be eager-loaded: orderJson() reads $o->creator?->name
            // and production has lazy-loading disabled (62 live 500s, Jul-Aug 2026).
            ->with(['items', 'table', 'creator', 'assignedCashier'])
            ->orderByDesc('id')
            ->get()
            ->map(fn($o) => $this->orderJson($o));

        return response()->json($orders);
    }

    public function storeOrder(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = auth('pos')->user();

        if ($user->isPosCashier() || $user->isPosKitchen()) {
            return response()->json(['success' => false, 'message' => 'Not allowed.'], 403);
        }
        if (!$this->restaurantOn($company)) {
            return response()->json(['success' => false, 'message' => 'Restaurant features are not enabled.'], 403);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01|max:9999',
            'items.*.unit_price' => 'required|numeric|min:0|max:99999999',
            'items.*.item_id' => 'nullable|integer',
            'items.*.special_notes' => 'nullable|string|max:500',
            'cashier_id' => 'nullable|integer',
            'table_id' => 'nullable|integer',
            'order_type' => 'nullable|in:dine_in,takeaway,delivery',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:30',
            'kitchen_notes' => 'nullable|string|max:500',
            // Urgent/Rush (owner, 7 Aug 2026) — same flag the cashier sale
            // screen sets; KDS badge + KOT *** URGENT *** read order->priority.
            'priority' => 'nullable|boolean',
        ]);

        // Task 632 (ZFC "NOTE: waiter", 13 Aug 2026): browser autofill drops the
        // waiter's OWN login identity into note boxes — discard exact matches on
        // EVERY waiter note-persisting path (storeOrder + appendItems).
        $validated = $this->stripIdentityNotes($validated, $user);

        // Cashier pick is OPTIONAL (customer feedback, 23 Jul 2026). No pick =
        // unassigned order → EVERY cashier's incoming list shows it (incomingOrders
        // already treats NULL assigned_cashier_id as "for anyone"). When a cashier
        // IS chosen, they must be a real, active billing account of THIS company.
        $cashier = null;
        if (!empty($validated['cashier_id'])) {
            $cashier = User::where('company_id', $companyId)
                ->where('id', $validated['cashier_id'])
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereIn('pos_role', ['pos_admin', 'pos_manager', 'pos_cashier'])
                      ->orWhere('role', 'company_admin');
                })
                ->first();
            if (!$cashier) {
                return response()->json(['success' => false, 'message' => 'Selected cashier not found.'], 422);
            }
        }

        $orderType = $validated['order_type'] ?? 'dine_in';
        $tableId = $orderType === 'dine_in' ? ($validated['table_id'] ?? null) : null;

        // Task 527 (owner, 12 Aug 2026): takeaway punch is an admin-controlled
        // permission (default ON — missing column fails OPEN so existing
        // companies keep current behavior). Waiters only; admins/managers on
        // the tablet are never blocked. Client hides the Takeaway button — this
        // is the security boundary.
        if ($orderType === 'takeaway' && $user->isPosWaiter()
            && !(bool) ($company->pos_waiter_takeaway_enabled ?? true)) {
            return response()->json(['success' => false, 'message' => __('pos.waiter_takeaway_not_allowed')], 403);
        }

        // Task 534 (owner, 12 Aug 2026): delivery orders are ALWAYS blocked for
        // waiters — the waiter UI never shows this option (security boundary here).
        // Admins/managers opening the tablet are exempt (same pattern as takeaway).
        if ($orderType === 'delivery' && $user->isPosWaiter()) {
            return response()->json(['success' => false, 'message' => __('pos.waiter_delivery_not_allowed')], 403);
        }

        // Table-required invariant (owner voice note, 9 Aug 2026): a live shop's
        // waiter punched a dine-in order WITHOUT selecting a table and the KOT
        // still printed. When the company actually manages tables (tables feature
        // ON), a dine-in punch without a table is always a mistake — block it
        // here (the client-side guard is UX only, never a security boundary).
        $waiterFeatures = PosFeatureService::forCompany($company);
        if ($orderType === 'dine_in' && !$tableId && ($waiterFeatures->tables ?? false)) {
            return response()->json(['success' => false, 'message' => __('pos.dine_in_table_required')], 422);
        }

        $storeOrderResponse = DB::transaction(function () use ($companyId, $validated, $cashier, $orderType, $tableId, $user, $company) {
            if ($tableId) {
                $table = RestaurantTable::where('company_id', $companyId)
                    ->where('id', $tableId)->where('is_active', true)
                    ->lockForUpdate()->first();
                if (!$table) {
                    return response()->json(['success' => false, 'message' => 'Table not found.'], 422);
                }
                if ($table->status === 'occupied') {
                    return response()->json(['success' => false, 'message' => 'Table T-' . $table->table_number . ' is occupied.'], 409);
                }
                $table->update(['status' => 'occupied', 'occupied_since' => $table->occupied_since ?: now()]);
            }

            $subtotal = 0;
            foreach ($validated['items'] as $it) {
                $subtotal += round((float) $it['quantity'] * (float) $it['unit_price'], 2);
            }
            $subtotal = round($subtotal, 2);
            // Indicative totals only — the REAL bill (tax, discount, PRA) is
            // computed by storeInvoice when the cashier takes payment.
            // Whole-rupee total per POS rounding convention.
            $total = round($subtotal);

            // Order Matching (Aug 2026): waiter-created orders join the SAME
            // company-central daily token series as counter orders.
            $waiterTokenNo = ($company->order_match_style ?? 'off') === 'token'
                ? \App\Services\OrderTokenService::nextToken($companyId)
                : null;

            $order = RestaurantOrder::create([
                'company_id' => $companyId,
                'order_number' => 'ORD-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)),
                'token_no' => $waiterTokenNo,
                'table_id' => $tableId,
                'order_type' => $orderType,
                'status' => 'held',
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => 0,
                'total_amount' => $total,
                'kitchen_notes' => $validated['kitchen_notes'] ?? null,
                'priority' => (bool) ($validated['priority'] ?? false),
                'created_by' => $user->id,
                'assigned_cashier_id' => $cashier?->id,
                'source' => 'waiter',
                'kot_sent_at' => now(),
                'kot_print_count' => 0,
            ]);

            foreach ($validated['items'] as $it) {
                RestaurantOrderItem::create([
                    'order_id' => $order->id,
                    'item_type' => !empty($it['item_id']) ? 'product' : 'manual',
                    'item_id' => $it['item_id'] ?? null,
                    'item_name' => $it['name'],
                    'quantity' => (float) $it['quantity'],
                    'unit_price' => round((float) $it['unit_price'], 2),
                    'subtotal' => round((float) $it['quantity'] * (float) $it['unit_price'], 2),
                    'special_notes' => $it['special_notes'] ?? null,
                    // kot_printed_at stays NULL — stamped when a ticket actually prints.
                ]);
            }

            // ZFC issue #10 (28 Jul 2026): waiter punch must ACTUALLY print the
            // kitchen ticket — before this, only kot_sent_at was stamped and no
            // print job existed, so the kitchen never got the KOT. Best-effort:
            // a printer problem must never lose the order.
            $company = Company::find($companyId);
            $kot = \App\Services\KotPrintService::enqueueForOrder($company, $order, $user->id);
            if ($kot['printed'] && !empty($kot['job_ids'])) {
                $order->update(['kot_print_count' => 1]);
            }

            $msg = $cashier ? 'Order sent to ' . $cashier->name . '.' : 'Order sent to counter.';
            if (!$kot['printed']) {
                $msg .= ' KOT print nahi hui (' . ($kot['reason'] ?? 'error') . ') — cashier screen se print karein.';
            }

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'kot_printed' => (bool) $kot['printed'],
                'message' => $msg,
            ]);
        });

        // Instant cashier phone push (Task #1142) — queued only AFTER the
        // transaction committed (success responses carry order_id; the 4xx
        // early-returns inside the closure never do). Fire-and-forget:
        // a push problem can never fail the punch.
        try {
            $storeOrderJson = json_decode($storeOrderResponse->getContent(), true);
            if (!empty($storeOrderJson['success']) && !empty($storeOrderJson['order_id'])) {
                \App\Services\PosPushService::queueWaiterOrderPush((int) $storeOrderJson['order_id']);
            }
        } catch (\Throwable $e) {
            // push is additive — the order is already saved
        }

        return $storeOrderResponse;
    }

    /** Append items to an already-sent held order — the delta prints alone (kot_printed_at NULL rows). */
    /**
     * Task 632 (ZFC "NOTE: waiter", 13 Aug 2026): live data proved mobile browser
     * autofill drops the punching user's OWN login identity (name/username/email/
     * email-prefix/phone) into note boxes — the KOT then prints a confusing
     * "NOTE: waiter". A note that is EXACTLY such an identity string is never a
     * real kitchen instruction — discard it (log user id only, never the raw
     * value: it can be an email/phone). Notes that merely CONTAIN the word stay.
     * Must be applied on EVERY path that persists waiter item/kitchen notes.
     */
    public static function stripIdentityNotes(array $validated, $user): array
    {
        foreach (($validated['items'] ?? []) as $k => $it) {
            $validated['items'][$k]['special_notes'] = self::stripIdentityNote($it['special_notes'] ?? null, $user);
        }
        if (array_key_exists('kitchen_notes', $validated)) {
            $validated['kitchen_notes'] = self::stripIdentityNote($validated['kitchen_notes'], $user);
        }
        return $validated;
    }

    /**
     * Task 636: single-note variant of the identity-autofill discard, shared by
     * the CASHIER sale-screen paths (RestaurantPosController::holdOrder,
     * PosController::resolveItemExemptions) as well as the waiter paths above.
     * Returns the note unchanged unless it is EXACTLY a login identity string.
     */
    public static function stripIdentityNote(?string $note, $user): ?string
    {
        if ($note === null || trim($note) === '' || !$user) {
            return $note;
        }
        $identity = array_filter(array_unique(array_map(
            fn ($v) => mb_strtolower(trim((string) $v)),
            [
                $user->name ?? null,
                $user->username ?? null,
                $user->email ?? null,
                strstr((string) ($user->email ?? ''), '@', true) ?: null, // email prefix
                $user->phone ?? null,
            ]
        )));
        $clean = mb_strtolower(trim($note));
        if ($clean !== '' && in_array($clean, $identity, true)) {
            \Log::warning('Item note discarded: exact match with login identity (autofill)', [
                'user_id' => $user->id ?? null,
            ]);
            return null;
        }
        return $note;
    }

    public function appendItems(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01|max:9999',
            'items.*.unit_price' => 'required|numeric|min:0|max:99999999',
            'items.*.item_id' => 'nullable|integer',
            'items.*.special_notes' => 'nullable|string|max:500',
        ]);

        // Task 632: same identity-autofill note discard as storeOrder — this
        // path also persists + immediately prints special_notes on a KOT.
        $validated = self::stripIdentityNotes($validated, $user);

        return DB::transaction(function () use ($companyId, $validated, $user, $id) {
            // ZFC (1 Aug 2026): waiter DESKTOP (cashier) ke lagaye held orders mein
            // bhi items add kar sakta hai — source/creator restriction hata di
            // (table-shift jaisi company-wide authority, owner-approved).
            $order = RestaurantOrder::where('company_id', $companyId)
                ->where('id', $id)
                ->where('status', 'held')
                ->lockForUpdate()
                ->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found or already settled.'], 404);
            }

            // Task 626 (owner, 13 Aug 2026): takeaway toggle OFF = waiter ka
            // append rasta TAKEAWAY orders par bhi band (Task 527 ka append-allow
            // khatam). Waiters only — admin/manager tablet par exempt. Purane
            // orders ka settle path cashier side untouched. Missing column
            // fails OPEN (default ON), same as storeOrder.
            if ($order->order_type === 'takeaway' && $user->isPosWaiter()) {
                $gateCompany = Company::find($companyId);
                if (!(bool) ($gateCompany->pos_waiter_takeaway_enabled ?? true)) {
                    return response()->json(['success' => false, 'message' => __('pos.waiter_takeaway_not_allowed')], 403);
                }
            }

            $added = 0;
            foreach ($validated['items'] as $it) {
                RestaurantOrderItem::create([
                    'order_id' => $order->id,
                    'item_type' => !empty($it['item_id']) ? 'product' : 'manual',
                    'item_id' => $it['item_id'] ?? null,
                    'item_name' => $it['name'],
                    'quantity' => (float) $it['quantity'],
                    'unit_price' => round((float) $it['unit_price'], 2),
                    'subtotal' => round((float) $it['quantity'] * (float) $it['unit_price'], 2),
                    'special_notes' => $it['special_notes'] ?? null,
                ]);
                $added += round((float) $it['quantity'] * (float) $it['unit_price'], 2);
            }

            $newSubtotal = round((float) $order->subtotal + $added, 2);
            $order->update([
                'subtotal' => $newSubtotal,
                'total_amount' => round($newSubtotal),
                'kot_sent_at' => now(),
            ]);

            // ZFC issue #10: print the DELTA ticket (only unprinted rows) right away.
            $company = Company::find($companyId);
            $kot = \App\Services\KotPrintService::enqueueForOrder($company, $order, $user->id, true);
            if ($kot['printed'] && !empty($kot['job_ids'])) {
                // Query-builder update (NOT $order->update): the model's
                // 'integer' cast chokes on DB::raw Expression —
                // "Object of class ...Expression could not be converted to int"
                // (live bug, waiter Add Items, 1 Aug 2026).
                RestaurantOrder::whereKey($order->id)
                    ->update(['kot_print_count' => DB::raw('COALESCE(kot_print_count, 0) + 1'), 'updated_at' => now()]);
            }

            $msg = 'Items added — kitchen gets a delta ticket.';
            if (!$kot['printed']) {
                $msg = 'Items added. KOT print nahi hui (' . ($kot['reason'] ?? 'error') . ') — cashier screen se print karein.';
            }

            return response()->json(['success' => true, 'kot_printed' => (bool) $kot['printed'], 'message' => $msg]);
        });
    }

    /**
     * Table Shift (owner batch, 26 Jul 2026): waiter apna HELD order kisi
     * KHALI table par shift kare. Ownership yahan verify hoti hai, phir
     * poori race-safe logic RestaurantPosController::shiftTable ki hai
     * (lockForUpdate, timer carry, KOT reprint NAHI) — ek hi source of truth.
     */
    public function shiftTable(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        // ZFC voice note (1 Aug 2026): waiter ko HAR occupied table shift karne
        // ka ikhtiyar chahiye — cashier (desktop) ke lagaye orders bhi. Ownership
        // restriction hata di: ab company ka koi bhi ACTIVE order shift ho sakta
        // hai (source/creator koi bhi ho). Race-safety wahi ek source of truth.
        $exists = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->where('id', $id)
            ->exists();
        if (!$exists) {
            return response()->json(['success' => false, 'message' => 'Order nahi mila'], 404);
        }

        return app(RestaurantPosController::class)->shiftTable($request, $id);
    }

    /**
     * Waiter self-cancel (Task 412, Aug 2026): waiter apna GHALAT punch hua order
     * cashier ke claim/settle se PEHLE khud cancel kar sake. Soft-cancel semantics
     * = cashier-side deleteOrder jaisi (status='cancelled', cancelled_at/by) taake
     * order Cancelled Orders report mein waiter ke naam ke saath aaye.
     *
     * Race-safety: single conditional UPDATE (status='held' + created_by=self) —
     * completeIncoming ka settle-claim bhi status='held' par conditional hai, is
     * liye cancel vs settle ka sirf EK winner ho sakta hai; settle ho chuka order
     * yahan 409 deta hai.
     */
    public function cancelOrder(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if (!is_numeric($id) || $id < 1) {
            return response()->json(['success' => false, 'message' => 'Invalid order ID'], 400);
        }

        // Company is needed for both the waiter-cancel gate AND the void enqueue
        // below — load once here so the permission check and the KOT service share
        // the same object.
        $company = Company::find($companyId);

        // Task 527 (owner, 12 Aug 2026): waiter self-cancel is now an
        // admin-controlled permission, DEFAULT OFF (missing column reads null
        // → blocked, which IS the desired default). Waiters only —
        // admins/managers using the tablet keep cancel.
        if ($user->isPosWaiter()) {
            if (!(bool) ($company->pos_waiter_cancel_enabled ?? false)) {
                return response()->json(['success' => false, 'message' => __('pos.waiter_cancel_not_allowed')], 403);
            }
        }

        // Task 850 — VOID SLIP pre-collection: gather every printed item BEFORE
        // the cancel so we can tell the kitchen to stop cooking. Only items with
        // a kot_printed_at stamp count — a fresh hold cancelled before any KOT
        // printed stays silent (nothing sent = nothing to void). This read happens
        // before the atomic UPDATE; if the UPDATE later loses the race (409), the
        // collected items are simply discarded.
        $orderForVoid = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $id)
            ->with('items')
            ->first();
        $voidItems = [];
        if ($orderForVoid) {
            foreach ($orderForVoid->items->whereNotNull('kot_printed_at') as $oi) {
                $voidItems[] = [
                    'item_type' => $oi->item_type ?? 'product',
                    'item_id'   => $oi->item_id,
                    'item_name' => $oi->item_name ?? '',
                    'notes'     => $oi->special_notes ?? '',
                    'qty'       => (float) $oi->quantity,
                ];
            }
        }

        $updates = ['status' => 'cancelled', 'updated_at' => now()];
        if (\Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'cancelled_at')) {
            $updates['cancelled_at'] = now();
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'cancelled_by')) {
            $updates['cancelled_by'] = $user->id;
        }

        // Atomic single-winner: only the waiter's OWN, still-held, UN-CLAIMED
        // waiter order flips. assigned_cashier_id NULL = koi cashier ne order
        // pakra nahi (claimIncoming/storeOrder dono isi column par chalte hain) —
        // claim ke baad cancel sirf cashier side se hota hai, warna cashier ke
        // load hue cart ke neeche se order gayab ho sakta tha.
        $cancelled = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $id)
            ->where('source', 'waiter')
            ->where('status', 'held')
            ->whereNull('assigned_cashier_id')
            ->where('created_by', $user->id)
            ->update($updates);

        if (!$cancelled) {
            return response()->json([
                'success' => false,
                'message' => 'Order cancel nahi ho saka — cashier ne order le liya hai (ya settle kar diya). Cancel ab counter se hi ho sakta hai.',
            ], 409);
        }

        $order = RestaurantOrder::where('company_id', $companyId)->find($id);

        // Free the table if no other live order still sits on it (deleteOrder / P4 pattern).
        if ($order && $order->table_id) {
            $stillActive = RestaurantOrder::where('company_id', $companyId)
                ->where('table_id', $order->table_id)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->exists();
            if (!$stillActive) {
                RestaurantTable::where('company_id', $companyId)->where('id', $order->table_id)
                    ->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => null]);
            }
        }

        try {
            if ($order && class_exists(\App\Services\AuditLogService::class)) {
                \App\Services\AuditLogService::log('order_deleted', 'restaurant_order', $order->id, [
                    'order_number' => $order->order_number,
                    'total_amount' => $order->total_amount,
                    'status' => 'held',
                    'cancelled_from' => 'waiter_tablet',
                ], null, $companyId, $user->id);
            }
        } catch (\Exception $auditEx) {
        }

        // Task 850/851 — post-cancel void enqueue: tell the kitchen to stop cooking
        // the cancelled dishes. Mirrors deleteOrder's best-effort pattern: a
        // failed enqueue never rolls back the cancel. Desktop Agent silent path
        // first; client falls back to the iframe void-ticket route when queued=false.
        $kotVoidQueued = false;
        $kotVoidUrl    = null;
        if (!empty($voidItems) && $company && $order) {
            try {
                $enqVoid = \App\Services\KotPrintService::enqueueVoid($company, $order, $voidItems, $user->id);
                $kotVoidQueued = (bool) ($enqVoid['printed'] ?? false) && !empty($enqVoid['job_ids'] ?? []);
            } catch (\Throwable $voidEx) {
                \Illuminate\Support\Facades\Log::warning('cancelOrder (waiter) void KOT enqueue failed: ' . $voidEx->getMessage(), ['order_id' => $id]);
            }
            // Iframe fallback — waiter-accessible route under pos/waiter/ so
            // PosAuth's waiter allowlist covers it (pos/restaurant/orders/.../void-ticket
            // is blocked for pos_waiter). Relative URL to avoid route-absolute-https
            // trap (see route-absolute-https-fetch.md).
            $kotVoidUrl = route('pos.waiter.orders.void-ticket', $id, false)
                . '?void_items=' . urlencode(base64_encode(json_encode($voidItems)));
        }

        return response()->json([
            'success'         => true,
            'message'         => 'Order cancelled',
            'kot_void_queued' => $kotVoidQueued,
            'kot_void_url'    => $kotVoidUrl,
        ]);
    }

    /**
     * Task 851 — GET /pos/waiter/orders/{id}/void-ticket
     *
     * Waiter-accessible iframe fallback for the void slip. Lives under pos/waiter/
     * so PosAuth's waiter allowlist already covers it — no middleware changes needed.
     * Mirrors RestaurantPosController::voidTicket but is reachable by pos_waiter
     * sessions (the cashier route pos/restaurant/orders/{id}/void-ticket is blocked
     * by PosAuth for pos_waiter).
     *
     * Security:
     * - Company-scoped: a waiter on company A cannot reach company B's orders.
     * - Ownership-scoped for pos_waiter: the order must be source='waiter',
     *   status='cancelled', and created_by the requesting waiter. Admins and
     *   managers see any cancelled order in the company (they can supervise).
     * - void_items are RECONSTRUCTED from the server-side kot_printed_at items —
     *   the query-string payload is ignored to prevent a forged void slip.
     */
    public function waiterVoidTicket(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if (!is_numeric($id) || $id < 1) {
            abort(404);
        }

        // Role gate: only the owning pos_waiter OR an admin/manager may view this
        // endpoint. pos_cashier and other confined roles are excluded — they have no
        // business triggering a void slip for a cancelled waiter order.
        $isAdmin = $user && $user->isPosAdmin(); // isPosAdmin() covers pos_admin + company_admin + pos_manager
        $isWaiter = $user && $user->isPosWaiter();
        if (!$isAdmin && !$isWaiter) {
            abort(403, 'Access denied');
        }

        // Base: company-scoped, cancelled waiter order.
        $q = \App\Models\RestaurantOrder::where('company_id', $companyId)
            ->where('source', 'waiter')
            ->where('status', 'cancelled')
            ->with(['table', 'creator', 'items']);

        // pos_waiter confined to their OWN cancelled orders (same-company IDOR guard).
        // Admin / manager roles supervise any company order.
        if ($isWaiter) {
            $q->where('created_by', $user->id);
        }

        $order = $q->findOrFail($id);

        $company = Company::find($companyId);

        // Reconstruct void items from KOT-printed items on the server — never
        // trust the client-supplied query-string payload (forged-void-slip guard).
        $voidItems = $order->items
            ->filter(fn($oi) => !is_null($oi->kot_printed_at))
            ->map(fn($oi) => [
                'item_type' => $oi->item_type ?? 'product',
                'item_id'   => $oi->item_id,
                'item_name' => $oi->item_name ?? '',
                'notes'     => $oi->special_notes ?? '',
                'qty'       => (float) $oi->quantity,
            ])
            ->values();

        return view('pos.restaurant.kitchen-ticket', [
            'order'        => $order,
            'company'      => $company,
            'void'         => true,
            'voidItems'    => $voidItems,
            'ticketItems'  => collect(),
            'grouped'      => collect(),
            'stationLabel' => null,
            'delta'        => false,
            'kotBatchNo'   => null,
            'newItemIds'   => collect(),
        ]);
    }

    /** Cashier side — waiter orders waiting for payment (mine or unassigned; admins see all). */
    public function incomingOrders(?Request $request = null)
    {
        $request   = $request ?? request();
        $companyId = app('currentCompanyId');
        $user      = auth('pos')->user();

        // ── Fast-path: If-None-Match ETag (Task 1097) ────────────────────────
        // Composite fingerprint across orders + items — same reasoning as
        // heldOrdersEtag() in RestaurantPosController:
        //   • Same-second new order  → different MAX(id) on orders.
        //   • Order removed          → different COUNT.
        //   • KOT stamp (raw update) → different MAX(kot_printed_at) on items.
        //   • New item same second   → different MAX(id) on items.
        $isCashier = $user->isPosCashier();
        $etag = $this->incomingOrdersEtag($companyId, $user->id, $isCashier);

        // KDS liveness flag — always re-read even on 304 so the header stays fresh.
        $kdsSeen  = (int) \Illuminate\Support\Facades\Cache::get('kds_seen_' . $companyId, 0);
        $kdsAlive = (time() - $kdsSeen) < 90;

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('X-KDS-Alive', $kdsAlive ? '1' : '0');
        }

        $q = RestaurantOrder::where('company_id', $companyId)
            ->where('source', 'waiter')
            ->with(['items', 'table', 'creator', 'assignedCashier']);
        self::whereOpenWaiterOrder($q);

        // Cashiers see orders sent to THEM (or unassigned); admins/managers see all.
        if ($user->isPosCashier()) {
            $q->where(function ($w) use ($user) {
                $w->where('assigned_cashier_id', $user->id)->orWhereNull('assigned_cashier_id');
            });
        }

        // Newest FIRST (ZFC voice note, 1 Aug 2026): "jo bhi order waiter lagayega
        // woh OOPAR show hoga" — the cashier wants the latest incoming order at
        // the top of the Incoming/Counter lists, not buried under older ones.
        $orders = $q->orderByDesc('id')->get()->map(fn($o) => $this->orderJson($o));

        // KDS liveness flag (Jul 2026): sale screens poll this endpoint every 20s.
        // Header (not body) so the response stays a plain array — existing clients
        // keep working. "Alive" = KDS board polled within the last 90s (its own
        // poll is every 15s). Drives the KDS-auto-print fallback: KDS closed →
        // cashier-side auto-KOT resumes instead of tickets silently vanishing.
        return response()->json($orders)
            ->header('ETag', $etag)
            ->header('X-KDS-Alive', $kdsAlive ? '1' : '0');
    }

    /**
     * Atomic claim for the sale screen's AUTO-LOAD (waiter easy-pickup, Jul 2026).
     * Two idle terminals polling the same unassigned order must never BOTH load
     * it (payment runs before settlement's atomic claim → duplicate final bill).
     * Conditional UPDATE = single-winner: sets assigned_cashier_id to the caller
     * only while the order is still held and unassigned (or already theirs).
     */
    public function claimIncoming(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        $claimQuery = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $id)
            ->where('source', 'waiter');
        self::whereOpenWaiterOrder($claimQuery);
        // Admin/manager override (Jul 2026): admins see ALL held waiter orders in
        // the table picker — without an override, an order assigned to an
        // off-shift cashier stays stuck for everyone else. Admin claim simply
        // re-assigns it (single-winner UPDATE still holds per request).
        if (!$user->isPosAdmin()) {
            $claimQuery->where(function ($w) use ($user) {
                $w->whereNull('assigned_cashier_id')->orWhere('assigned_cashier_id', $user->id);
            });
        }
        // Task 1097: bump updated_at so the incoming-orders ETag digest (which
        // hashes updated_at per row) detects the assignment change on every poll.
        $claimed = $claimQuery->update(['assigned_cashier_id' => $user->id, 'updated_at' => now()]);

        if (!$claimed) {
            // MySQL reports 0 affected rows when the value is unchanged (order
            // already assigned to this same cashier) — re-check before failing.
            $mineQ = RestaurantOrder::where('company_id', $companyId)
                ->where('id', $id)->where('source', 'waiter')
                ->where('assigned_cashier_id', $user->id);
            self::whereOpenWaiterOrder($mineQ);
            $mine = $mineQ->exists();
            if (!$mine) {
                return response()->json(['success' => false, 'message' => 'Order already taken by another cashier.'], 409);
            }
        }

        // Return the FRESH order snapshot (table-se-bill flow, Jul 2026): the
        // cashier's polled copy can be stale — a waiter may have appended items
        // between the poll and the claim. Cart must build from THIS, not the
        // stale client object. (Post-claim appends remain a known limitation —
        // same as the old drawer flow.)
        $order = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $id)
            ->with(['items', 'table', 'creator', 'assignedCashier'])
            ->first();

        return response()->json(['success' => true, 'order' => $order ? $this->orderJson($order) : null]);
    }

    /** Link the paid PosTransaction to the waiter order, mark completed, free the table. */
    public function completeIncoming(Request $request, $id)
    {
        $companyId = app('currentCompanyId');

        $validated = $request->validate([
            'transaction_id' => 'required|integer',
        ]);

        $txn = \App\Models\PosTransaction::where('company_id', $companyId)
            ->where('id', $validated['transaction_id'])
            ->first();
        if (!$txn) {
            return response()->json(['success' => false, 'message' => 'Transaction not found.'], 422);
        }

        if (!self::settleWaiterOrder($companyId, (int) $id, $txn, auth('pos')->user())) {
            return response()->json(['success' => false, 'message' => 'Order already settled.'], 409);
        }

        return response()->json(['success' => true, 'message' => 'Waiter order settled.']);
    }

    /**
     * Task 646: atomic waiter-order settle, shared by completeIncoming (client
     * fallback) AND PosController::storeInvoice. storeInvoice calls this BEFORE
     * returning the paid transaction so the receipt templates (auto-print
     * included) can already look the waiter up via pos_transaction_id — the
     * old client-only completeIncomingOrder fetch raced the print chain and
     * the first receipt could miss the "Waiter:" line.
     *
     * Authorization mirrors claimIncoming (architect review): a non-admin may
     * settle only an order that is UNASSIGNED or assigned to THEM; POS admins/
     * managers may settle any (same off-shift-cashier rescue policy). The
     * order id is client-supplied on both call paths, so this guard is the
     * security boundary — never relax it to company-scope alone.
     */
    public static function settleWaiterOrder(int $companyId, int $orderId, \App\Models\PosTransaction $txn, ?\App\Models\User $user): bool
    {
        if (!$user) {
            return false;
        }
        // Atomic claim — double-click / two cashiers can't settle the same order twice.
        $claimQuery = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $orderId)
            ->where('source', 'waiter');
        self::whereOpenWaiterOrder($claimQuery);
        if (!$user->isPosAdmin()) {
            $claimQuery->where(function ($w) use ($user) {
                $w->whereNull('assigned_cashier_id')->orWhere('assigned_cashier_id', $user->id);
            });
        }
        $claimed = $claimQuery
            ->update([
                'status' => 'completed',
                'pos_transaction_id' => $txn->id,
                'payment_method' => $txn->payment_method,
                'updated_at' => now(),
            ]);
        if (!$claimed) {
            return false;
        }

        $order = RestaurantOrder::where('company_id', $companyId)->with('table')->find($orderId);

        // Free the table if no other live order still sits on it (P4 pattern).
        // Task 880: wrapped in try/catch so a non-critical table-status update
        // never propagates into the parent DB transaction and rolls back the bill.
        if ($order && $order->table_id) {
            try {
                $stillActive = RestaurantOrder::where('company_id', $companyId)
                    ->where('table_id', $order->table_id)
                    ->where('id', '!=', $order->id)
                    ->whereIn('status', ['held', 'preparing', 'ready'])
                    ->exists();
                if (!$stillActive) {
                    RestaurantTable::where('id', $order->table_id)
                        ->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => null]);
                }
            } catch (\Throwable $e) {
                \Log::warning('settleWaiterOrder: table-free failed (non-fatal)', [
                    'order_id' => $orderId,
                    'table_id' => $order->table_id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /**
     * Task 644 review fix (Aug 2026): the incoming/claim/settle trio used a bare
     * status='held' filter, but the legacy KDS status route (updateStatus) can
     * move an order held→preparing→ready. A TABLELESS waiter ("counter") order
     * in those states was counted as pending on the dashboard yet invisible in
     * the bell panel and unclaimable/unsettleable — a true dead end, because the
     * bell panel is its ONLY surface (owner rule 5 Aug 2026: counter orders never
     * appear on the table board/picker). Open = held, OR tableless in
     * preparing/ready. Table-attached waiter orders keep the old held-only panel
     * behaviour: the Tables board already lists/settles them in every open status.
     * Keep this predicate the mirror of the dashboard's counterOrdersCount slice
     * (RestaurantPosController::dashboard) — every counted order must be
     * reachable and settleable through this trio.
     */
    private static function whereOpenWaiterOrder($q)
    {
        return $q->where(function ($w) {
            $w->where('status', 'held')
              ->orWhere(function ($x) {
                  $x->whereNull('table_id')->whereIn('status', ['preparing', 'ready']);
              });
        });
    }

    /**
     * Task 1097 — collision-resistant ETag for the incoming-orders poll.
     *
     * Uses the same per-row digest strategy as RestaurantPosController::heldOrdersEtag:
     * fetch minimal columns (id, updated_at, kot_printed_at) for orders AND items,
     * hash in PHP.  This correctly detects every mutation including:
     *   • Non-max item deleted / updated / KOT-stamped (all miss MAX-only approaches).
     *   • Two orders created in the same second (different ids → different hash).
     *   • Any order status change (status included in order rows).
     *
     * Items are scoped via a subquery over the already-filtered order set —
     * no PHP-side pluck/WHERE-IN, one query per table.
     *
     * $isCashier controls whether the scope is narrowed to this user's orders
     * (must mirror the main query filter exactly).
     */
    private function incomingOrdersEtag(int $companyId, int $userId, bool $isCashier): string
    {
        $scopeOrders = function ($q) use ($companyId, $userId, $isCashier) {
            $q->from('restaurant_orders')
              ->where('company_id', $companyId)
              ->where('source', 'waiter')
              ->where(function ($w) {
                  $w->where('status', 'held')
                    ->orWhere(function ($x) {
                        $x->whereNull('table_id')->whereIn('status', ['preparing', 'ready']);
                    });
              });
            if ($isCashier) {
                $q->where(function ($w) use ($userId) {
                    $w->where('assigned_cashier_id', $userId)->orWhereNull('assigned_cashier_id');
                });
            }
        };

        $orderRows = DB::table('restaurant_orders')
            ->where('company_id', $companyId)
            ->where('source', 'waiter')
            ->where(function ($w) {
                $w->where('status', 'held')
                  ->orWhere(function ($x) {
                      $x->whereNull('table_id')->whereIn('status', ['preparing', 'ready']);
                  });
            })
            ->when($isCashier, fn ($q) => $q->where(function ($w) use ($userId) {
                $w->where('assigned_cashier_id', $userId)->orWhereNull('assigned_cashier_id');
            }))
            ->orderBy('id')
            // Include assigned_cashier_id: claimIncoming() sets it via a raw
            // query-builder update (now also bumps updated_at, but including
            // the field directly is defense-in-depth for any future raw updates).
            ->get(['id', 'status', 'updated_at', 'assigned_cashier_id']);

        $itemRows = DB::table('restaurant_order_items')
            ->whereIn('order_id', function ($q) use ($scopeOrders) {
                $q->select('id');
                $scopeOrders($q);
            })
            ->orderBy('id')
            ->get(['id', 'updated_at', 'kot_printed_at']);

        $payload =
            $orderRows->map(fn($r) => $r->id . ':' . $r->status . ':' . ($r->updated_at ?? '') . ':' . ($r->assigned_cashier_id ?? ''))->join(',')
            . '|'
            . $itemRows->map(fn($r) => $r->id . ':' . ($r->updated_at ?? '') . ':' . ($r->kot_printed_at ?? ''))->join(',');

        return '"inc-' . $companyId . '-' . $userId . '-' . md5($payload) . '"';
    }

    private function orderJson(RestaurantOrder $o): array
    {
        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'order_type' => $o->order_type,
            'table_id' => $o->table_id !== null ? (int) $o->table_id : null,
            'table' => $o->table ? $o->table->table_number : null,
            'customer_name' => $o->customer_name,
            // Task 530: daily Order-Matching token — parcel append banner shows
            // customer name (if entered) warna yeh token, taake waiter pehchan sake.
            'token_no' => $o->token_no !== null ? (int) $o->token_no : null,
            'customer_phone' => $o->customer_phone,
            'kitchen_notes' => $o->kitchen_notes,
            'waiter' => $o->creator?->name ?? 'Unknown',
            'assigned_cashier' => $o->assignedCashier?->name,
            'assigned_cashier_id' => $o->assigned_cashier_id,
            'subtotal' => (float) $o->subtotal,
            'total_amount' => (float) $o->total_amount,
            'unprinted_count' => $o->items->whereNull('kot_printed_at')->count(),
            // Waiter self-cancel modal (Task 412): KOT-already-sent warning gate.
            'kot_sent_at' => $o->kot_sent_at,
            'items' => $o->items->map(fn($i) => [
                // Task #645: real row id + subtotal — cancel modal's Made/Not-Made
                // ticks post these ids as made_item_ids to deleteOrder.
                'id' => $i->id,
                'subtotal' => (float) $i->subtotal,
                'item_id' => $i->item_id,
                'item_type' => $i->item_type,
                'name' => $i->item_name,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'special_notes' => $i->special_notes,
                'is_tax_exempt' => (bool) $i->is_tax_exempt,
                'is_third_schedule' => \Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'is_third_schedule') ? (bool) ($i->is_third_schedule ?? false) : false,
                'printed' => $i->kot_printed_at !== null,
            ])->values(),
            'created_at' => $o->created_at->format('H:i'),
        ];
    }
}
