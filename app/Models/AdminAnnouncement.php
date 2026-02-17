<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAnnouncement extends Model
{
    protected $fillable = [
        'title', 'message', 'type', 'target', 'target_company_id',
        'is_active', 'expires_at', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
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

    public function scopeForCompany($query, $companyId)
    {
        return $query->where(function ($q) use ($companyId) {
            $q->where('target', 'all')
              ->orWhere(function ($q2) use ($companyId) {
                  $q2->where('target', 'specific')->where('target_company_id', $companyId);
              });
        });
    }
}
