<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\ApiToken;
use App\Models\RegisterToken;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un jeton issu d'un jeton d'API sert aux intégrations, jamais à gérer
 * l'authentification du compte : sessions, 2FA, jetons d'API, jetons de
 * permissions. Sans ce refus, use-token/register-token permettaient à un jeton
 * d'API bridé d'obtenir un access token avec tous les droits du compte.
 */
class ApiTokenAccountRestrictionsTest extends TestCase
{
    /**
     * Échange un vrai jeton d'API contre un access token via /token-auth.
     */
    private function apiTokenJwtFor(User $user): string
    {
        $tokenData = TokenTools::createApiToken(30);
        ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Intégration',
            'token' => TokenTools::hashToken($tokenData->token),
            'expires_at' => $tokenData->expire,
        ]);

        return $this->postJson('/api/v1/token-auth', ['token' => $tokenData->token])
            ->assertOk()
            ->json('data.accessToken');
    }

    private function decode(string $jwt): \stdClass
    {
        return JWT::decode($jwt, new Key(Storage::disk('keys')->get('auth-public.key'), 'RS256'));
    }

    public function testTheTokenExchangedFromAnApiTokenIsTypedAsSuch(): void
    {
        $user = User::factory()->create(['admin' => true]);

        $this->assertTrue(TokenTools::isApiToken($this->decode($this->apiTokenJwtFor($user))));
    }

    public function testALongTermIntegrationTokenIsTypedAsAnApiToken(): void
    {
        $jwt = TokenTools::createCustomDurationToken(User::factory()->create(), [], [], [], 90);

        $this->assertTrue(TokenTools::isApiToken($this->decode($jwt)));
    }

    public function testASessionTokenIsNotAnApiToken(): void
    {
        $jwt = TokenTools::createAccessToken(User::factory()->create(), [], [], [], sessionId: 'une-session');

        $this->assertFalse(TokenTools::isApiToken($this->decode($jwt)));
    }

    public function testAnApiTokenCannotTouchTheAccountAuthenticationSettings(): void
    {
        $user = User::factory()->create(['admin' => true]);
        $headers = ['Authorization' => 'Bearer ' . $this->apiTokenJwtFor($user)];
        RegisterToken::create([
            'token' => TokenTools::hashToken('un-jeton-de-permissions'),
            'description' => 'Test',
            'validite' => now()->addDay(),
        ]);

        $forbidden = [
            ['post', '/api/v1/use-token', ['token' => 'un-jeton-de-permissions']],
            ['post', '/api/v1/register-token', ['roles' => [1]]],
            ['get', '/api/v1/sessions', []],
            ['get', '/api/v1/api-tokens', []],
            ['post', '/api/v1/api-tokens', ['name' => 'Nouveau', 'expires_in_days' => 30, 'permission_ids' => [1], 'password' => 'password']],
            ['get', '/api/v1/2fa/status', []],
            ['get', '/api/v1/2fa/webauthn/credentials', []],
            ['post', '/api/v1/2fa/totp/enable', ['password' => 'password']],
            ['post', '/api/v1/2fa/webauthn/register/challenge', ['password' => 'password']],
            ['post', '/api/v1/2fa/totp/disable', ['password' => 'password', 'code' => '000000']],
            ['post', '/api/v1/2fa/totp/recovery-codes', ['password' => 'password']],
        ];

        foreach ($forbidden as [$method, $uri, $payload]) {
            $this->withHeaders([...$headers, 'Sis-Key' => 'test'])->json($method, $uri, $payload)
                ->assertStatus(403, "{$method} {$uri} devrait être refusé à un jeton d'API")
                ->assertJsonPath('message', "Action impossible avec un jeton d'API");
        }

        $this->assertDatabaseHas('register_tokens', ['token' => TokenTools::hashToken('un-jeton-de-permissions')]);
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function testAnApiTokenCanStillUseTheIntegrationEndpoints(): void
    {
        $user = User::factory()->create(['admin' => true]);
        $headers = ['Authorization' => 'Bearer ' . $this->apiTokenJwtFor($user)];

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/permissions')->assertOk();
    }

    /**
     * Jeton d'usurpation obtenu comme le fait GestSIS_APP (admin/token).
     */
    private function impersonationJwtFor(User $user): string
    {
        $admin = User::factory()->create(['admin' => true]);
        $adminToken = TokenTools::createAccessToken($admin, [], [], [], true, sessionId: 'session-admin');

        return $this->withHeaders(['Authorization' => 'Bearer ' . $adminToken])
            ->getJson('/api/v1/admin/token?user_id=' . $user->id)
            ->assertOk()
            ->json('accessToken');
    }

    /**
     * Usurpation d'identité (support) : les réglages d'authentification du
     * compte se consultent, mais ne se modifient pas.
     */
    public function testImpersonationCanReadButNotModifyTheAccountAuthenticationSettings(): void
    {
        $user = User::factory()->create();
        $session = $user->refreshTokens()->create([
            'token' => TokenTools::hashToken('session-de-l-utilisateur'),
            'expire' => now()->addDays(30),
            'family_id' => 'famille-utilisateur',
        ]);
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Intégration',
            'token' => TokenTools::hashToken('jeton-api-utilisateur'),
            'expires_at' => now()->addDays(30),
        ]);
        $headers = ['Authorization' => 'Bearer ' . $this->impersonationJwtFor($user)];

        foreach (['/api/v1/sessions', '/api/v1/api-tokens', '/api/v1/2fa/status', '/api/v1/2fa/webauthn/credentials'] as $uri) {
            $this->withHeaders($headers)->getJson($uri)->assertOk();
        }

        $forbidden = [
            ['delete', "/api/v1/sessions/{$session->id}", []],
            ['delete', "/api/v1/api-tokens/{$apiToken->id}", []],
            ['post', '/api/v1/api-tokens', ['name' => 'Nouveau', 'expires_in_days' => 30, 'permission_ids' => [1], 'password' => 'password']],
            ['post', '/api/v1/use-token', ['token' => 'un-jeton-de-permissions']],
            ['post', '/api/v1/2fa/totp/enable', ['password' => 'password']],
            ['post', '/api/v1/2fa/webauthn/register/challenge', ['password' => 'password']],
            ['post', '/api/v1/2fa/totp/disable', ['password' => 'password', 'code' => '000000']],
        ];
        foreach ($forbidden as [$method, $uri, $payload]) {
            $this->withHeaders($headers)->json($method, $uri, $payload)
                ->assertStatus(403, "{$method} {$uri} devrait être refusé en usurpation")
                ->assertJsonPath('message', "Action impossible en usurpation d'identité");
        }

        $this->assertDatabaseHas('refresh_tokens', ['id' => $session->id]);
        $this->assertNull($apiToken->fresh()->revoked_at);
    }
}
