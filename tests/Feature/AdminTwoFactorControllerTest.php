<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\TwoFactorPolicy;
use App\Models\User;
use App\Models\WebauthnCredential;
use Carbon\Carbon;
use Tests\TestCase;

class AdminTwoFactorControllerTest extends TestCase
{
    private function adminHeaders(): array
    {
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], [], true);

        return ['Authorization' => 'Bearer ' . $token];
    }

    // Policy

    public function testAdminCanSetTheEnforcementDate(): void
    {
        $enforcedAt = now()->addDays(30)->startOfSecond();

        $response = $this->withHeaders($this->adminHeaders())->putJson('/api/v1/admin/2fa/policy', [
            'enforcedAt' => $enforcedAt->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertTrue(Carbon::parse(TwoFactorPolicy::current()->enforced_at)->equalTo($enforcedAt));
    }

    public function testAdminCanClearTheEnforcementDate(): void
    {
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->addDays(10);
        $policy->save();

        $response = $this->withHeaders($this->adminHeaders())->putJson('/api/v1/admin/2fa/policy', [
            'enforcedAt' => null,
        ]);

        $response->assertOk();
        $this->assertNull(TwoFactorPolicy::current()->enforced_at);
    }

    public function testNonAdminCannotChangeThePolicy(): void
    {
        $user = User::factory()->create();
        $token = TokenTools::createAccessToken($user, [], [], []);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson('/api/v1/admin/2fa/policy', ['enforcedAt' => now()->addDay()->toIso8601String()]);

        $response->assertStatus(401);
    }

    // Exemptions

    public function testAdminCanGrantAndRevokeAnExemption(): void
    {
        $user = User::factory()->create();
        $headers = $this->adminHeaders();

        $grant = $this->withHeaders($headers)->postJson("/api/v1/admin/users/{$user->id}/2fa-exemption", [
            'reason' => 'Compte tablette, migration vers token API en cours',
        ]);
        $grant->assertOk();
        $this->assertTrue($user->fresh()->two_factor_exempt);
        $this->assertNotNull($user->fresh()->two_factor_exempt_by);

        $revoke = $this->withHeaders($headers)->deleteJson("/api/v1/admin/users/{$user->id}/2fa-exemption");
        $revoke->assertNoContent();
        $this->assertFalse($user->fresh()->two_factor_exempt);
    }

    /**
     * La révocation ne coupe pas les sessions elle-même : c'est le refresh qui
     * les refuse, et seulement si l'obligation 2FA s'applique réellement.
     */
    public function testAfterRevokingAnExemptionTheSessionIsCutAtRefreshOnlyWhenEnforced(): void
    {
        $headers = $this->adminHeaders();
        $grantThenRevoke = function (User $user) use ($headers): void {
            $this->withHeaders($headers)->postJson("/api/v1/admin/users/{$user->id}/2fa-exemption", [
                'reason' => 'Compte tablette, migration vers token API en cours',
            ])->assertOk();
            $this->withHeaders($headers)->deleteJson("/api/v1/admin/users/{$user->id}/2fa-exemption")->assertNoContent();
        };
        $issueRefreshToken = function (User $user, string $plain): void {
            $user->refreshTokens()->create([
                'token' => TokenTools::hashToken($plain),
                'expire' => now()->addDays(30),
                'family_id' => $plain,
            ]);
        };

        $gracePeriodUser = User::factory()->create();
        $issueRefreshToken($gracePeriodUser, 'token-pendant-periode-de-grace');
        $grantThenRevoke($gracePeriodUser);
        $this->postJson('/api/v1/refresh-token', ['token' => 'token-pendant-periode-de-grace'])->assertOk();

        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $enforcedUser = User::factory()->create();
        $issueRefreshToken($enforcedUser, 'token-apres-echeance');
        $grantThenRevoke($enforcedUser);
        $this->postJson('/api/v1/refresh-token', ['token' => 'token-apres-echeance'])->assertStatus(401);
    }

    public function testGrantingAnExemptionRequiresAReason(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v1/admin/users/{$user->id}/2fa-exemption", []);

        $response->assertStatus(422);
        $this->assertFalse($user->fresh()->two_factor_exempt);
    }

    // Stats

    public function testStatsDistinguishEnabledExemptAndPending(): void
    {
        User::factory()->create(); // pending
        User::factory()->create(['two_factor_confirmed_at' => now()]); // enabled
        User::factory()->create(['two_factor_exempt' => true]); // exempt

        $response = $this->withHeaders($this->adminHeaders())->getJson('/api/v1/admin/2fa/stats');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(3, $data['totalActiveUsers']);
        $this->assertGreaterThanOrEqual(1, $data['twoFactorEnabled']);
        $this->assertGreaterThanOrEqual(1, $data['exempt']);
        $this->assertGreaterThanOrEqual(1, $data['pending']);
    }

    public function testStatsCountAWebauthnOnlyUserAsEnabledAndAnEnabledExemptUserOnce(): void
    {
        $headers = $this->adminHeaders();
        $before = $this->withHeaders($headers)->getJson('/api/v1/admin/2fa/stats')->json('data');

        $webauthnOnly = User::factory()->create();
        WebauthnCredential::create([
            'user_id' => $webauthnOnly->id,
            'credential_id' => base64_encode('cred-stats'),
            'public_key' => base64_encode('pk-stats'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé',
        ]);
        User::factory()->create(['two_factor_confirmed_at' => now(), 'two_factor_exempt' => true]);

        $after = $this->withHeaders($headers)->getJson('/api/v1/admin/2fa/stats')->json('data');

        $this->assertSame($before['totalActiveUsers'] + 2, $after['totalActiveUsers']);
        $this->assertSame($before['twoFactorEnabled'] + 2, $after['twoFactorEnabled']);
        $this->assertSame($before['exempt'], $after['exempt']);
        $this->assertSame($before['pending'], $after['pending']);
    }

    /**
     * `two_factor_confirmed_at` ne couvre que le TOTP : la fiche admin doit
     * aussi permettre d'afficher un compte protégé par WebAuthn seul.
     */
    public function testAdminUserDetailExposesWhetherTheUserHasAWebauthnKey(): void
    {
        $headers = $this->adminHeaders();
        $withKey = User::factory()->create();
        WebauthnCredential::create([
            'user_id' => $withKey->id,
            'credential_id' => base64_encode('cred-admin-detail'),
            'public_key' => base64_encode('pk-admin-detail'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé',
        ]);
        $withoutKey = User::factory()->create();

        $this->withHeaders($headers)->getJson("/api/v1/admin/users/{$withKey->id}")
            ->assertOk()
            ->assertJsonPath('data.webauthn_credentials_exists', true);
        $this->withHeaders($headers)->getJson("/api/v1/admin/users/{$withoutKey->id}")
            ->assertOk()
            ->assertJsonPath('data.webauthn_credentials_exists', false);
    }

    public function testNonAdminCannotReadStats(): void
    {
        $user = User::factory()->create();
        $token = TokenTools::createAccessToken($user, [], [], []);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/admin/2fa/stats');

        $response->assertStatus(401);
    }
}
