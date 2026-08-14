<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppUpdate extends Model
{
    protected $fillable = [
        'title', 'points', 'image_path', 'audience', 'is_published', 'is_featured', 'created_by',
    ];

    protected $casts = [
        'points' => 'array',
        'is_published' => 'boolean',
        // Featured "bara elaan" (Task 722): renders as a celebratory hero popup.
        'is_featured' => 'boolean',
    ];

    /**
     * Self-healing accessor (11 Aug 2026): a write path once DOUBLE-encoded
     * points (JSON string inside JSON) and the What's New foreach in the
     * pos-app/fbr-pos-app layouts 500'd EVERY panel page. Always return an
     * array here so one bad row can never take the panels down again.
     */
    public function getPointsAttribute($value)
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (is_string($decoded)) {
            $inner = json_decode($decoded, true);
            $decoded = is_array($inner) ? $inner : [$decoded];
        }
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** Normalize writes: a JSON string is decoded (not re-encoded), a plain string becomes one point. */
    public function setPointsAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        $this->attributes['points'] = json_encode(array_values(array_filter((array) $value, fn ($p) => $p !== null && $p !== '')), JSON_UNESCAPED_UNICODE);
    }

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
