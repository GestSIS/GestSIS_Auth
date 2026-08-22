<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\User;
use Tests\TestCase;

class MeTest extends TestCase
{
    public function testValidTokenReturnsCurrentUser(): void
    {
        $user = User::factory()->create();
        $bearerToken = TokenTools::createAccessToken($user, [], [], []);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->getJson('/api/v1/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $user->id);
    }

    public function testMissingTokenIsRejected(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Accès refusé');
    }

    public function testInvalidTokenIsRejected(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token-string',
        ])->getJson('/api/v1/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Accès refusé');
    }
}
