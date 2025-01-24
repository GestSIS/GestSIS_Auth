<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordResetToken extends Model
{
    protected $fillable = [
        'token',
        'user_id',
        'validite'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
