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
        'tax_rate', 'tax_amount', 'exempt_amount', 'total_amount', 'payment_method',
        'cash_received', 'change_due',
        'status', 'locked_by_terminal_id', 'lock_time',
        'pra_invoice_number', 'pra_response_code', 'pra_status', 'submission_hash', 'pra_qr_code', 'created_by',
        'offline_uuid',
        'share_token', 'share_token_created_at',
        'receipt_printed_at', 'reprint_count',
        'notes',
        'is_archived', 'archived_at', 'archived_by_report_id',
        'rider_id', 'order_type', 'delivery_status', 'rider_settlement_id', 'rider_settled_at',
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
        'lock_time' => 'datetime',
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
