<?php

namespace App\Models;

use App\Services\PosFeatureService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AdminAnnouncement extends Model
{
    protected $fillable = [
        'title', 'message', 'type', 'target', 'target_company_id',
        'is_active', 'expires_at', 'created_by',
        'audience_panel', 'target_categories',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'target_categories' => 'array',
    ];

    public function targetCompany()
    {
        return $this->belongsTo(Company::class, 'target_company_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dismissals()
    {
        return $this->hasMany(AnnouncementDismissal::class, 'announcement_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForCompany($query, $companyId, ?Company $company = null)
    {
        $company ??= Company::find($companyId);
        $panel = PosFeatureService::panelFor($company);
        $category = PosFeatureService::resolveCategory($company);

        return $query->where(function ($q) use ($companyId) {
            $q->where('target', 'all')
              ->orWhere(function ($q2) use ($companyId) {
                  $q2->where('target', 'specific')->where('target_company_id', $companyId);
              });
        })->when(Schema::hasColumn('admin_announcements', 'audience_panel'), function ($q) use ($panel) {
            $q->where(function ($w) use ($panel) {
                $w->whereNull('audience_panel')->orWhere('audience_panel', 'all')
                    ->orWhere('audience_panel', $panel);
            });
        })->when(Schema::hasColumn('admin_announcements', 'target_categories'), function ($q) use ($category) {
            $q->where(function ($w) use ($category) {
                $w->whereNull('target_categories')->orWhereJsonContains('target_categories', $category);
            });
        });
    }

    /** The preview and the dashboard use the same audience decisions. */
    public function reachesCompany(Company $company): bool
    {
        if ($this->target === 'specific' && (int) $this->target_company_id !== (int) $company->id) {
            return false;
        }
        if ($this->target !== 'specific' && $this->target !== 'all') {
            return false;
        }
        if (!in_array($this->audience_panel ?: 'all', ['all', PosFeatureService::panelFor($company)], true)) {
            return false;
        }
        $categories = $this->target_categories;
        return !$categories || in_array(PosFeatureService::resolveCategory($company), $categories, true);
    }
}
