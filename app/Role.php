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
        return $this->belongsToMany(User::class, 'user_roles');
    }
    
    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }
    
    public function registerTokens()
    {
        return $this->belongsToMany(RegisterToken::class, 'register_token_roles');
    }
    
    public function permissionRoles()
    {
        return $this->hasMany(PermissionRole::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_roles');
    }

    public function sis()
    {
        return $this->belongsTo(Sis::class);
    }
}
