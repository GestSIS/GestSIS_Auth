<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Role;
use App\Models\Sis;
use App\Models\User;
use Tests\TestCase;

class UserRoleControllerOrganisationTest extends TestCase
{
    public function testAssignRoleToUserStillWorksWithSisKeyHeaderUnchanged(): void
    {
        $sis = Sis::factory()->create(['api_key' => 'test-userrole-ctrl']);
        $role = Role::create(['nom' => 'Rôle', 'organisation_id' => $sis->id]);
        $targetUser = User::factory()->create();
        $manager = User::factory()->create();
        $token = TokenTools::createAccessToken($manager, ['test-userrole-ctrl' => ['utilisateur.modification']], [], []);

        $response = $this->withHeaders(['Sis-Key' => 'test-userrole-ctrl', 'Authorization' => "Bearer $token"])
            ->postJson("/api/v1/roles/{$role->id}/users", ['user_id' => (string) $targetUser->id]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('user_roles', ['user_id' => $targetUser->id, 'role_id' => $role->id]);
    }
}
