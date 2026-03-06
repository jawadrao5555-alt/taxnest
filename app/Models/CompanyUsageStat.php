<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyUsageStat extends Model
{
    protected $fillable = [
        'company_id', 'total_pos_transactions', 'total_sales_amount',
        'active_terminals', 'active_users', 'inventory_items', 'last_activity_at',
    ];

    protected $casts = [
        'total_sales_amount' => 'float',
        'last_activity_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public static function refreshForCompany(int $companyId): self
    {
        $stats = self::firstOrCreate(['company_id' => $companyId]);

        $stats->update([
            'total_pos_transactions' => \App\Models\PosTransaction::where('company_id', $companyId)->where('status', 'completed')->count(),
            'total_sales_amount' => \App\Models\PosTransaction::where('company_id', $companyId)->where('status', 'completed')->sum('total_amount'),
            'active_terminals' => \App\Models\PosTerminal::where('company_id', $companyId)->where('is_active', true)->count(),
            'active_users' => \App\Models\User::where('company_id', $companyId)->count(),
            'inventory_items' => \App\Models\InventoryStock::where('company_id', $companyId)->count(),
            'last_activity_at' => now(),
        ]);

        return $stats;
    }
}
