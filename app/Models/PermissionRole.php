<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class PermissionRole extends Pivot
{
    // `permission_roles` a un vrai id auto-incrémenté (contrairement au
    // défaut de Pivot) : nécessaire pour que Role::permissions()->using(...)
    // fonctionne (Pivot implémente fromRawAttributes(), requis par
    // attach()/sync(), ce qu'un Model nu ne fournit pas).
    public $incrementing = true;

    // Explicite : AsPivot::getTable() ne devine le nom de table ("permission_role",
    // singulier) que lorsque $table n'est pas déjà posé -- ce qui casse tout usage
    // direct de ce modèle (DBCustomSeed.php, Role::permissionRoles()) qui n'instancie
    // pas via la relation belongsToMany (seul chemin qui pose $table explicitement).
    protected $table = 'permission_roles';

    protected $fillable = [
        'permission_id',
        'role_id',
    ];

    protected function casts(): array
    {
        return ['permission_id' => 'integer', 'role_id' => 'integer'];
    }
}
