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
        'organisation_id'
    ];

    protected function casts(): array
    {
        return ['organisation_id' => 'integer'];
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
        // ->using(PermissionRole::class) : sans ça, attach()/sync() (le seul
        // chemin réel, via RoleController) insèrent la ligne pivot par une
        // requête brute sans jamais instancier PermissionRole, donc sans
        // jamais déclencher PermissionRoleObserver -- la contrainte
        // cross-domaine ne serait alors vérifiée que par un appel direct
        // à PermissionRole::create(), que personne ne fait.
        return $this->belongsToMany(Permission::class, 'permission_roles')->using(PermissionRole::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
