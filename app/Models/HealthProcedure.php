<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The priced catalogue of what the theatre does.
 *
 * A PACKAGE price is the all-in figure a hospital quotes a patient. When one is
 * set, the operation posts that single number and its consumables stop being
 * separately billable — that is exactly what "package" means to the person who
 * agreed to it, and billing the sutures on top is how a hospital loses an
 * argument at the discharge counter.
 *
 * Catalogue rows are DEACTIVATED, never deleted: operations are filed against
 * them and a register that renders a blank procedure name is worthless.
 */
class HealthProcedure extends Model
{
    public const ANAESTHESIA_TYPES = [
        'general', 'spinal', 'epidural', 'local', 'sedation', 'regional', 'none',
    ];

    protected $fillable = [
        'company_id',
        'health_department_id',
        'name',
        'code',
        'category',
        'description',
        'base_price',
        'is_package',
        'package_price',
        'package_includes',
        'default_anaesthesia',
        'estimated_minutes',
        'pre_op_checklist',
        'is_active',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'package_price' => 'decimal:2',
        'is_package' => 'boolean',
        'is_active' => 'boolean',
        'estimated_minutes' => 'integer',
        'company_id' => 'integer',
        'health_department_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function department()
    {
        return $this->belongsTo(HealthDepartment::class, 'health_department_id');
    }

    /** What an operation off this catalogue row should be priced at. */
    public function effectivePrice(): float
    {
        if ($this->is_package && $this->package_price !== null) {
            return (float) $this->package_price;
        }

        return (float) $this->base_price;
    }

    /**
     * The default pre-op checklist, as a list of plain item strings.
     *
     * Stored as JSON but tolerant of a hospital that typed a newline-separated
     * list into the box: the checklist is a safety habit, and refusing to save
     * it over a formatting detail helps nobody.
     */
    public function checklistItems(): array
    {
        $raw = trim((string) $this->pre_op_checklist);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map(
                fn ($item) => is_array($item) ? trim((string) ($item['item'] ?? '')) : trim((string) $item),
                $decoded
            ), fn ($item) => $item !== ''));
        }

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
    }

    public static function anaesthesiaLabelKey(?string $type): string
    {
        return 'health.anaesthesia_' . (in_array($type, self::ANAESTHESIA_TYPES, true) ? $type : 'none');
    }
}
