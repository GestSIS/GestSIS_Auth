<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\User;
use Tests\TestCase;

class ApiLogoutControllerTest extends TestCase
{
    public function testLogoutDeletesTheGivenRefreshToken(): void
    {
        $user = User::factory()->create();
        $token = TokenTools::createRefreshToken();
        $row = $user->refreshTokens()->create([
            'token' => TokenTools::hashToken($token->token),
            'expire' => $token->expire,
        ]);

        $response = $this->postJson('/api/v1/logout', ['token' => $token->token]);

        $response->assertNoContent();
        $this->assertDatabaseMissing('refresh_tokens', ['id' => $row->id]);
    }

    public function testLoggingOutAnUnknownTokenStillSucceeds(): void
    {
        $response = $this->postJson('/api/v1/logout', ['token' => 'does-not-exist']);

        $response->assertNoContent();
    }

    public function testLogoutDoesNotAffectOtherSessions(): void
    {
        $user = User::factory()->create();
        $token1 = TokenTools::createRefreshToken();
        $token2 = TokenTools::createRefreshToken();
        $user->refreshTokens()->create(['token' => TokenTools::hashToken($token1->token), 'expire' => $token1->expire]);
        $other = $user->refreshTokens()->create(['token' => TokenTools::hashToken($token2->token), 'expire' => $token2->expire]);

        $this->postJson('/api/v1/logout', ['token' => $token1->token])->assertNoContent();

        $this->assertDatabaseHas('refresh_tokens', ['id' => $other->id]);
    }

    /**
     * Un client peut détenir un jeton déjà tourné (réponse de refresh perdue) :
     * le logout doit aussi révoquer son successeur, pas seulement la ligne reçue.
     */
    public function testLogoutWithAnAlreadyRotatedTokenRevokesTheWholeSession(): void
    {
        $user = User::factory()->create();
        $token = TokenTools::createRefreshToken();
        $user->refreshTokens()->create([
            'token' => TokenTools::hashToken($token->token),
            'expire' => $token->expire,
            'family_id' => $token->familyId,
        ]);
        $successor = $this->postJson('/api/v1/refresh-token', ['token' => $token->token])->json('data.refreshToken');

        $this->postJson('/api/v1/logout', ['token' => $token->token])->assertNoContent();

        $this->assertDatabaseMissing('refresh_tokens', ['token' => TokenTools::hashToken($successor)]);
    }
}
