<?php

namespace App\Observers;

use App\Exceptions\CrossDomainePermissionException;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;

class PermissionRoleObserver
{
    public function creating(PermissionRole $permissionRole): void
    {
        $permission = Permission::findOrFail($permissionRole->permission_id);
        if ($permission->domaine_id === null) {
            return;
        }

        $role = Role::findOrFail($permissionRole->role_id);
        if ($role->organisation->domaine_id !== $permission->domaine_id) {
            throw new CrossDomainePermissionException(
                "Permission {$permission->api_key} (domaine {$permission->domaine_id}) ne peut pas être attribuée au rôle {$role->id} (domaine {$role->organisation->domaine_id})."
            );
        }
    }
}
