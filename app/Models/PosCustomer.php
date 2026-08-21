<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosCustomer extends Model
{
    protected $fillable = [
        'company_id', 'name', 'email', 'phone', 'address',
        'city', 'ntn', 'cnic', 'type', 'is_active',
        'loyalty_points', 'loyalty_tier', 'total_spent', 'total_orders',
        'khata_balance',
        // (Khata upgrade Aug 2026) per-customer credit limit + last-reminder
        // stamp. Non-fillable would SILENTLY drop on create/update — must be
        // listed here or the udhaar hadd never sticks.
        'khata_limit', 'khata_last_reminder_at',
        // Remembered delivery pin (Task 1105) — filled when a bill for this
        // phone gets a customer location; pre-pins the next locate modal.
        'geo_lat', 'geo_lng',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'loyalty_points' => 'integer',
        'total_spent' => 'decimal:2',
        'total_orders' => 'integer',
        'khata_balance' => 'decimal:2',
        // (Khata upgrade Aug 2026)
        'khata_limit' => 'decimal:2',
        'khata_last_reminder_at' => 'datetime',
    ];

    /**
     * (Khata upgrade Aug 2026) PROD schema-drift self-heal guard.
     *
     * The owner's live cPanel DB can lag a migration (a column marked "Ran"
     * without actually existing — see prod-schema-drift-selfheal.md). Reads and
     * WRITES of the freshly-added khata columns must degrade to "no limit / no
     * reminder stamp" rather than 500 the customers page, the sale path or the
     * khata page before the migration lands. Cached per-request so we probe
     * information_schema at most once per column.
     */
    /** Per-request cache of khata column probes (see khataColumnExists). */
    protected static array $khataColumnCache = [];

    public static function khataColumnExists(string $column): bool
    {
        if (!array_key_exists($column, static::$khataColumnCache)) {
            try {
                static::$khataColumnCache[$column] = \Illuminate\Support\Facades\Schema::hasColumn((new static)->getTable(), $column);
            } catch (\Throwable $e) {
                // If even the probe fails (odd driver/permissions), assume absent
                // and degrade — never let a diagnostic call take down a sale.
                static::$khataColumnCache[$column] = false;
            }
        }
        return static::$khataColumnCache[$column];
    }

    /** Clear the probe cache — for tests that mutate the schema mid-run. */
    public static function flushKhataColumnCache(): void
    {
        static::$khataColumnCache = [];
    }

    public function khataLedger()
    {
        return $this->hasMany(FbrCustomerLedger::class, 'customer_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
