<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'barcode',
        'sku',
        'hs_code',
        'pct_code',
        'default_tax_rate',
        'tax_type',
        'uom',
        'schedule_type',
        'sro_reference',
        'serial_number',
        'mrp',
        'default_price',
        'is_price_editable',
        'is_active',
        'show_on_sale',
        'is_third_schedule',
        // Peti (Wholesale) Rate (Task 1414): "peti mein kitne piece" — nullable.
        // Missing from $fillable ⇒ Eloquent silently drops the write on
        // create()/update() (the exact trap the plan warns about).
        'pack_size',
        // ── Pharmacy Mode (Task 1558) ────────────────────────────────────
        // A medical store bills on the salt, not on the printed brand name, so
        // these ride the same catalogue row rather than a side table. They are
        // only ever SHOWN in pharmacy mode; a non-pharmacy shop simply leaves
        // them null and nothing about its catalogue changes.
        'generic_name',
        'strength',
        'dosage_form',
        'manufacturer',
        'drug_schedule',
        'prescription_required',
        'shelf_location',
        'strips_per_pack',
        'units_per_strip',
        'allow_loose_sale',
        // ── DRAP medicine catalogue link (Task 1579) ─────────────────────
        // A product added from the global catalogue remembers WHICH catalogue
        // row it came from (MRP update notices ride on this) and its DRAP
        // registration number (an extra alias the Excel importer matches on).
        'medicine_catalogue_id',
        'drap_reg_no',
    ];

    /** The global DRAP catalogue row this product was added from (pharmacy mode). */
    public function catalogueEntry()
    {
        return $this->belongsTo(MedicineCatalogueEntry::class, 'medicine_catalogue_id');
    }

    protected $casts = [
        'is_price_editable' => 'boolean',
        'is_active' => 'boolean',
        'show_on_sale' => 'boolean',
        'is_third_schedule' => 'boolean',
        // NULL stays NULL (not-a-peti-product); a real value casts to int.
        'pack_size' => 'integer',
        'prescription_required' => 'boolean',
        'allow_loose_sale' => 'boolean',
        'strips_per_pack' => 'integer',
        'units_per_strip' => 'integer',
    ];

    /** Every dosage form the medicine form offers. Free text is still allowed. */
    public const DOSAGE_FORMS = [
        'tablet', 'capsule', 'syrup', 'suspension', 'injection', 'drops',
        'cream', 'ointment', 'inhaler', 'sachet', 'suppository', 'other',
    ];

    /**
     * Drug schedules under the Drugs Act 1976 / DRAP rules that a counter
     * actually has to care about. 'G' and 'H' are the prescription-only ones,
     * 'CD' is a controlled drug, 'OTC' needs no prescription at all.
     */
    public const DRUG_SCHEDULES = ['OTC', 'G', 'H', 'CD', 'X'];

    /**
     * How many loose units (tablets / capsules) live inside ONE stocked pack.
     *
     * Stock is always counted in stocked packs — that is the unit every
     * existing report, the FBR payload and inventory_stocks already speak. A
     * broken strip is therefore a FRACTION of a pack, and this is the divisor.
     * Returns null when the product has no loose composition at all, which is
     * how a syrup bottle stays un-breakable.
     */
    public function looseUnitsPerPack(): ?int
    {
        $perStrip = (int) ($this->units_per_strip ?? 0);
        if ($perStrip < 1) {
            return null;
        }
        $strips = (int) ($this->strips_per_pack ?? 0);
        $total  = $perStrip * ($strips > 0 ? $strips : 1);

        return $total > 0 ? $total : null;
    }

    /** Can this medicine be broken open at the counter? */
    public function sellsLoose(): bool
    {
        return (bool) $this->allow_loose_sale && $this->looseUnitsPerPack() !== null;
    }

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function aliases()
    {
        return $this->hasMany(ProductAlias::class);
    }
}
