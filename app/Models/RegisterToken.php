<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegisterToken extends Model
{
    protected $fillable = [
        'token', 'description', 'validite'
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'register_token_roles');
    }
}
