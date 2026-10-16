<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\Organisation;
use App\Models\User;
use App\Models\UserRole;
use Tests\TestCase;

class UserGetPermissionsTest extends TestCase
{
    public function testGetPermissionsKeyedByOrganisationCodeExterneMatchesPreviousSisApiKeyBehaviour(): void
    {
        $sis = Organisation::factory()->create(['org_key' => 'test-perm']);
        $role = Role::create(['nom' => 'Admin', 'organisation_id' => $sis->id]);
        $permission = Permission::where('api_key', 'sapeur.lecture')->firstOrFail();
        PermissionRole::create(['permission_id' => $permission->id, 'role_id' => $role->id]);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $result = User::getPermissions($user->id);

        $this->assertSame(['sapeur.lecture'], $result['test-perm']);
    }
}
