<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lightweight POS survey (Task 1022 — Caller ID elaan / advice collection).
 * questions = [{key, text, options: [{key, label}]}] (fixed-choice only).
 */
class Survey extends Model
{
    protected $fillable = [
        'title', 'intro', 'questions', 'allow_comment', 'audience', 'is_published', 'closed_at',
    ];

    protected $casts = [
        'allow_comment' => 'boolean',
        'is_published' => 'boolean',
        'closed_at' => 'datetime',
    ];

    /** Always return an array — one bad row must never 500 the POS layout. */
    public function getQuestionsAttribute($value)
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values($decoded) : [];
    }

    public function setQuestionsAttribute($value)
    {
        $this->attributes['questions'] = is_string($value)
            ? $value
            : json_encode(array_values((array) $value), JSON_UNESCAPED_UNICODE);
    }

    public function scopeActive($query)
    {
        return $query->where('is_published', true)->whereNull('closed_at');
    }

    public function responses()
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function isActive(): bool
    {
        return $this->is_published && $this->closed_at === null;
    }
}
