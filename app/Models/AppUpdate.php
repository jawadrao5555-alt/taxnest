<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppUpdate extends Model
{
    protected $fillable = [
        'title', 'points', 'image_path', 'audience', 'is_published', 'created_by',
    ];

    protected $casts = [
        'points' => 'array',
        'is_published' => 'boolean',
    ];

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function seens()
    {
        return $this->hasMany(AppUpdateSeen::class, 'app_update_id');
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
