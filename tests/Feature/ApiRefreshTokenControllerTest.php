<?php

namespace Tests\Feature;

use App\Auth\RefreshTokenCodec;
use App\Auth\TokenTools;
use App\Models\AuthSession;
use App\Models\LegacyRefreshToken;
use App\Models\TwoFactorPolicy;
use App\Models\TwoFactorMethod;
use App\Models\User;
use Tests\TestCase;

class ApiRefreshTokenControllerTest extends TestCase
{
    public function testRefreshingRotatesTheTokenWithinTheSameSession(): void
    {
        $user = User::factory()->create();
        [$plainToken, $session] = $this->issueSession($user);

        $response = $this->postJson('/api/v1/refresh-token', ['token' => $plainToken]);

        $response->assertOk();
        $newPlainToken = $response->json('data.refreshToken');
        $this->assertNotSame($plainToken, $newPlainToken);
        $this->assertSame(1, $session->fresh()->refresh_generation);
        $this->assertSame($session->id, app(RefreshTokenCodec::class)->decode($newPlainToken)['sid']);
    }

    /**
     * Session ouverte avant l'échéance d'obligation 2FA : le refresh ne doit
     * pas la prolonger indéfiniment sans second facteur.
     */
    public function testRefreshIsRefusedAndSessionsRevokedOnceTwoFactorSetupIsRequired(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create();
        [$plainToken] = $this->issueSession($user);
        $this->issueSession($user);

        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertStatus(401);
        $this->assertSame(0, $user->authSessions()->count());
    }

    public function testRefreshStillWorksUnderEnforcementForProtectedOrExemptAccounts(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $protected = User::factory()->has(TwoFactorMethod::factory(), 'twoFactorMethods')->create();
        $exempt = User::factory()->create(['two_factor_exempt' => true]);
        [$protectedToken] = $this->issueSession($protected);
        [$exemptToken] = $this->issueSession($exempt);

        $this->postJson('/api/v1/refresh-token', ['token' => $protectedToken])->assertOk();
        $this->postJson('/api/v1/refresh-token', ['token' => $exemptToken])->assertOk();
    }

    public function testRefreshIsRefusedOnceAnExemptionHasExpired(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create(['two_factor_exempt' => true, 'two_factor_exempt_until' => now()->subHour()]);
        [$plainToken] = $this->issueSession($user);

        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertStatus(401);
    }

    public function testRefreshingAnUnknownTokenIsRejected(): void
    {
        $this->postJson('/api/v1/refresh-token', ['token' => 'does-not-exist'])->assertStatus(401);
    }

    /**
     * Jeton fabriqué ou modifié (signature invalide) : refusé, et surtout sans
     * révoquer la session qu'il vise — sinon n'importe qui connaissant un id
     * de session pourrait déconnecter son propriétaire.
     */
    public function testAForgedTokenIsRejectedWithoutRevokingTheTargetedSession(): void
    {
        $user = User::factory()->create();
        [$plainToken, $session] = $this->issueSession($user);
        [$payload] = explode('.', $plainToken);

        $this->postJson('/api/v1/refresh-token', ['token' => $payload . '.signature-inventee'])->assertStatus(401);

        $this->assertDatabaseHas('auth_sessions', ['id' => $session->id]);
        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertOk();
    }

    public function testReplayingAnAlreadyRotatedTokenWithinTheGracePeriodReturnsTheSameResponse(): void
    {
        $user = User::factory()->create();
        [$plainToken] = $this->issueSession($user);

        $first = $this->postJson('/api/v1/refresh-token', ['token' => $plainToken]);
        $first->assertOk();

        // Deuxième onglet qui présente le même (ancien) token quasi en même
        // temps : ne doit pas être traité comme un vol, doit recevoir la même
        // réponse que la première requête.
        $second = $this->postJson('/api/v1/refresh-token', ['token' => $plainToken]);

        $second->assertOk();
        $this->assertSame($first->json('data.accessToken'), $second->json('data.accessToken'));
        $this->assertSame($first->json('data.refreshToken'), $second->json('data.refreshToken'));
    }

    public function testReplayingAnAlreadyRotatedTokenAfterTheGracePeriodRevokesTheSession(): void
    {
        $user = User::factory()->create();
        [$plainToken, $session] = $this->issueSession($user);
        $successor = $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertOk()->json('data.refreshToken');

        // Au-delà de la fenêtre de grâce (le cache l'a expirée) : ce n'est
        // plus une course entre onglets, c'est traité comme un vol réel.
        $this->travel(11)->seconds();

        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertStatus(401);
        $this->assertDatabaseMissing('auth_sessions', ['id' => $session->id]);
        $this->postJson('/api/v1/refresh-token', ['token' => $successor])->assertStatus(401);
    }

    public function testAnIdleExpiredSessionIsRejected(): void
    {
        $user = User::factory()->create();
        [$plainToken] = $this->issueSession($user, ['idle_expires_at' => now()->subDay()]);

        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertStatus(401);
    }

    public function testANotRememberedSessionStaysShortLived(): void
    {
        $user = User::factory()->create();
        [$plainToken, $session] = $this->issueSession($user, ['remember' => false, 'idle_expires_at' => now()->addDay()]);

        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertOk();

        $this->assertFalse($session->fresh()->remember);
        $this->assertTrue($session->fresh()->idle_expires_at->lessThan(now()->addDays(2)));
    }

    /**
     * Reprise d'une session ouverte avant cette version (ancien jeton
     * aléatoire, stocké haché) : échangé une fois contre une session signée,
     * sans déconnexion.
     */
    public function testALegacyTokenIsExchangedOnceForASignedSession(): void
    {
        $user = User::factory()->create();
        LegacyRefreshToken::create([
            'token' => TokenTools::hashToken('ancien-jeton-aleatoire'),
            'expire' => now()->addDays(20),
            'user_id' => $user->id,
        ]);

        $response = $this->postJson('/api/v1/refresh-token', ['token' => 'ancien-jeton-aleatoire']);

        $response->assertOk();
        $this->assertSame(1, $user->authSessions()->count());
        $this->assertSame(0, $user->legacyRefreshTokens()->count());
        $this->assertNotNull(app(RefreshTokenCodec::class)->decode($response->json('data.refreshToken')));

        $this->travel(11)->seconds();
        $this->postJson('/api/v1/refresh-token', ['token' => 'ancien-jeton-aleatoire'])->assertStatus(401);
    }

    public function testAnExpiredLegacyTokenIsRejected(): void
    {
        $user = User::factory()->create();
        LegacyRefreshToken::create([
            'token' => TokenTools::hashToken('ancien-jeton-expire'),
            'expire' => now()->subDay(),
            'user_id' => $user->id,
        ]);

        $this->postJson('/api/v1/refresh-token', ['token' => 'ancien-jeton-expire'])->assertStatus(401);
        $this->assertSame(0, AuthSession::where('user_id', $user->id)->count());
    }

    /**
     * Le bandeau d'incitation 2FA doit survivre au refresh (sinon il
     * disparaissait après le premier renouvellement de l'access token).
     */
    public function testRefreshReturnsTheTwoFactorNudgeDuringTheGracePeriod(): void
    {
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->addDays(10);
        $policy->save();

        $unprotected = User::factory()->create();
        $protected = User::factory()->has(TwoFactorMethod::factory(), 'twoFactorMethods')->create();
        [$unprotectedToken] = $this->issueSession($unprotected);
        [$protectedToken] = $this->issueSession($protected);

        $this->postJson('/api/v1/refresh-token', ['token' => $unprotectedToken])
            ->assertOk()
            ->assertJsonPath('data.twoFactorNudge.daysRemaining', 9);
        $this->postJson('/api/v1/refresh-token', ['token' => $protectedToken])
            ->assertOk()
            ->assertJsonPath('data.twoFactorNudge', null);
    }
}
