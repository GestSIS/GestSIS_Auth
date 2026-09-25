<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\RefreshToken;
use App\Models\TwoFactorPolicy;
use App\Models\User;
use Tests\TestCase;

class ApiRefreshTokenControllerTest extends TestCase
{
    private function issueRefreshToken(User $user, bool $remember = true): array
    {
        $familyId = (string) \Illuminate\Support\Str::uuid();
        $token = TokenTools::createRefreshToken($familyId, $remember);
        $row = $user->refreshTokens()->create([
            'token' => TokenTools::hashToken($token->token),
            'expire' => $token->expire,
            'family_id' => $familyId,
            'remember' => $remember,
        ]);

        return [$token->token, $row];
    }

    public function testRefreshingRotatesTheTokenAndKeepsTheSameFamily(): void
    {
        $user = User::factory()->create();
        [$plainToken, $row] = $this->issueRefreshToken($user);

        $response = $this->postJson('/api/v1/refresh-token', ['token' => $plainToken]);

        $response->assertOk();
        $newPlainToken = $response->json('data.refreshToken');
        $this->assertNotSame($plainToken, $newPlainToken);

        $this->assertNotNull($row->fresh()->used_at);
        $newRow = RefreshToken::where('token', TokenTools::hashToken($newPlainToken))->first();
        $this->assertSame($row->family_id, $newRow->family_id);
    }

    /**
     * Session ouverte avant l'échéance d'obligation 2FA : la rotation ne doit
     * pas la prolonger indéfiniment sans second facteur.
     */
    public function testRefreshIsRefusedAndSessionsRevokedOnceTwoFactorSetupIsRequired(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create();
        [$plainToken] = $this->issueRefreshToken($user);
        $this->issueRefreshToken($user);

        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertStatus(401);
        $this->assertSame(0, $user->refreshTokens()->count());
    }

    public function testRefreshStillWorksUnderEnforcementForProtectedOrExemptAccounts(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $protected = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $exempt = User::factory()->create(['two_factor_exempt' => true]);
        [$protectedToken] = $this->issueRefreshToken($protected);
        [$exemptToken] = $this->issueRefreshToken($exempt);

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
        [$plainToken] = $this->issueRefreshToken($user);

        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertStatus(401);
    }

    public function testRefreshingAnUnknownTokenIsRejected(): void
    {
        $response = $this->postJson('/api/v1/refresh-token', ['token' => 'does-not-exist']);

        $response->assertStatus(401);
    }

    public function testReplayingAnAlreadyRotatedTokenWithinTheGracePeriodReturnsTheSameResponse(): void
    {
        $user = User::factory()->create();
        [$plainToken] = $this->issueRefreshToken($user);

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

    public function testReplayingAnAlreadyRotatedTokenAfterTheGracePeriodRevokesTheWholeFamily(): void
    {
        $user = User::factory()->create();
        [$plainToken, $row] = $this->issueRefreshToken($user);

        $this->postJson('/api/v1/refresh-token', ['token' => $plainToken])->assertOk();
        $familyId = $row->family_id;
        $this->assertSame(2, RefreshToken::where('family_id', $familyId)->count());

        // Au-delà de la fenêtre de grâce (le cache l'a expirée) : ce n'est
        // plus une course entre onglets, c'est traité comme un vol réel.
        $this->travel(11)->seconds();

        $replay = $this->postJson('/api/v1/refresh-token', ['token' => $plainToken]);

        $replay->assertStatus(401);
        $this->assertSame(0, RefreshToken::where('family_id', $familyId)->count());
    }

    public function testAnExpiredUnusedTokenIsRejectedWithoutRevokingTheFamily(): void
    {
        $user = User::factory()->create();
        [$plainToken, $row] = $this->issueRefreshToken($user);
        $row->expire = now()->subDay();
        $row->save();

        $response = $this->postJson('/api/v1/refresh-token', ['token' => $plainToken]);

        $response->assertStatus(401);
        $this->assertDatabaseHas('refresh_tokens', ['id' => $row->id]);
    }

    public function testANotRememberedTokenRotatesToAShortLivedSuccessor(): void
    {
        $user = User::factory()->create();
        [$plainToken] = $this->issueRefreshToken($user, remember: false);

        $response = $this->postJson('/api/v1/refresh-token', ['token' => $plainToken]);

        $response->assertOk();
        $newRow = RefreshToken::where('token', TokenTools::hashToken($response->json('data.refreshToken')))->first();
        $this->assertFalse((bool) $newRow->remember);
        $this->assertTrue(\Carbon\Carbon::parse($newRow->expire)->lessThan(now()->addDays(2)));
    }
}
