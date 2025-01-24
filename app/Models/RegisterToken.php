<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RegisterToken extends Model
{
    protected $fillable = [
        'token',
        'description',
        'validite'
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'register_token_roles');
    }
}
