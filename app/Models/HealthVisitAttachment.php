<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file filed against one encounter — an outside lab report, a wound photo,
 * a scanned referral.
 *
 * Only the pointer lives here. The bytes sit on the PRIVATE local disk inside
 * the per-company healthcare directory the platform service owns, and are
 * served through a controller that re-checks the clinical capability. A patient
 * file must never become a guessable public URL.
 */
class HealthVisitAttachment extends Model
{
    public const KINDS = ['report', 'image', 'other'];

    protected $fillable = [
        'company_id',
        'health_visit_id',
        'health_patient_id',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'kind',
        'caption',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'company_id' => 'integer',
        'health_visit_id' => 'integer',
        'health_patient_id' => 'integer',
        'uploaded_by' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function visit()
    {
        return $this->belongsTo(HealthVisit::class, 'health_visit_id');
    }

    public function patient()
    {
        return $this->belongsTo(HealthPatient::class, 'health_patient_id');
    }

    public function getSizeLabelAttribute(): string
    {
        $bytes = (int) $this->size_bytes;
        if ($bytes <= 0) {
            return '—';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
