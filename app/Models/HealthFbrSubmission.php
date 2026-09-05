<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One regulator attempt, kept forever (Task 1551).
 *
 * Never overwritten and never merged with the next attempt. The exact payload
 * that left this server and the exact body that came back are both stored,
 * because "what did we actually send FBR" is the only question that matters
 * when a filing is disputed — and re-deriving the payload months later from
 * data that has since moved answers a different question.
 *
 * A failed attempt is as important as a successful one: together the rows are
 * the retry history, and they are what tells a hospital whether a missing
 * invoice number means "we never tried", "FBR refused us" or "our own
 * configuration was wrong".
 */
class HealthFbrSubmission extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFIG_ERROR = 'config_error';
    public const STATUS_QUEUED_AGENT = 'queued_agent';
    public const STATUS_BLOCKED = 'blocked';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_AUTO = 'auto';
    public const TRIGGER_RETRY = 'retry';
    public const TRIGGER_DAY_CLOSE = 'day_close';

    protected $fillable = [
        'company_id',
        'health_bill_id',
        'fbr_pos_transaction_id',
        'attempt_no',
        'status',
        'trigger',
        'request_payload',
        'response_payload',
        'response_code',
        'invoice_number',
        'error_message',
        'submitted_at',
        'duration_ms',
        'actor_id',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'duration_ms' => 'integer',
        'submitted_at' => 'datetime',
        'company_id' => 'integer',
        'health_bill_id' => 'integer',
        'fbr_pos_transaction_id' => 'integer',
        'actor_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function bill()
    {
        return $this->belongsTo(HealthBill::class, 'health_bill_id');
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUBMITTED && !empty($this->invoice_number);
    }

    /** Pretty-print the stored JSON for the evidence screen; raw text if it is not JSON. */
    public function prettyRequest(): string
    {
        return self::pretty($this->request_payload);
    }

    public function prettyResponse(): string
    {
        return self::pretty($this->response_payload);
    }

    private static function pretty(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $raw;
        }

        $out = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $out === false ? $raw : $out;
    }
}
