<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Couvre le contrôle d'accès des endpoints WebAuthn. La cérémonie
 * cryptographique elle-même (une réponse d'authenticator valide, CBOR/COSE)
 * n'est pas simulable simplement en test automatisé — web-auth/webauthn-lib
 * ne fournit pas d'authenticator virtuel prêt à l'emploi pour ça. Le chemin
 * heureux de registerVerify/loginVerify doit être vérifié manuellement avec
 * un vrai navigateur (voir le plan d'implémentation).
 */
class WebauthnAccessControlTest extends TestCase
{
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . TokenTools::createAccessToken($user, [], [], [])];
    }

    public function testRegisterChallengeRequiresAuthentication(): void
    {
        $response = $this->postJson('/api/v1/2fa/webauthn/register/challenge');

        $response->assertStatus(401);
    }

    public function testRegisterChallengeReturnsCreationOptionsForAnAuthenticatedUser(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/v1/2fa/webauthn/register/challenge', ['password' => 'password']);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['challenge', 'rp', 'user', 'pubKeyCredParams']]);
    }

    public function testRegisterChallengeAcceptsASetupTokenTooLikeTotpEnable(): void
    {
        $policy = \App\Models\TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();
        config(['gestsis.two_factor_enforcement_enabled' => true]);

        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);
        $setupToken = $loginResponse->json('data.setupToken');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])
            ->postJson('/api/v1/2fa/webauthn/register/challenge');

        $response->assertOk();
    }

    public function testRegisterVerifyRejectsWhenNoChallengeIsPending(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/webauthn/register/verify', [
            'name' => 'Ma clé',
            'response' => ['id' => 'x'],
        ]);

        $response->assertStatus(422);
    }

    public function testCredentialsListRequiresAuthentication(): void
    {
        $response = $this->getJson('/api/v1/2fa/webauthn/credentials');

        $response->assertStatus(401);
    }

    public function testCredentialsListReturnsOnlyTheOwnersCredentials(): void
    {
        $user = User::factory()->create();
        WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-1'),
            'public_key' => base64_encode('pk-1'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'YubiKey bleu',
        ]);

        $otherUser = User::factory()->create();
        WebauthnCredential::create([
            'user_id' => $otherUser->id,
            'credential_id' => base64_encode('cred-2'),
            'public_key' => base64_encode('pk-2'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé de quelqu\'un d\'autre',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))->getJson('/api/v1/2fa/webauthn/credentials');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'YubiKey bleu');
    }

    public function testCannotDeleteAnotherUsersCredential(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $credential = WebauthnCredential::create([
            'user_id' => $otherUser->id,
            'credential_id' => base64_encode('cred-3'),
            'public_key' => base64_encode('pk-3'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé de quelqu\'un d\'autre',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/v1/2fa/webauthn/credentials/{$credential->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('webauthn_credentials', ['id' => $credential->id]);
    }

    public function testDeletingTheLastMethodClearsRecoveryCodesButKeepsOtherMethodsIntact(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('a-very-long-password'),
            'two_factor_confirmed_at' => now(),
        ]);
        $codes = \App\Models\TwoFactorRecoveryCode::regenerateFor($user);
        $credential = WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-4'),
            'public_key' => base64_encode('pk-4'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Seule clé',
        ]);

        // TOTP encore confirmé : la suppression de la clé WebAuthn ne doit pas
        // purger les codes de secours (le compte reste protégé via TOTP).
        $response = $this->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/v1/2fa/webauthn/credentials/{$credential->id}", [
                'password' => 'a-very-long-password',
                'code' => $codes[0],
            ]);

        $response->assertNoContent();
        $this->assertSame(8, $user->twoFactorRecoveryCodes()->count());
    }

    public function testDeletingAWebauthnCredentialRequiresThePassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $credential = WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-5'),
            'public_key' => base64_encode('pk-5'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Seule clé',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/v1/2fa/webauthn/credentials/{$credential->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('webauthn_credentials', ['id' => $credential->id]);
    }

    public function testDeletingAWebauthnCredentialRejectsAWrongPassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $credential = WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-6'),
            'public_key' => base64_encode('pk-6'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Seule clé',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/v1/2fa/webauthn/credentials/{$credential->id}", [
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(401);
        $this->assertDatabaseHas('webauthn_credentials', ['id' => $credential->id]);
    }

    public function testDeletingTheOnlyWebauthnCredentialWithTheCorrectPasswordSucceeds(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $credential = WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-7'),
            'public_key' => base64_encode('pk-7'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Seule clé',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/v1/2fa/webauthn/credentials/{$credential->id}", [
                'password' => 'a-very-long-password',
            ]);

        $response->assertNoContent();
        $this->assertDatabaseMissing('webauthn_credentials', ['id' => $credential->id]);
    }

    public function testLoginChallengeRejectsAFullAccessTokenInsteadOfAPreAuthToken(): void
    {
        $user = User::factory()->create();
        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/webauthn/challenge');

        $response->assertStatus(401);
    }

    public function testLoginChallengeReturns422WhenTheAccountHasNoWebauthnCredential(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $preAuthToken = TokenTools::createTwoFactorPreAuthToken($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $preAuthToken])
            ->postJson('/api/v1/2fa/webauthn/challenge');

        $response->assertStatus(422);
    }

    public function testLoginChallengeReturnsRequestOptionsWhenACredentialExists(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-5'),
            'public_key' => base64_encode('pk-5'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé principale',
        ]);
        $preAuthToken = TokenTools::createTwoFactorPreAuthToken($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $preAuthToken])
            ->postJson('/api/v1/2fa/webauthn/challenge');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['challenge', 'allowCredentials']]);
    }
}
