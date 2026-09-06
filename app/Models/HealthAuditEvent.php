<?php

namespace App\Models;

use App\Services\HealthAudit\HealthAuditRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * One attributable act inside the healthcare panel (Task 1554).
 *
 * The row is written once and never again. UPDATE and DELETE throw rather than
 * silently succeeding, because "audit records cannot be silently edited or
 * removed by ordinary company users" has to be a property of the record, not a
 * promise made by whichever screen happens to be in front of them.
 *
 * A hard delete is still possible at the database level — no application can
 * prevent that — which is what the hash chain is for: every row carries the
 * hash of the row written before it, so a removed row leaves a successor whose
 * ancestor cannot be found and the verifier says so.
 */
class HealthAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'occurred_at',
        'category',
        'event',
        'action',
        'actor_user_id',
        'actor_name',
        'actor_role',
        'entity_type',
        'entity_id',
        'entity_label',
        'health_patient_id',
        'health_doctor_id',
        'amount',
        'reason',
        'source',
        'ip_address',
        'user_agent',
        'route',
        'old_values',
        'new_values',
        'meta',
        'is_sensitive',
        'prev_hash',
        'sha256_hash',
        'created_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'old_values' => 'array',
        'new_values' => 'array',
        'meta' => 'array',
        'is_sensitive' => 'boolean',
        'amount' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException('Healthcare audit events are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Healthcare audit events are immutable and cannot be deleted.');
        });
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The canonical string this row's hash covers.
     *
     * EVERY persisted column except the hash itself — the row's own id and its
     * created_at stamp included. A field the hash does not cover is a field
     * somebody can rewrite while the verifier keeps saying "intact": the branch
     * an act was filed under, the address it came from, whether it was flagged
     * sensitive, WHEN the system wrote it down, or WHERE it sits in the order
     * of the table. None of those may be quietly editable.
     *
     * Because the id is part of the payload the hash is sealed AFTER the row
     * has been inserted, inside the same transaction (see the recorder).
     *
     * Field order is frozen. Adding a field to the END is safe for rows written
     * afterwards; re-ordering would make every historic row read as altered.
     */
    public function canonicalPayload(): string
    {
        return implode('|', [
            'v3',
            (string) ($this->id ?? ''),
            (int) $this->company_id,
            (string) ($this->branch_id ?? ''),
            (string) ($this->health_department_id ?? ''),
            self::stamp($this->occurred_at),
            (string) $this->category,
            (string) $this->event,
            (string) $this->action,
            (string) ($this->actor_user_id ?? ''),
            (string) ($this->actor_name ?? ''),
            (string) ($this->actor_role ?? ''),
            (string) ($this->entity_type ?? ''),
            (string) ($this->entity_id ?? ''),
            (string) ($this->entity_label ?? ''),
            (string) ($this->health_patient_id ?? ''),
            (string) ($this->health_doctor_id ?? ''),
            $this->amount === null ? '' : number_format((float) $this->amount, 2, '.', ''),
            (string) ($this->reason ?? ''),
            (string) ($this->source ?? ''),
            (string) ($this->ip_address ?? ''),
            (string) ($this->user_agent ?? ''),
            (string) ($this->route ?? ''),
            $this->storedJson('old_values'),
            $this->storedJson('new_values'),
            $this->storedJson('meta'),
            $this->is_sensitive ? '1' : '0',
            (string) ($this->prev_hash ?? ''),
            self::stamp($this->created_at),
        ]);
    }

    /**
     * A timestamp as the column stores it: whole seconds, no zone suffix.
     *
     * Written and re-read values must serialise byte-for-byte, so the format is
     * pinned here rather than left to whatever the cast happens to produce.
     */
    protected static function stamp($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) ($value ?? '');
    }

    /**
     * The EXACT text a JSON column holds, before the cast turns it into an
     * array and after a re-read turns it back.
     *
     * This has to be byte-identical at write time and at verification time or
     * every row carrying a before/after diff reads as tampered with. Going
     * through the cast (json_encode of the decoded array) is not good enough:
     * the value would be re-serialised, and a float's serialisation depends on
     * the php.ini the verifier happens to run under, which is how a CLI check
     * ends up disagreeing with a row the web request wrote.
     *
     * `getAttributes()` is the one accessor that returns the stored text in
     * both states — an array cast is encoded on assignment, so it is already a
     * string before the row is saved.
     */
    protected function storedJson(string $key): string
    {
        $raw = $this->getAttributes()[$key] ?? null;

        if ($raw === null) {
            return '';
        }

        return is_string($raw) ? $raw : (string) json_encode($raw);
    }

    /**
     * The recorded reason as THIS reader may see it.
     *
     * The reason is free text somebody typed, and people type patient names
     * and clinical detail into "reason" boxes. So the words are shown only to
     * a reader who may open the clinical record anyway (clinical.view); an
     * auditor of the books learns that a reason was given and how long it
     * was, which is what the controls need, and nothing more.
     */
    public function reasonFor(?User $reader): ?string
    {
        return HealthAuditRecorder::wordsFor($reader, (int) $this->company_id, $this->reason);
    }

    /** The auditor-safe form: the pack always carries this one, whoever built it. */
    public function reasonWithheld(): ?string
    {
        return HealthAuditRecorder::withhold($this->reason);
    }

    /** Same, for a plain row that did not come through the model. */
    public static function withholdReason(?string $reason): ?string
    {
        return HealthAuditRecorder::withhold($reason);
    }

    public function expectedHash(): string
    {
        return hash('sha256', $this->canonicalPayload());
    }
}
