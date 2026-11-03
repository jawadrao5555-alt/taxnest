<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PosImsProfile extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['sandbox_token','production_token'];
    protected $casts = ['enabled'=>'boolean','sandbox_token'=>'encrypted','production_token'=>'encrypted','production_confirmed_at'=>'datetime'];
}
