<?php

namespace App\Http\Controllers;

use App\Models\PosDeal;
use App\Models\PosProduct;
use App\Models\PosService;
use App\Models\PosUserItemPref;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user sale-screen grid visibility (owner, 25 Jul 2026).
 * Every POS user (cashier / waiter / manager / admin) decides which items
 * appear on THEIR OWN grid — user pref overrides the admin show_on_sale
 * default in both directions, for that user's screen only.
 * Routes are pos.auth ONLY (no company.approval — personal display pref,
 * same precedent as Madadgar). Search / billing / KOT are never affected.
 */
class PosGridPrefController extends Controller
{
    public function toggle(Request $request)
    {
        $data = $request->validate([
            'item_type' => 'required|in:product,service,deal',
            'item_id'   => 'required|integer|min:1',
            'visible'   => 'required|boolean',
        ]);

        $user = auth('pos')->user();
        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        if (!$user || !$companyId) {
            abort(403);
        }
        if (!Schema::hasTable('pos_user_item_prefs')) {
            // Prod drift safe: file deploy can land before the live migration.
            return response()->json(['ok' => false, 'error' => 'not_ready'], 503);
        }

        // The toggled item must belong to the user's own company.
        $model = match ($data['item_type']) {
            'product' => PosProduct::class,
            'service' => PosService::class,
            'deal'    => PosDeal::class,
        };
        if (!$model::where('company_id', $companyId)->where('id', $data['item_id'])->exists()) {
            abort(404);
        }

        PosUserItemPref::updateOrCreate(
            ['user_id' => $user->id, 'item_type' => $data['item_type'], 'item_id' => (int) $data['item_id']],
            ['visible' => (bool) $data['visible']]
        );

        return response()->json(['ok' => true]);
    }

    public function reset()
    {
        $user = auth('pos')->user();
        if (!$user) {
            abort(403);
        }
        if (Schema::hasTable('pos_user_item_prefs')) {
            PosUserItemPref::where('user_id', $user->id)->delete();
        }

        return response()->json(['ok' => true]);
    }
}
