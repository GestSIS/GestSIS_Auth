<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Role;
use App\Models\Sis;
use App\Models\User;
use App\Models\UserRole;
use Tests\TestCase;

class AdminUserRoleControllerTest extends TestCase
{
    protected function adminToken(): string
    {
        $admin = User::factory()->create();
        return TokenTools::createAccessToken($admin, [], [], [], true);
    }

    public function testAdminCanAddARoleToAUser(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create();
        $role = Role::create(['nom' => 'Role', 'sis_id' => $sis->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken(),
        ])->postJson('/api/v1/admin/user-roles', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'role_id' => $role->id]);
    }

    public function testAddingADuplicateRoleReturnsACleanValidationErrorInsteadOfCrashing(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create();
        $role = Role::create(['nom' => 'Role', 'sis_id' => $sis->id]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken(),
        ])->postJson('/api/v1/admin/user-roles', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.role_id.0', 'Cet utilisateur a déjà ce rôle.');
        $this->assertSame(1, UserRole::where('user_id', $user->id)->where('role_id', $role->id)->count());
    }
}
