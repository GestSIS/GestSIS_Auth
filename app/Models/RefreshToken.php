<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    //
    protected $fillable = ['token', 'expire'];


    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }
}
