<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user sale-screen grid visibility override (owner, 25 Jul 2026).
 * One row per explicit user choice: visible=false hides an item from THAT
 * user's grid; visible=true force-shows an item the admin hid via
 * show_on_sale. No row = admin default applies. Never affects search,
 * billing, KOT or reports — pure grid decluttering.
 */
class PosUserItemPref extends Model
{
    protected $fillable = ['user_id', 'item_type', 'item_id', 'visible'];

    protected $casts = [
        'visible' => 'boolean',
    ];

    /**
     * Prefs map for the sale-screen JS: { "product:12": 0, "deal:3": 1 }.
     * Prod-drift safe: returns [] until the table exists on live.
     */
    public static function mapForUser(?int $userId): array
    {
        if (!$userId) {
            return [];
        }
        try {
            if (!Schema::hasTable('pos_user_item_prefs')) {
                return [];
            }
            return static::where('user_id', $userId)
                ->get()
                ->mapWithKeys(fn ($r) => [$r->item_type . ':' . $r->item_id => $r->visible ? 1 : 0])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Task 1271 — FBR sale-screen prefs map. FBR grid items are Product rows
     * (NOT PosProduct), so their prefs are stored with item_type='fbrproduct'
     * (10-char column cap) to keep the id spaces separate. The FBR client JS
     * still keys items as "product:ID" — remap here.
     */
    public static function mapForFbrUser(?int $userId): array
    {
        if (!$userId) {
            return [];
        }
        try {
            if (!Schema::hasTable('pos_user_item_prefs')) {
                return [];
            }
            return static::where('user_id', $userId)
                ->where('item_type', 'fbrproduct')
                ->get()
                ->mapWithKeys(fn ($r) => ['product:' . $r->item_id => $r->visible ? 1 : 0])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
