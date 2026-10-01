<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\LegacyRefreshToken;
use App\Models\User;
use Tests\TestCase;

class ApiLogoutControllerTest extends TestCase
{
    public function testLogoutDeletesTheSessionOfTheGivenRefreshToken(): void
    {
        $user = User::factory()->create();
        [$plainToken, $session] = $this->issueSession($user);

        $this->postJson('/api/v1/logout', ['token' => $plainToken])->assertNoContent();

        $this->assertDatabaseMissing('auth_sessions', ['id' => $session->id]);
    }

    public function testLoggingOutAnUnknownTokenStillSucceeds(): void
    {
        $this->postJson('/api/v1/logout', ['token' => 'does-not-exist'])->assertNoContent();
        $this->postJson('/api/v1/logout', ['token' => 'faux.jeton'])->assertNoContent();
    }

    public function testLogoutDoesNotAffectOtherSessions(): void
    {
        $user = User::factory()->create();
        [$plainToken] = $this->issueSession($user);
        [, $other] = $this->issueSession($user);

        $this->postJson('/api/v1/logout', ['token' => $plainToken])->assertNoContent();

        $this->assertDatabaseHas('auth_sessions', ['id' => $other->id]);
    }

    /**
     * Un client peut détenir un jeton déjà renouvelé (réponse de refresh
     * perdue) : le logout coupe quand même la session, successeur compris.
     */
    public function testLogoutWithAnAlreadyRotatedTokenRevokesTheWholeSession(): void
    {
        $user = User::factory()->create();
        [$plainToken, $session] = $this->issueSession($user);
        $successor = $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->json('data.refreshToken');

        $this->postJson('/api/v1/logout', ['token' => $plainToken])->assertNoContent();

        $this->assertDatabaseMissing('auth_sessions', ['id' => $session->id]);
        $this->postJson('/api/v1/refresh-token', ['token' => $successor])->assertStatus(401);
    }

    public function testLogoutAlsoAcceptsALegacyRefreshToken(): void
    {
        $user = User::factory()->create();
        $legacy = LegacyRefreshToken::create([
            'token' => TokenTools::hashToken('ancien-jeton'),
            'expire' => now()->addDays(10),
            'user_id' => $user->id,
        ]);

        $this->postJson('/api/v1/logout', ['token' => 'ancien-jeton'])->assertNoContent();

        $this->assertDatabaseMissing('refresh_tokens', ['id' => $legacy->id]);
    }
}
