<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ResetPasswordToken extends Model
{
    protected $fillable = [
        'token', 'user_id', 'validite'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
