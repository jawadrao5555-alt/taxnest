<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PosImsSubmission extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['agent_claim'];
    protected $casts = ['snapshot'=>'encrypted:array','payload'=>'encrypted:array','attempts'=>'encrypted:array','attempted_at'=>'datetime'];
}
