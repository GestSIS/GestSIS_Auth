<?php

namespace App;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Carbon\Carbon;
use App\Role;
use App\UserRole;

class User extends Authenticatable
{
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function refreshTokens()
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    public function getActiveRefreshToken()
    {
        return $this->refreshTokens()->where('expire', '>', Carbon::now())->first();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }
}
