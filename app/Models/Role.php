<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'nom',
        'description',
        'sis_id'
    ];

    protected function casts(): array
    {
        return ['sis_id' => 'integer'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function registerTokens(): BelongsToMany
    {
        return $this->belongsToMany(RegisterToken::class, 'register_token_roles');
    }

    public function permissionRoles(): HasMany
    {
        return $this->hasMany(PermissionRole::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_roles');
    }

    public function sis(): BelongsTo
    {
        return $this->belongsTo(Sis::class);
    }
}
