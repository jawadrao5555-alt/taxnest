<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PosUserItemPref;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1271 — per-user FBR sale-screen grid visibility (FBR twin of
 * PosGridPrefController). Every FBR POS user decides which products appear
 * on THEIR OWN grid — user pref overrides the admin show_on_sale default in
 * both directions, for that user's screen only. Search / billing / FBR
 * submission are never affected.
 *
 * Storage: shared pos_user_item_prefs table with item_type='fbrproduct'
 * (FBR grid items are Product rows, NOT PosProduct — the id spaces must
 * stay separate; column is varchar(10)). Client still sends/keys 'product'.
 * Routes are fbrpos.auth ONLY (no company.approval — personal display pref,
 * same precedent as the PRA routes).
 */
class FbrPosGridPrefController extends Controller
{
    public function toggle(Request $request)
    {
        $data = $request->validate([
            'item_type' => 'required|in:product',
            'item_id'   => 'required|integer|min:1',
            'visible'   => 'required|boolean',
        ]);

        $user = Auth::guard('fbrpos')->user();
        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        if (!$user || !$companyId) {
            abort(403);
        }
        if (!Schema::hasTable('pos_user_item_prefs')) {
            // Prod drift safe: file deploy can land before the live migration.
            return response()->json(['ok' => false, 'error' => 'not_ready'], 503);
        }

        // The toggled item must belong to the user's own company.
        if (!Product::where('company_id', $companyId)->where('id', $data['item_id'])->exists()) {
            abort(404);
        }

        PosUserItemPref::updateOrCreate(
            ['user_id' => $user->id, 'item_type' => 'fbrproduct', 'item_id' => (int) $data['item_id']],
            ['visible' => (bool) $data['visible']]
        );

        return response()->json(['ok' => true]);
    }

    public function reset()
    {
        $user = Auth::guard('fbrpos')->user();
        if (!$user) {
            abort(403);
        }
        if (Schema::hasTable('pos_user_item_prefs')) {
            PosUserItemPref::where('user_id', $user->id)
                ->where('item_type', 'fbrproduct')
                ->delete();
        }

        return response()->json(['ok' => true]);
    }
}
