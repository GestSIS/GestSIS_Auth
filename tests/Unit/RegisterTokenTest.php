<?php

namespace Tests\Unit;

use App\Auth\TokenTools;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sis;
use App\Models\User;
use App\Models\UserRole;
use Tests\TestCase;

class RegisterTokenTest extends TestCase
{

    /**
     * A basic test example.
     *
     * @return void
     */
    public function testCreateRegisterTokenWithValidPermissions(): void
    {
        // Create user with necessary permissions
        $user = User::factory()->create();

        // Get or create the 'utilisateur.tout' permission
        $permission = Permission::where('api_key', 'utilisateur.tout')->first();

        // Create a SIS
        $sis = Sis::firstOrCreate(
            ['api_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST']
        );

        // Create a role with the necessary permission
        $userRole = Role::create([
            'nom' => 'Admin Role',
            'sis_id' => $sis->id
        ]);
        $userRole->permissions()->attach($permission->id);
        UserRole::create(['user_id' => $user->id, 'role_id' => $userRole->id]);

        // Create a role to assign via register token
        $targetRole = Role::create([
            'nom' => 'Target Role',
            'sis_id' => $sis->id
        ]);

        $bearerToken = TokenTools::createAccessToken($user, ['test' => ['utilisateur.tout']], [], []);
        $params = [
            'roles' => [$targetRole->id]
        ];

        $response = $this->withHeaders([
            'Sis-Key' => 'test',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->post("api/v1/register-token/", $params);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }
}
