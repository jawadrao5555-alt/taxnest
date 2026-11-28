<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpcomingProduct extends Model
{
    public const STATUSES = [
        'upcoming' => 'Coming Soon',
        'in_development' => 'In Development',
        'beta' => 'Beta',
        'released' => 'Released',
        'temporarily_unavailable' => 'Temporarily Unavailable',
    ];

    public const CONCEPTS = [
        'staff' => 'Staff and shifts',
        'distribution' => 'Delivery workflow',
        'migration' => 'Data mapping',
        'portfolio' => 'Business overview',
        'laboratory' => 'Specimen workflow',
        'general' => 'General workflow',
    ];

    protected $fillable = [
        'slug', 'name', 'introduction', 'benefits', 'features', 'industries',
        'concept', 'status', 'existing_product_path', 'sort_order', 'published_at',
    ];

    protected $casts = [
        'benefits' => 'array',
        'features' => 'array',
        'industries' => 'array',
        'published_at' => 'datetime',
    ];

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function interests(): HasMany
    {
        return $this->hasMany(UpcomingProductInterest::class);
    }
}
