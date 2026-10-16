<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Organisation;
use App\Models\User;
use Tests\TestCase;

class RoleControllerOrganisationTest extends TestCase
{
    public function testStoreRoleStillWorksWithSisKeyHeaderUnchanged(): void
    {
        $sis = Organisation::factory()->create(['org_key' => 'test-role-ctrl']);
        $user = User::factory()->create();
        $token = TokenTools::createAccessToken($user, ['test-role-ctrl' => ['utilisateur.config']], [], []);

        $response = $this->withHeaders(['Sis-Key' => 'test-role-ctrl', 'Authorization' => "Bearer $token"])
            ->postJson('/api/v1/roles', ['nom' => 'Nouveau rôle', 'description' => 'Rôle de test', 'organisation_id' => $sis->id]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('roles', ['nom' => 'Nouveau rôle', 'organisation_id' => $sis->id]);
    }
}
