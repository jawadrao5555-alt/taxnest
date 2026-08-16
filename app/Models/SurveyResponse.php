<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (survey, user). answers NULL = seen/dismissed only;
 * answered_at set = real submitted response (never overwritten).
 */
class SurveyResponse extends Model
{
    protected $fillable = [
        'survey_id', 'user_id', 'company_id', 'answers', 'comment', 'answered_at',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
    ];

    public function getAnswersAttribute($value)
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : null;
    }

    public function setAnswersAttribute($value)
    {
        $this->attributes['answers'] = $value === null
            ? null
            : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
