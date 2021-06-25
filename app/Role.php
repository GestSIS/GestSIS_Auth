<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use App\User;
use App\UserRole;


class Role extends Model
{
    protected $fillable = [
        'nom', 'description'
    ];

    
    public function users()
    {
        return $this->hasManyThrough(User::class, UserRole::class);
    }
}
