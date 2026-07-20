<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppUpdateSeen extends Model
{
    protected $fillable = [
        'app_update_id', 'user_id',
    ];

    public function appUpdate()
    {
        return $this->belongsTo(AppUpdate::class, 'app_update_id');
    }
}
