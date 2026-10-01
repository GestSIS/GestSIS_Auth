<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Auth\RefreshTokenCodec;
use App\Models\LegacyRefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Durée maximale d'une session depuis le login (30 jours par défaut, 90 pour
 * certains comptes réglés par un admin), et purge des sessions terminées.
 */
class SessionLifetimeTest extends TestCase
{
    private function adminHeaders(): array
    {
        $admin = User::factory()->create(['admin' => true]);

        return ['Authorization' => 'Bearer ' . TokenTools::createAccessToken($admin, [], [], [], true, sessionId: 'session-admin')];
    }

    /**
     * Access token court (60 min) : une révocation de session prend effet au
     * plus tard à son expiration, le refresh renouvelant l'accès entre-temps.
     */
    public function testTheAccessTokenIssuedAtLoginAndRefreshLastsSixtyMinutes(): void
    {
        $user = User::factory()->create();
        $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->json('data');
        $refreshed = $this->postJson('/api/v1/refresh-token', ['token' => $login['refreshToken']])->json('data');

        foreach ([$login['accessToken'], $refreshed['accessToken']] as $accessToken) {
            $payload = json_decode(base64_decode(strtr(explode('.', $accessToken)[1], '-_', '+/')));
            $this->assertSame(3600, $payload->exp - $payload->iat);
        }
    }

    public function testASessionOlderThanThirtyDaysIsRefusedAtRefreshAndRevoked(): void
    {
        $user = User::factory()->create();
        [$plainToken, $row] = $this->issueSession($user, ['started_at' => now()->subDays(31)]);

        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Session expirée, veuillez vous reconnecter');
        $this->assertDatabaseMissing('auth_sessions', ['id' => $row->id]);
    }

    public function testAnAccountSetToNinetyDaysKeepsItsSessionBeyondThirtyDays(): void
    {
        $user = User::factory()->create(['session_max_days' => 90]);
        [$recentToken] = $this->issueSession($user, ['started_at' => now()->subDays(40)]);
        [$tooOldToken] = $this->issueSession($user, ['started_at' => now()->subDays(91)]);

        $this->postJson('/api/v1/refresh-token', ['token' => $recentToken])->assertOk();
        $this->postJson('/api/v1/refresh-token', ['token' => $tooOldToken])->assertStatus(401);
    }

    /**
     * La rotation recopie la date de login (« Connecté le » exact même après
     * purge des anciennes lignes) et le nouveau jeton ne dépasse jamais la fin
     * absolue de la session.
     */
    public function testRotationKeepsTheSessionStartAndCapsTheNewTokenAtTheDeadline(): void
    {
        $user = User::factory()->create();
        [$plainToken, $row] = $this->issueSession($user, ['started_at' => now()->subDays(25)]);

        $newPlainToken = $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])
            ->assertOk()
            ->json('data.refreshToken');

        $claims = app(RefreshTokenCodec::class)->decode($newPlainToken);
        $this->assertTrue($row->fresh()->started_at->equalTo($row->started_at));
        $this->assertLessThanOrEqual($row->started_at->copy()->addDays(30)->getTimestamp(), $claims['exp']);
    }

    public function testTheSessionsListShowsTheLoginTimeAfterARefresh(): void
    {
        $user = User::factory()->create();
        $this->travelTo(now()->subHours(2));
        $current = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->json('data');
        $this->travelBack();
        $refreshed = $this->postJson('/api/v1/refresh-token', ['token' => $current['refreshToken']])->json('data');

        $listed = $this->withHeaders(['Authorization' => 'Bearer ' . $refreshed['accessToken']])
            ->getJson('/api/v1/sessions')
            ->assertOk()
            ->json('data.0');

        $this->assertTrue(now()->subMinutes(110)->gt($listed['created_at']));
        $this->assertTrue(now()->subMinute()->lt($listed['last_used_at']));
    }

    /**
     * Une session terminée (inactivité ou durée maximale) est supprimée, et
     * avec elle son IP et son navigateur : ils ne sont stockés que là.
     */
    public function testPruneDeletesFinishedSessionsAndKeepsActiveOnes(): void
    {
        $user = User::factory()->create();
        $tablet = User::factory()->create(['session_max_days' => 90]);
        [, $active] = $this->issueSession($user);
        [, $idleExpired] = $this->issueSession($user, ['idle_expires_at' => now()->subMinute()]);
        [, $tooOld] = $this->issueSession($user, ['started_at' => now()->subDays(31)]);
        [, $tabletSession] = $this->issueSession($tablet, ['started_at' => now()->subDays(40)]);
        $expiredLegacy = LegacyRefreshToken::create(['token' => 'hash', 'expire' => now()->subDay(), 'user_id' => $user->id]);

        $this->artisan('sessions:prune')->assertSuccessful();

        $this->assertDatabaseHas('auth_sessions', ['id' => $active->id]);
        $this->assertDatabaseMissing('auth_sessions', ['id' => $idleExpired->id]);
        $this->assertDatabaseMissing('auth_sessions', ['id' => $tooOld->id]);
        $this->assertDatabaseHas('auth_sessions', ['id' => $tabletSession->id]);
        $this->assertDatabaseMissing('refresh_tokens', ['id' => $expiredLegacy->id]);
    }

    public function testAnAdminCanSetTheSessionLifetimeToNinetyDaysAndBackToTheDefault(): void
    {
        $headers = $this->adminHeaders();
        $user = User::factory()->create();

        $this->withHeaders($headers)->putJson("/api/v1/admin/users/{$user->id}/session-policy", ['max_days' => 90])
            ->assertOk()
            ->assertJsonPath('data.session_max_days', 90);
        $this->assertNotNull($user->fresh()->session_max_days_set_by);

        $this->withHeaders($headers)->putJson("/api/v1/admin/users/{$user->id}/session-policy", ['max_days' => 30])
            ->assertOk();
        $this->assertNull($user->fresh()->session_max_days);
        $this->assertNull($user->fresh()->session_max_days_set_by);

        $this->withHeaders($headers)->putJson("/api/v1/admin/users/{$user->id}/session-policy", ['max_days' => 365])
            ->assertStatus(422);
    }

    public function testANonAdminCannotChangeASessionPolicyOrRevokeSessions(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer ' . TokenTools::createAccessToken($user, [], [], [], sessionId: 'session')];

        $this->withHeaders($headers)->putJson("/api/v1/admin/users/{$user->id}/session-policy", ['max_days' => 90])
            ->assertStatus(401);
        $this->withHeaders($headers)->deleteJson("/api/v1/admin/users/{$user->id}/sessions")
            ->assertStatus(401);
        $this->assertNull($user->fresh()->session_max_days);
    }

    public function testAnAdminCanDisconnectAllDevicesOfAnAccountAndItIsLogged(): void
    {
        $log = Log::spy();
        $headers = $this->adminHeaders();
        $user = User::factory()->create();
        [$plainToken] = $this->issueSession($user);
        $this->issueSession($user);

        $this->withHeaders($headers)->deleteJson("/api/v1/admin/users/{$user->id}/sessions")->assertNoContent();

        $this->assertSame(0, $user->authSessions()->count());
        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertStatus(401);
        $log->shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context = []) => $message === 'All sessions revoked by admin'
                && $context['user_id'] === $user->id
        )->once();
    }

    /**
     * Réglage admin-only : absent des réponses qui ne sont pas admin.
     */
    public function testTheSessionPolicyIsNotExposedOutsideAdminEndpoints(): void
    {
        $user = User::factory()->create(['session_max_days' => 90]);

        $this->assertArrayNotHasKey('session_max_days', $user->toArray());
    }
}
