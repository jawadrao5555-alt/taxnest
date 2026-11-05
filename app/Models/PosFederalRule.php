<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PosFederalRule extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['enabled'=>'boolean','mapping'=>'encrypted:array','reviewed_at'=>'datetime'];
}
