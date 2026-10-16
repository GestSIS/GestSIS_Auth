<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Role;
use App\Models\Sis;
use App\Models\User;
use Tests\TestCase;

class AdminRoleControllerTest extends TestCase
{
    protected function adminToken(): string
    {
        $admin = User::factory()->create(['admin' => true]);
        return TokenTools::createAccessToken($admin, [], [], [], true);
    }

    public function testUpdateMovesRoleToTheProvidedOrganisation(): void
    {
        $originalSis = Sis::factory()->create();
        $newSis = Sis::factory()->create();
        $role = Role::create(['nom' => 'Role', 'description' => 'Un rôle', 'organisation_id' => $originalSis->id]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken()])
            ->putJson("/api/v1/admin/roles/{$role->id}", [
                'nom' => 'Role',
                'description' => 'Un rôle',
                'organisation_id' => $newSis->id,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'organisation_id' => $newSis->id]);
    }
}
