<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Frost and Brew (company id 26) — restore 'token' order-match style.
     *
     * NEUTRALIZED (Task 654 review): this file's Aug-13 timestamp sorts BEFORE
     * the Aug-23 code-rollout migration, so on a FRESH database the rollout ran
     * after it and flipped company 26 back to 'code' — the opposite of already-
     * migrated production (which ran this after the rollout and correctly ended
     * on 'token'). The real revert now lives in
     * 2026_08_23_100000_frost_and_brew_order_match_token_after_rollout.php,
     * which sorts after the rollout on every database. This file must stay
     * (it is recorded as applied on live) but is a deliberate no-op.
     */
    public function up(): void
    {
        // Intentionally no-op — see class docblock.
    }

    public function down(): void
    {
        // No-op.
    }
};
