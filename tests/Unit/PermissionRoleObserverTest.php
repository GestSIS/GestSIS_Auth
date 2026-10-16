<?php

namespace Tests\Unit;

use App\Exceptions\CrossDomainePermissionException;
use App\Models\Domaine;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\Sis;
use Tests\TestCase;

class PermissionRoleObserverTest extends TestCase
{
    public function testAttachingAnEcaPermissionToASisRoleThrows(): void
    {
        $ecaDomaineId = Domaine::where('code', 'eca')->firstOrFail()->id;
        $ecaPermission = Permission::create(['nom' => 'Cours validation', 'description' => '', 'api_key' => 'cours.validation', 'tri' => 1]);
        // domaine_id n'est volontairement pas fillable (Task 3) : affectation directe.
        $ecaPermission->domaine_id = $ecaDomaineId;
        $ecaPermission->save();
        $sisRole = Role::create(['nom' => 'Rôle SIS', 'organisation_id' => Sis::factory()->create()->id]);

        $this->expectException(CrossDomainePermissionException::class);
        PermissionRole::create(['permission_id' => $ecaPermission->id, 'role_id' => $sisRole->id]);
    }

    public function testAttachingADomainlessPermissionToAnyRoleSucceeds(): void
    {
        $sisRole = Role::create(['nom' => 'Rôle SIS', 'organisation_id' => Sis::factory()->create()->id]);
        $utilisateurConfig = Permission::where('api_key', 'utilisateur.config')->firstOrFail();

        PermissionRole::create(['permission_id' => $utilisateurConfig->id, 'role_id' => $sisRole->id]);

        $this->assertDatabaseHas('permission_roles', ['permission_id' => $utilisateurConfig->id, 'role_id' => $sisRole->id]);
    }

    /**
     * `RoleController::store()`/`update()` -- the real HTTP path that assigns
     * permissions to a role -- never calls `PermissionRole::create()`
     * directly; it goes through `$role->permissions()->attach()`/`->sync()`.
     * Without `Role::permissions()` declaring `->using(PermissionRole::class)`,
     * Eloquent's `attach()` inserts the pivot row via a raw query builder
     * insert and never instantiates `PermissionRole`, so this observer's
     * `creating` event never fires on that path -- the cross-domaine guard
     * above would be bypassed by every real caller.
     */
    public function testAttachingAnEcaPermissionToASisRoleThroughTheRelationThrows(): void
    {
        $ecaDomaineId = Domaine::where('code', 'eca')->firstOrFail()->id;
        $ecaPermission = Permission::create(['nom' => 'Cours validation 2', 'description' => '', 'api_key' => 'cours.validation2', 'tri' => 2]);
        $ecaPermission->domaine_id = $ecaDomaineId;
        $ecaPermission->save();
        $sisRole = Role::create(['nom' => 'Rôle SIS 2', 'organisation_id' => Sis::factory()->create()->id]);

        $this->expectException(CrossDomainePermissionException::class);
        $sisRole->permissions()->attach($ecaPermission->id);
    }
}
