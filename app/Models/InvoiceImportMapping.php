<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Saved column-mapping preset for DI bulk import of DMS day-end exports
 * (e.g. "Coca-Cola Voyage export"). mapping_json maps our template fields to
 * the export's column headers; defaults_json holds fixed values for fields
 * the export doesn't carry (document_type, destination_province, ...).
 */
class InvoiceImportMapping extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'mapping_json',
        'defaults_json',
    ];

    /** Decoded field => source column header map. */
    public function mappingArray(): array
    {
        $decoded = json_decode($this->mapping_json ?? '[]', true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Decoded field => fixed default value map. */
    public function defaultsArray(): array
    {
        $decoded = json_decode($this->defaults_json ?? '[]', true);
        return is_array($decoded) ? $decoded : [];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
