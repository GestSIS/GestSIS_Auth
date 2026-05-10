<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\ApiToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sis;
use App\Models\User;
use App\Models\UserRole;
use Carbon\Carbon;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{

    /**
     * Test creating an API token with valid permissions.
     */
    public function testCreateApiTokenWithValidPermissions(): void
    {
        // Create a user with permissions
        $user = User::factory()->create();
        $permission = Permission::first();

        if (!$permission) {
            $this->markTestSkipped('No permissions in database');
        }

        // Create SIS and assign permission to user
        $sis = Sis::firstOrCreate(
            ['api_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST']
        );
        $role = Role::create(['nom' => 'Test Role', 'sis_id' => $sis->id]);
        $role->permissions()->attach($permission->id);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $bearerToken = TokenTools::createAccessToken($user, ['test' => [$permission->api_key]], [], []);

        $params = [
            'name' => 'Test API Token',
            'description' => 'Token for testing purposes',
            'expires_in_days' => 90,
            'permission_ids' => [$permission->id],
            'sis_ids' => [$sis->id],
        ];

        $response = $this->withHeaders([
            'Sis-Key' => 'test',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->postJson('/api/v1/api-tokens', $params);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'token',
            'token_info' => [
                'id',
                'name',
                'description',
                'expires_at',
                'permissions',
            ],
        ]);

        // Verify token was created in database
        $this->assertDatabaseHas('api_tokens', [
            'user_id' => $user->id,
            'name' => 'Test API Token',
            'description' => 'Token for testing purposes',
        ]);
    }

    /**
     * Test that duplicate token names are rejected.
     */
    public function testCannotCreateDuplicateTokenName(): void
    {
        $user = User::factory()->create();
        $permission = Permission::first();

        $sis = Sis::firstOrCreate(
            ['api_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST']
        );
        $role = Role::create(['nom' => 'Test Role', 'sis_id' => $sis->id]);
        $role->permissions()->attach($permission->id);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $bearerToken = TokenTools::createAccessToken($user, ['test' => [$permission->api_key]], [], []);

        // Create first token
        $params = [
            'name' => 'My Token',
            'expires_in_days' => 30,
            'permission_ids' => [$permission->id],
            'sis_ids' => [$sis->id],
        ];

        $this->withHeaders([
            'Sis-Key' => 'test',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->postJson('/api/v1/api-tokens', $params);

        // Try to create second token with same name
        $response = $this->withHeaders([
            'Sis-Key' => 'test',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->postJson('/api/v1/api-tokens', $params);

        $response->assertStatus(422);
        $response->assertJsonPath('error.name.0', 'Un jeton avec ce nom existe déjà.');
    }

    /**
     * Test exchanging API token for JWT.
     */
    public function testExchangeApiTokenForJwt(): void
    {
        $user = User::factory()->create();
        $permission = Permission::first();

        $sis = Sis::firstOrCreate(
            ['api_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST']
        );
        $role = Role::create(['nom' => 'Test Role', 'sis_id' => $sis->id]);
        $role->permissions()->attach($permission->id);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        // Create an API token directly
        $tokenData = TokenTools::createApiToken(30);
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Test Token',
            'token' => TokenTools::hashToken($tokenData->token),
            'expires_at' => $tokenData->expire,
        ]);
        $apiToken->permissions()->attach([$permission->id]);

        // Exchange token for JWT
        $response = $this->postJson('/api/v1/token-auth', [
            'token' => $tokenData->token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'accessToken',
            'user',
        ]);

        // Verify last_used_at was updated
        $apiToken->refresh();
        $this->assertNotNull($apiToken->last_used_at);
    }

    /**
     * Test that token is rejected when user loses ANY permission.
     */
    public function testTokenRejectedWhenUserLosesPermission(): void
    {
        $user = User::factory()->create();
        $permission1 = Permission::first();
        $permission2 = Permission::skip(1)->first();

        if (!$permission1 || !$permission2) {
            $this->markTestSkipped('Not enough permissions in database');
        }

        $sis = Sis::firstOrCreate(
            ['api_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST']
        );
        $role = Role::create(['nom' => 'Test Role', 'sis_id' => $sis->id]);
        $role->permissions()->attach([$permission1->id, $permission2->id]);
        $userRole = UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        // Create an API token with both permissions
        $tokenData = TokenTools::createApiToken(30);
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Test Token',
            'token' => TokenTools::hashToken($tokenData->token),
            'expires_at' => $tokenData->expire,
        ]);
        $apiToken->permissions()->attach([$permission1->id, $permission2->id]);

        // Verify token works initially
        $response = $this->postJson('/api/v1/token-auth', [
            'token' => $tokenData->token,
        ]);
        $response->assertStatus(200);

        // User loses access by removing their role
        $userRole->delete();

        // Try to exchange token again - should be rejected
        $response = $this->postJson('/api/v1/token-auth', [
            'token' => $tokenData->token,
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => $response->json('error')]);
        $this->assertStringContainsString("n'est plus valide", $response->json('error'));
        $this->assertStringContainsString('perdu les permissions', $response->json('error'));
    }

    /**
     * Test that expired tokens are rejected.
     */
    public function testExpiredTokenRejected(): void
    {
        $user = User::factory()->create();
        $permission = Permission::first();

        // Create an expired API token
        $tokenData = TokenTools::createApiToken(30);
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Expired Token',
            'token' => TokenTools::hashToken($tokenData->token),
            'expires_at' => Carbon::now()->subDay(), // Already expired
        ]);
        $apiToken->permissions()->attach([$permission->id]);

        // Try to exchange expired token
        $response = $this->postJson('/api/v1/token-auth', [
            'token' => $tokenData->token,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Jeton API invalide ou expiré');
    }

    /**
     * Test that invalid tokens are rejected.
     */
    public function testInvalidTokenRejected(): void
    {
        $response = $this->postJson('/api/v1/token-auth', [
            'token' => 'invalid-token-string',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Jeton API invalide ou expiré');
    }

    /**
     * Test listing user's API tokens.
     */
    public function testListUserTokens(): void
    {
        $user = User::factory()->create();
        $permission = Permission::first();

        $sis = Sis::firstOrCreate(
            ['api_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST']
        );
        $role = Role::create(['nom' => 'Test Role', 'sis_id' => $sis->id]);
        $role->permissions()->attach($permission->id);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $bearerToken = TokenTools::createAccessToken($user, ['test' => [$permission->api_key]], [], []);

        // Create a couple of tokens
        $tokenData1 = TokenTools::createApiToken(30);
        $apiToken1 = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Token 1',
            'description' => 'First token',
            'token' => TokenTools::hashToken($tokenData1->token),
            'expires_at' => $tokenData1->expire,
        ]);
        $apiToken1->permissions()->attach([$permission->id]);

        $tokenData2 = TokenTools::createApiToken(60);
        $apiToken2 = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Token 2',
            'description' => 'Second token',
            'token' => TokenTools::hashToken($tokenData2->token),
            'expires_at' => $tokenData2->expire,
        ]);
        $apiToken2->permissions()->attach([$permission->id]);

        // List tokens
        $response = $this->withHeaders([
            'Sis-Key' => 'test',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->getJson('/api/v1/api-tokens');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'tokens');
        $response->assertJsonStructure([
            'tokens' => [
                '*' => [
                    'id',
                    'name',
                    'description',
                    'created_at',
                    'expires_at',
                    'last_used_at',
                    'permissions',
                ],
            ],
        ]);

        // Verify token values are not exposed
        $tokens = $response->json('tokens');
        foreach ($tokens as $token) {
            $this->assertArrayNotHasKey('token', $token);
        }
    }

    /**
     * Test revoking an API token.
     */
    public function testRevokeApiToken(): void
    {
        $user = User::factory()->create();
        $permission = Permission::first();

        $sis = Sis::firstOrCreate(
            ['api_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST']
        );
        $role = Role::create(['nom' => 'Test Role', 'sis_id' => $sis->id]);
        $role->permissions()->attach($permission->id);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $bearerToken = TokenTools::createAccessToken($user, ['test' => [$permission->api_key]], [], []);

        // Create a token
        $tokenData = TokenTools::createApiToken(30);
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Token to Revoke',
            'token' => TokenTools::hashToken($tokenData->token),
            'expires_at' => $tokenData->expire,
        ]);
        $apiToken->permissions()->attach([$permission->id]);

        // Revoke the token
        $response = $this->withHeaders([
            'Sis-Key' => 'test',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->deleteJson("/api/v1/api-tokens/{$apiToken->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Jeton révoqué avec succès');

        // Verify token was deleted
        $this->assertDatabaseMissing('api_tokens', [
            'id' => $apiToken->id,
        ]);

        // Try to use the revoked token
        $exchangeResponse = $this->postJson('/api/v1/token-auth', [
            'token' => $tokenData->token,
        ]);

        $exchangeResponse->assertStatus(401);
    }

    /**
     * Test that users can only revoke their own tokens.
     */
    public function testCannotRevokeOtherUsersTokens(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $permission = Permission::first();

        $sis = Sis::firstOrCreate(
            ['api_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST']
        );
        $role = Role::create(['nom' => 'Test Role', 'sis_id' => $sis->id]);
        $role->permissions()->attach($permission->id);
        UserRole::create(['user_id' => $user1->id, 'role_id' => $role->id]);

        $bearerToken = TokenTools::createAccessToken($user1, ['test' => [$permission->api_key]], [], []);

        // Create a token for user2
        $tokenData = TokenTools::createApiToken(30);
        $apiToken = ApiToken::create([
            'user_id' => $user2->id,
            'name' => 'User2 Token',
            'token' => TokenTools::hashToken($tokenData->token),
            'expires_at' => $tokenData->expire,
        ]);
        $apiToken->permissions()->attach([$permission->id]);

        // Try to revoke user2's token as user1
        $response = $this->withHeaders([
            'Sis-Key' => 'test',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->deleteJson("/api/v1/api-tokens/{$apiToken->id}");

        $response->assertStatus(404);

        // Verify token still exists
        $this->assertDatabaseHas('api_tokens', [
            'id' => $apiToken->id,
        ]);
    }

    /**
     * Test that non-admins must specify at least one SIS.
     */
    public function testNonAdminsMustSpecifySis(): void
    {
        $user = User::factory()->create();
        $permission = Permission::first();

        $sis = Sis::firstOrCreate(
            ['api_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST']
        );
        $role = Role::create(['nom' => 'Test Role', 'sis_id' => $sis->id]);
        $role->permissions()->attach($permission->id);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $bearerToken = TokenTools::createAccessToken($user, ['test' => [$permission->api_key]], [], []);

        // Try to create token without sis_ids
        $params = [
            'name' => 'Test Token',
            'expires_in_days' => 30,
            'permission_ids' => [$permission->id],
        ];

        $response = $this->withHeaders([
            'Sis-Key' => 'test',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->postJson('/api/v1/api-tokens', $params);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'Vous devez spécifier au moins un SIS pour ce jeton'
        ]);
    }

    /**
     * Test that user cannot create token with permissions they don't have in a specific SIS.
     */
    public function testCannotCreateTokenWithMissingPermissionsInSis(): void
    {
        $user = User::factory()->create();
        $permission1 = Permission::first();
        $permission2 = Permission::skip(1)->first();

        if (!$permission1 || !$permission2) {
            $this->markTestSkipped('Not enough permissions in database');
        }

        // Create two SIS
        $sisA = Sis::firstOrCreate(
            ['api_key' => 'sis_a'],
            ['nom' => 'SIS A', 'abreviation' => 'SA']
        );
        $sisB = Sis::firstOrCreate(
            ['api_key' => 'sis_b'],
            ['nom' => 'SIS B', 'abreviation' => 'SB']
        );

        // User has permission1 in SIS A
        $roleA = Role::create(['nom' => 'Role A', 'sis_id' => $sisA->id]);
        $roleA->permissions()->attach($permission1->id);
        UserRole::create(['user_id' => $user->id, 'role_id' => $roleA->id]);

        // User has permission1 in SIS B, but NOT permission2
        $roleB = Role::create(['nom' => 'Role B', 'sis_id' => $sisB->id]);
        $roleB->permissions()->attach($permission1->id);
        UserRole::create(['user_id' => $user->id, 'role_id' => $roleB->id]);

        $bearerToken = TokenTools::createAccessToken($user, [
            'sis_a' => [$permission1->api_key],
            'sis_b' => [$permission1->api_key],
        ], [], []);

        // Try to create token with permission1 AND permission2 for SIS B
        // Should fail because user doesn't have permission2 in SIS B
        $params = [
            'name' => 'Test Token',
            'expires_in_days' => 30,
            'permission_ids' => [$permission1->id, $permission2->id],
            'sis_ids' => [$sisB->id],
        ];

        $response = $this->withHeaders([
            'Sis-Key' => 'sis_a',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->postJson('/api/v1/api-tokens', $params);

        $response->assertStatus(403);
        $response->assertJsonPath('error', function ($error) use ($permission2, $sisB) {
            return str_contains($error, $permission2->nom) && str_contains($error, $sisB->nom);
        });
    }

    /**
     * Test that user can create token with permissions they have in all requested SIS.
     */
    public function testCanCreateTokenWithValidPermissionsInMultipleSis(): void
    {
        $user = User::factory()->create();
        $permission1 = Permission::first();
        $permission2 = Permission::skip(1)->first();

        if (!$permission1 || !$permission2) {
            $this->markTestSkipped('Not enough permissions in database');
        }

        // Create two SIS
        $sisA = Sis::firstOrCreate(
            ['api_key' => 'sis_a'],
            ['nom' => 'SIS A', 'abreviation' => 'SA']
        );
        $sisB = Sis::firstOrCreate(
            ['api_key' => 'sis_b'],
            ['nom' => 'SIS B', 'abreviation' => 'SB']
        );

        // User has both permissions in both SIS
        $roleA = Role::create(['nom' => 'Role A', 'sis_id' => $sisA->id]);
        $roleA->permissions()->attach([$permission1->id, $permission2->id]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $roleA->id]);

        $roleB = Role::create(['nom' => 'Role B', 'sis_id' => $sisB->id]);
        $roleB->permissions()->attach([$permission1->id, $permission2->id]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $roleB->id]);

        $bearerToken = TokenTools::createAccessToken($user, [
            'sis_a' => [$permission1->api_key, $permission2->api_key],
            'sis_b' => [$permission1->api_key, $permission2->api_key],
        ], [], []);

        // Create token with both permissions for both SIS - should succeed
        $params = [
            'name' => 'Multi-SIS Token',
            'expires_in_days' => 30,
            'permission_ids' => [$permission1->id, $permission2->id],
            'sis_ids' => [$sisA->id, $sisB->id],
        ];

        $response = $this->withHeaders([
            'Sis-Key' => 'sis_a',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->postJson('/api/v1/api-tokens', $params);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'token',
            'token_info' => [
                'id',
                'name',
                'expires_at',
                'permissions',
                'allowed_sis',
            ],
        ]);

        // Verify the token has both SIS in allowed_sis
        $tokenInfo = $response->json('token_info');
        $this->assertCount(2, $tokenInfo['allowed_sis']);
    }
}
