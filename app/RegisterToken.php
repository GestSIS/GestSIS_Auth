<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class RegisterToken extends Model
{
    protected $fillable = [
        'token', 'description', 'validite'
    ];

    public function roles()
    {
        return $this->hasManyThrough(Role::class, RegisterTokenRole::class);
    }
}
