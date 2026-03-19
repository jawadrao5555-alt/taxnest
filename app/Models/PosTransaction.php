<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTransaction extends Model
{
    protected $fillable = [
        'company_id', 'terminal_id', 'invoice_number', 'customer_name', 'customer_phone',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax_rate', 'tax_amount', 'exempt_amount', 'total_amount', 'payment_method',
        'status', 'locked_by_terminal_id', 'lock_time',
        'pra_invoice_number', 'pra_response_code', 'pra_status', 'submission_hash', 'pra_qr_code', 'created_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'exempt_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
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

    public function items()
    {
        return $this->hasMany(PosTransactionItem::class, 'transaction_id');
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
