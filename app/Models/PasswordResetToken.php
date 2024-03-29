<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetToken extends Model
{
    protected $fillable = [
        'token', 'user_id', 'validite'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
