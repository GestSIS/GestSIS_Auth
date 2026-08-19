<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Role;
use App\Models\Sis;
use App\Models\User;
use App\Models\UserRole;
use Tests\TestCase;

class SisControllerTest extends TestCase
{
    protected function adminToken(): string
    {
        $admin = User::factory()->create();
        return TokenTools::createAccessToken($admin, [], [], [], true);
    }

    public function testAdminCanShowASisWithItsUsersAndRoles(): void
    {
        $sis = Sis::create(['api_key' => 'sis-test-' . uniqid(), 'nom' => 'Test SIS', 'abreviation' => 'TST']);
        $role = Role::create(['nom' => 'Role', 'sis_id' => $sis->id]);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken(),
        ])->getJson('/api/v1/admin/sis/' . $sis->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $sis->id);
        $response->assertJsonPath('data.roles.0.id', $role->id);
        $response->assertJsonPath('data.roles.0.user_roles.0.user.id', $user->id);
    }

    public function testShowingAnUnknownSisReturnsACleanError(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken(),
        ])->getJson('/api/v1/admin/sis/999999');

        $response->assertStatus(200);
        $response->assertJsonPath('error', 'Sis inexistant');
    }
}
