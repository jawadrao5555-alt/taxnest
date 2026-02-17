<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnouncementDismissal extends Model
{
    protected $fillable = ['announcement_id', 'user_id'];

    public function announcement()
    {
        return $this->belongsTo(AdminAnnouncement::class, 'announcement_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
