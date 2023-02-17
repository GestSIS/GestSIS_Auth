<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Role extends Model
{
    protected $fillable = [
        'nom', 'description'
    ];

    protected $casts = ['sis_id' => 'integer'];

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
