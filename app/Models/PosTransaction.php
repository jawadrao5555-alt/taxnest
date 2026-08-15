<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PosTransaction extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'terminal_id', 'invoice_number', 'invoice_mode', 'customer_id', 'customer_name', 'customer_phone',
        'delivery_address',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax_rate', 'tax_amount', 'exempt_amount', 'total_amount', 'tax_inclusive', 'tax_menu_rate', 'payment_method',
        'cash_received', 'change_due',
        'status', 'locked_by_terminal_id', 'lock_time',
        'pra_invoice_number', 'pra_response_code', 'pra_error_message', 'pra_status', 'submission_hash', 'pra_qr_code', 'created_by',
        'business_date',
        'offline_uuid',
        'bill_token',
        'share_token', 'share_token_created_at',
        'receipt_printed_at', 'reprint_count',
        'notes',
        'is_archived', 'archived_at', 'archived_by_report_id',
        'rider_id', 'order_type', 'delivery_status', 'rider_settlement_id', 'rider_settled_at', 'rider_partial_paid',
        // Delivery duration stamps (3 Aug 2026) — fillable warna update() chupke se drop kar deta hai.
        'rider_assigned_at', 'delivered_at',
        // Task 786: who closed an unassigned delivery bill (pos user id).
        'delivered_by',
        // Prepaid conversion audit (Task 285, Aug 2026) — cash→qr_payment correction on deliveries board.
        'prepaid_converted_at', 'prepaid_converted_by',
        // Return / credit-note flow (Task 570, Aug 2026) — 'sale' | 'return' + parent link.
        'transaction_type', 'parent_transaction_id',
        // Rider auto-return wastage flag (Task 586) — spoiled goods, stock NOT restored.
        'is_wastage',
    ];

    /**
     * Global scope: hide archived bills from ALL queries by default.
     * Only the dedicated PosArchiveController (and the historical Day-Close PDF) bypass
     * this via withoutGlobalScope('hide_archived'). POS admins, cashiers, dashboards,
     * transactions list, invoice search — nothing sees archived bills.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('hide_archived', function (Builder $q) {
            // Only filter when the column exists (post-migration).
            if (\Schema::hasColumn('pos_transactions', 'is_archived')) {
                $q->where(function ($w) {
                    $w->where('is_archived', false)->orWhereNull('is_archived');
                });
            }
        });

        // Business-day stamp (owner rule 26 Jul 2026): set ONCE at creation, on
        // EVERY create path (sale, draft, restaurant settle, offline sync).
        // 00:00–05:59 bills belong to the previous day's business while that
        // day is still un-closed (PosBusinessDay). Seeded/synced rows with an
        // explicit created_at are bucketed by that timestamp, not by "now".
        static::creating(function (self $t) {
            try {
                if ($t->business_date === null
                    && $t->company_id
                    && \Schema::hasColumn('pos_transactions', 'business_date')) {
                    $at = $t->created_at
                        ? \Illuminate\Support\Carbon::parse($t->created_at)
                        : now();
                    $t->business_date = \App\Services\PosBusinessDay::forMoment((int) $t->company_id, $at);
                }
            } catch (\Throwable $e) {
                // Stamping must never block a sale — the migration backfill
                // repairs any missing stamps.
            }
        });
    }

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'exempt_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'cash_received' => 'decimal:2',
        'change_due' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'tax_menu_rate' => 'decimal:2',
        'lock_time' => 'datetime',
        'is_wastage' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function rider()
    {
        return $this->belongsTo(PosRider::class, 'rider_id');
    }

    public function items()
    {
        return $this->hasMany(PosTransactionItem::class, 'transaction_id');
    }

    /** Task 792: dine-in table link — RestaurantOrder carries pos_transaction_id. */
    public function restaurantOrder()
    {
        return $this->hasOne(RestaurantOrder::class, 'pos_transaction_id');
    }

    /** Return / credit-note flow (Task 570). */
    public function parentTransaction()
    {
        return $this->belongsTo(self::class, 'parent_transaction_id');
    }

    public function returns()
    {
        return $this->hasMany(self::class, 'parent_transaction_id')
            ->where('transaction_type', 'return');
    }

    /** Schema-drift-safe check: is this row a return/credit-note bill? */
    public function isReturnBill(): bool
    {
        return ($this->transaction_type ?? 'sale') === 'return';
    }

    /**
     * Exempt stream (Task 647; HISTORICAL-ONLY since Task 760): before
     * 15 Aug 2026 an all-exempt bill was stamped pra_status='exempt_internal'
     * by PraIntegrationService/AgentController and never reported. Owner
     * decision (Task 760): exempt items are now ZERO-RATED — reported to PRA
     * at TaxRate 0 — so NEW all-exempt bills submit normally and live in the
     * PRA stream. Nothing stamps exempt_internal anymore; these helpers keep
     * the historical rows in their own stream: not PRA (no fiscal ever), not
     * Local (deliberate provisional / reporting-OFF). SINGLE SOURCE OF TRUTH —
     * report tabs, billing scope, reprint list and receipt number-style must
     * all route through the helpers below (memory rule pos-billing-scope:
     * never inline a ternary). Historical rows are never retro-submitted.
     */
    public const EXEMPT_INTERNAL = 'exempt_internal';

    /** Is this bill in the (historical) Exempt stream — pre-zero-rating, never reported? */
    public function isExemptStream(): bool
    {
        return ($this->pra_status ?? null) === self::EXEMPT_INTERNAL;
    }

    /**
     * THE stream predicate (query side) — drives the Transactions/Reports tab
     * split everywhere (PosController::applyReportFilters delegates here).
     *   'pra'    → bills in the PRA pipeline (pra_status NOT NULL or fiscal
     *              number) EXCLUDING exempt_internal.
     *   'local'  → L-series bills + reporting-OFF finals (NULL status + no
     *              fiscal). exempt_internal has a non-NULL status → excluded
     *              by construction. Bypasses hide_archived (admin-only tab).
     *   'exempt' → exempt_internal bills only.
     */
    public static function applyStreamTab($query, string $tab)
    {
        if ($tab === 'exempt') {
            $query->where('pra_status', self::EXEMPT_INTERNAL);
        } elseif ($tab === 'local') {
            // exempt_internal takes PRECEDENCE over invoice_mode: even a (data-
            // drifted) local-mode exempt row belongs ONLY to the Exempt tab.
            $query->withoutGlobalScope('hide_archived')->where(function ($sub) {
                $sub->where('invoice_mode', 'local')
                    ->orWhere(function ($s) {
                        $s->whereNull('pra_status')->whereNull('pra_invoice_number');
                    });
            })->where(function ($sub) {
                $sub->whereNull('pra_status')->orWhere('pra_status', '!=', self::EXEMPT_INTERNAL);
            });
        } else {
            $query->where(function ($sub) {
                $sub->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })->where(function ($sub) {
                $sub->whereNotNull('pra_status')->orWhereNotNull('pra_invoice_number');
            })->where(function ($sub) {
                $sub->whereNull('pra_status')->orWhere('pra_status', '!=', self::EXEMPT_INTERNAL);
            });
        }

        return $query;
    }

    /**
     * Billing-scope stream lock (query side) — mirrors applyStreamTab. Owner
     * decision (14 Aug 2026): exempt bills belong to NO stream, so BOTH
     * pra-scoped and local-scoped staff see them.
     */
    public static function applyBillingScopeFilter($query, string $scope)
    {
        if ($scope === 'local') {
            $query->where('invoice_mode', 'local')
                ->orWhere(function ($s) {
                    $s->whereNull('pra_status')->whereNull('pra_invoice_number');
                })
                ->orWhere('pra_status', self::EXEMPT_INTERNAL);
        } elseif ($scope === 'pra') {
            // Exempt bills are ORed in EXPLICITLY, regardless of invoice_mode —
            // exempt status always takes precedence (visible to both scopes).
            $query->where(function ($outer) {
                $outer->where(function ($w) {
                    $w->where(function ($s) {
                        $s->where('invoice_mode', '!=', 'local')->orWhereNull('invoice_mode');
                    })->where(function ($s) {
                        $s->whereNotNull('pra_status')->orWhereNotNull('pra_invoice_number');
                    });
                })->orWhere('pra_status', self::EXEMPT_INTERNAL);
            });
        }

        return $query;
    }

    /**
     * Billing-scope stream lock (row side) — may a user with this scope see
     * this bill? Exempt bills are visible to EVERY scope.
     */
    public function allowedForBillingScope(string $scope): bool
    {
        if ($scope === 'both' || $this->isExemptStream()) {
            return true;
        }

        return $scope === 'local' ? $this->isLocalBill() : !$this->isLocalBill();
    }

    /**
     * "Local" bill for display/tag purposes = no PRA fiscal trail at all:
     * deliberate provisionals (invoice_mode='local') OR reporting-OFF finals
     * (pra_status NULL + no fiscal number). Anything with a non-NULL pra_status
     * (pending/submitted/completed/failed/offline) or a fiscal number is in the
     * PRA pipeline and is NOT local. Mirrors the Local Invoices report-tab split.
     */
    public function isLocalBill(): bool
    {
        return $this->invoice_mode === 'local'
            || ($this->pra_status === null && empty($this->pra_invoice_number));
    }

    /**
     * Tax-inclusive per-item display amounts (whole rupees) for receipts when the
     * "Show Tax on Receipt" toggle is OFF. Uses largest-remainder allocation so the
     * item amounts ALWAYS sum exactly to round(total + discount) — i.e. line items
     * minus the printed discount reconcile to the printed grand total, avoiding
     * customer disputes over a 1-rupee rounding drift.
     *
     * @return array<int, int> item_id => inclusive display amount (int rupees)
     */
    public function inclusiveLineAmounts(): array
    {
        $raw = [];
        foreach ($this->items as $item) {
            $raw[$item->id] = (float) $item->subtotal + (float) ($item->tax_amount ?? 0);
        }
        if (empty($raw)) {
            return [];
        }

        // Target = printed TOTAL + printed discount (both rounded independently,
        // exactly as the receipt displays them in tax-hidden mode) so that
        // itemsSum - printedDiscount == printedTotal always holds.
        $target = (int) round((float) $this->total_amount) + (int) round((float) $this->discount_amount);

        $out = [];
        $fracs = [];
        foreach ($raw as $id => $v) {
            $out[$id] = (int) floor($v);
            $fracs[$id] = $v - floor($v);
        }

        $remainder = $target - array_sum($out);
        if ($remainder > 0) {
            arsort($fracs);
            $ids = array_keys($fracs);
            $n = count($ids);
            for ($i = 0; $i < $remainder; $i++) {
                $out[$ids[$i % $n]] += 1;
            }
        } elseif ($remainder < 0) {
            asort($fracs);
            $ids = array_keys($fracs);
            $n = count($ids);
            for ($i = 0; $i < -$remainder; $i++) {
                $id = $ids[$i % $n];
                if ($out[$id] > 0) {
                    $out[$id] -= 1;
                }
            }
        }

        return $out;
    }

    /**
     * Task 777 — unguessable public reference for the receipt QR's bill page.
     * Reuses the existing share_token column (64-hex, already unguessable and
     * live everywhere) instead of adding a new column; mints lazily for old
     * bills. Schema-guarded per PROD drift convention — returns null when the
     * column is missing so callers fall back to the plain-text QR payload.
     */
    public function publicBillToken(): ?string
    {
        try {
            if (!\Schema::hasColumn('pos_transactions', 'share_token')) {
                return null;
            }
            if (!$this->share_token) {
                // Direct query update — never touches other dirty attributes
                // and works on shim/read-only render paths too. whereNull =
                // concurrent-mint safe; re-read the WINNING value afterwards.
                self::withoutGlobalScope('hide_archived')
                    ->where('id', $this->id)
                    ->whereNull('share_token')
                    ->update(['share_token' => bin2hex(random_bytes(32)), 'share_token_created_at' => now()]);
                $this->share_token = self::withoutGlobalScope('hide_archived')
                    ->where('id', $this->id)
                    ->value('share_token');
            }
            return $this->share_token ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function payments()
    {
        return $this->hasMany(PosPayment::class, 'transaction_id');
    }

    public function praLogs()
    {
        return $this->hasMany(PraLog::class, 'transaction_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lockedByTerminal()
    {
        return $this->belongsTo(PosTerminal::class, 'locked_by_terminal_id');
    }

    public function isLocked(): bool
    {
        if (!$this->locked_by_terminal_id) {
            return false;
        }
        if ($this->lock_time && $this->lock_time->diffInMinutes(now()) >= 5) {
            return false;
        }
        return true;
    }

    public function releaseLock(): void
    {
        $this->update([
            'locked_by_terminal_id' => null,
            'lock_time' => null,
        ]);
    }

    public function acquireLock(int $terminalId): bool
    {
        if ($this->isLocked() && $this->locked_by_terminal_id !== $terminalId) {
            return false;
        }
        $this->update([
            'locked_by_terminal_id' => $terminalId,
            'lock_time' => now(),
        ]);
        return true;
    }
}
