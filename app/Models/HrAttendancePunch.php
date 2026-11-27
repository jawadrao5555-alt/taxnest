<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class HrAttendancePunch extends Model
{
    public $timestamps = false;

    protected $fillable = ['organization_id', 'shift_id', 'actor_user_id', 'request_key', 'action', 'occurred_at_utc'];
}
