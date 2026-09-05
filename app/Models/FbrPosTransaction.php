<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\PosRider;

class FbrPosTransaction extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'invoice_number', 'invoice_mode', 'customer_name', 'customer_phone', 'customer_ntn',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax_rate', 'tax_amount', 'fbr_service_charge', 'total_amount', 'payment_method',
        'status', 'fbr_invoice_number', 'fbr_status', 'fbr_response_code',
        // Task 627: short human failure reason (timeout / HTTP code / FBR message) — F11 modal.
        'fbr_error_message',
        'fbr_response', 'fbr_submission_hash', 'created_by',
        'share_token', 'share_token_created_at',
        // Phase 2 fields
        'terminal_id', 'shift_id', 'transaction_type', 'parent_transaction_id',
        'customer_id', 'promotion_id', 'promotion_code',
        'loyalty_points_earned', 'loyalty_points_redeemed', 'loyalty_redemption_amount',
        'cash_received', 'change_due', 'payment_breakdown',
        // Task 156: order-type + delivery-address snapshot (Pending Deliveries panel)
        'order_type', 'delivery_address',
        // Aug 2026: idempotency key — client-generated UUID riding on every submit attempt
        // so the server replay-guard can return the existing bill on a lost-response retry.
        'offline_uuid',
        // Order Matching (Aug 2026): token/code copied from held sale at billing time;
        // stored permanently so receipt reprints always carry the correct match identifier.
        'token_no', 'order_code',
        // Persistent automated-retry counter — incremented by every automated retry path
        // (SyncFbrPosOfflineInvoicesJob, RetryFbrPosSubmissionJob queue-retries,
        // apiRetryFailed auto-sync calls) on failure. Reset to 0 on explicit manual retry
        // or successful submission. Scheduler skips bills at >= MAX_AUTO_RETRY (5).
        'fbr_auto_retry_count',
        // Rider / delivery lifecycle (Aug 2026) — mirrors PRA pos_transactions rider columns.
        'rider_id', 'delivery_status', 'rider_assigned_at', 'delivered_at',
        // Task 786: who closed an unassigned delivery bill (pos user id).
        'delivered_by',
        'rider_settlement_id', 'rider_settled_at', 'rider_partial_paid',
        'prepaid_converted_at', 'prepaid_converted_by',
        // Task 492: trading-day bucket (PosBusinessDay cutoff rule, FBR mirror).
        // Shop-facing grouping only — FBR/tax reporting keeps real created_at.
        'business_date',
        // Task 1389: stamped the first time this bill's store slip is rendered
        // or enqueued — separates a first print from a reprint (FBR twin of
        // pos_transactions.kot_sent_at, read by isTransactionReprint()).
        'kot_sent_at',
        // 💊 Pharmacy Mode (Task 1558): prescription capture — who prescribed,
        // for whom, and the photographed slip. Recorded on the bill so a
        // schedule-medicine sale can be answered for later.
        'doctor_name', 'patient_name', 'prescription_image',
    ];

    protected static function booted()
    {
        // Business-day stamp (Task 492 — FBR mirror of the PRA rule): set ONCE
        // at creation on EVERY create path (sale, provisional, offline sync).
        // 00:00–05:59 bills belong to the previous day's business while that
        // day is still un-closed per fbr_day_close_reports. Synced rows with an
        // explicit created_at are bucketed by that timestamp, not by "now".
        static::creating(function (self $t) {
            try {
                if ($t->business_date === null
                    && $t->company_id
                    && \Schema::hasColumn('fbr_pos_transactions', 'business_date')) {
                    $at = $t->created_at
                        ? \Illuminate\Support\Carbon::parse($t->created_at)
                        : now();
                    $t->business_date = \App\Services\PosBusinessDay::forMomentFbr((int) $t->company_id, $at);
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
        'fbr_service_charge' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'fbr_response' => 'array',
        'share_token_created_at' => 'datetime',
        'cash_received' => 'decimal:2',
        'change_due' => 'decimal:2',
        'loyalty_redemption_amount' => 'decimal:2',
        'payment_breakdown' => 'array',
        'fbr_auto_retry_count' => 'integer',
        'kot_sent_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function items()
    {
        return $this->hasMany(FbrPosTransactionItem::class, 'transaction_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Task 799: who closed an unassigned delivery bill (no rider, direct mark-delivered).
     */
    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function fbrLogs()
    {
        return $this->hasMany(FbrPosLog::class, 'transaction_id');
    }

    /** Assigned rider (shared pos_riders table, company-scoped). */
    public function rider()
    {
        return $this->belongsTo(PosRider::class, 'rider_id');
    }

    /**
     * Task 1271 — unguessable public reference for the shared bill PDF
     * (FBR twin of PosTransaction::publicBillToken, Task 777 pattern).
     * Mints lazily into the existing share_token column; schema-guarded so
     * PROD drift returns null instead of 500ing a pay response.
     */
    public function publicBillToken(): ?string
    {
        try {
            if (!\Schema::hasColumn('fbr_pos_transactions', 'share_token')) {
                return null;
            }
            if (!$this->share_token) {
                // Direct conditional update = concurrent-mint safe; re-read the
                // WINNING value afterwards (two cashiers sharing the same bill).
                self::where('id', $this->id)
                    ->whereNull('share_token')
                    ->update(['share_token' => bin2hex(random_bytes(32)), 'share_token_created_at' => now()]);
                $this->share_token = self::where('id', $this->id)->value('share_token');
            }
            return $this->share_token ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Task 1271 — deliberate provisional (FBR finality predicate, mirrors the
     * pos-provisional triple rule): invoice_mode='local' + fbr_status='local'
     * = still editable/deletable until promoted, so it must NEVER be shared
     * with a customer. Reporting-OFF finals (fbr_status NULL) are NOT
     * provisional.
     */
    public function isDeliberateProvisional(): bool
    {
        return ($this->invoice_mode ?? null) === 'local'
            && ($this->fbr_status ?? null) === 'local';
    }

    /**
     * Task 1271 — WhatsApp Bill extras for FINAL-bill JSON responses (FBR twin
     * of PosTransaction::waBillPayload). Both values null when the company
     * feature is off, the plan lacks it, the bill is a deliberate provisional,
     * the customer number isn't routable, or the token can't be minted — the
     * client hides the button on null. Never throws: share extras must not
     * break a pay response.
     */
    public function waBillPayload(?Company $company): array
    {
        $out = ['wa_phone' => null, 'share_url' => null];
        try {
            if (!$company
                || !\Schema::hasColumn('companies', 'pos_whatsapp_bill_enabled')
                || !$company->pos_whatsapp_bill_enabled
                || !\App\Services\PosFeatureService::planAllows($company, 'whatsapp_enabled')
                || $this->isDeliberateProvisional()) {
                return $out;
            }
            $wa = \App\Services\PkPhone::normalize($this->customer_phone);
            if (!$wa) {
                return $out;
            }
            $token = $this->publicBillToken();
            if (!$token) {
                return $out;
            }
            $out['wa_phone'] = $wa;
            $out['share_url'] = url('/fbr-pos/invoice/share/' . $token);
        } catch (\Throwable $e) {
            // fall through with nulls
        }
        return $out;
    }
}
