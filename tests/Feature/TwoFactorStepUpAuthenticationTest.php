<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;
use Tests\TestCase;

/**
 * Ajouter une méthode 2FA supplémentaire (quand une autre est déjà active)
 * exige une ré-authentification par mot de passe — voir
 * HandlesTwoFactorConfirmation::requireStepUpReauthentication.
 * Le tout premier enrôlement (aucune méthode active) n'exige rien de plus :
 * déjà couvert par TwoFactorSetupTest/WebauthnAccessControlTest.
 */
class TwoFactorStepUpAuthenticationTest extends TestCase
{
    /**
     * Le code de confirmation consomme la fenêtre TOTP courante : on simule
     * ensuite le passage à la fenêtre suivante pour pouvoir présenter un
     * nouveau code (sinon rejeté comme rejeu).
     */
    private function enableAndConfirmTotp(User $user): string
    {
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'a-very-long-password']);
        $secret = $user->fresh()->two_factor_secret;
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => TOTP::createFromSecret($secret)->now(),
        ]);
        User::whereKey($user->id)->update(['two_factor_last_used_timestep' => null]);

        return $secret;
    }

    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . TokenTools::createAccessToken($user, [], [], [])];
    }

    public function testEnablingTotpWithWebauthnAlreadyActiveRequiresThePassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-step-up-1'),
            'public_key' => base64_encode('pk-step-up-1'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé principale',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable');

        $response->assertStatus(422);
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function testEnablingTotpWithWebauthnAlreadyActiveRejectsAWrongPassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-step-up-2'),
            'public_key' => base64_encode('pk-step-up-2'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé principale',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', [
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function testEnablingTotpWithWebauthnAlreadyActiveSucceedsWithTheCorrectPassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-step-up-3'),
            'public_key' => base64_encode('pk-step-up-3'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé principale',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', [
            'password' => 'a-very-long-password',
        ]);

        $response->assertOk();
        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    /**
     * Même le tout premier enrôlement exige le mot de passe : sinon un jeton
     * volé suffirait à attacher l'authentificateur d'un tiers au compte.
     */
    public function testFirstTotpEnrollmentRequiresThePassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable')
            ->assertStatus(422);
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'wrong-password'])
            ->assertStatus(401);
        $this->assertNull($user->fresh()->two_factor_secret);

        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'a-very-long-password'])
            ->assertOk();
    }

    public function testRegisteringAWebauthnKeyWithTotpAlreadyActiveRequiresPasswordAndCode(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $secret = $this->enableAndConfirmTotp($user);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/v1/2fa/webauthn/register/challenge');

        $response->assertStatus(422);
    }

    public function testRegisteringAWebauthnKeyWithTotpAlreadyActiveRejectsAnInvalidCode(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $secret = $this->enableAndConfirmTotp($user);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/webauthn/register/challenge', [
            'password' => 'a-very-long-password',
            'code' => '000000',
        ]);

        $response->assertStatus(422);
    }

    public function testRegisteringAWebauthnKeyWithTotpAlreadyActiveSucceedsWithPasswordAndCode(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $secret = $this->enableAndConfirmTotp($user);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/webauthn/register/challenge', [
            'password' => 'a-very-long-password',
            'code' => TOTP::createFromSecret($secret)->now(),
        ]);

        $response->assertOk();
    }

    public function testRegisteringAnAdditionalWebauthnKeyWhenWebauthnIsAlreadyTheActiveMethodRequiresThePassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-step-up-4'),
            'public_key' => base64_encode('pk-step-up-4'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Première clé',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/v1/2fa/webauthn/register/challenge');

        $response->assertStatus(422);
    }

    public function testFirstWebauthnEnrollmentRequiresThePassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/v1/2fa/webauthn/register/challenge')
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/v1/2fa/webauthn/register/challenge', ['password' => 'a-very-long-password'])
            ->assertOk();
    }

    /**
     * Régénérer les codes de secours réutilise le même garde-fou de step-up :
     * un compte WebAuthn-only n'a pas de code TOTP à fournir, seul le mot de
     * passe est exigé (voir HandlesTwoFactorConfirmation::requireStepUpReauthentication).
     */
    public function testRegeneratingRecoveryCodesOnAWebauthnOnlyAccountRequiresOnlyThePassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-step-up-5'),
            'public_key' => base64_encode('pk-step-up-5'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé principale',
        ]);

        $withoutPassword = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/v1/2fa/totp/recovery-codes');
        $withoutPassword->assertStatus(422);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/recovery-codes', [
            'password' => 'a-very-long-password',
        ]);

        $response->assertOk();
        $this->assertCount(8, $response->json('data.recoveryCodes'));
    }

    public function testRegeneratingRecoveryCodesWithNoTwoFactorMethodActiveIsRejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/recovery-codes', [
            'password' => 'a-very-long-password',
        ]);

        $response->assertStatus(409);
    }

    /**
     * Avec un access token volé, le mot de passe ne doit pas pouvoir être
     * deviné via les endpoints de ré-authentification : limite par compte,
     * qui tient même en répartissant les essais sur plusieurs IP.
     */
    public function testStepUpIsLockedPerAccountAfterTooManyFailedAttempts(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $secret = $this->enableAndConfirmTotp($user);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/disable', [
                'password' => 'wrong-password',
                'code' => '000000',
            ])->assertStatus(401);
        }

        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/recovery-codes', [
            'password' => 'a-very-long-password',
            'code' => TOTP::createFromSecret($secret)->now(),
        ])->assertStatus(429);
    }

    /**
     * Un mot de passe hameçonné ne doit pas suffire à changer le mot de passe
     * d'un compte protégé (et à fermer toutes les sessions du propriétaire).
     */
    public function testChangingThePasswordOfATotpAccountRequiresASessionAndTheCode(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $secret = $this->enableAndConfirmTotp($user);
        $params = ['email' => $user->email, 'password' => 'a-very-long-password', 'new_password' => 'un-nouveau-mot-de-passe'];

        // Sans session : refusé.
        $this->postJson('/api/v1/change-password', $params)->assertStatus(401);

        // Jeton sans `sid` (jeton d'API, impersonation) : pas une vraie session.
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/change-password', $params)->assertStatus(401);

        $session = ['Authorization' => 'Bearer ' . TokenTools::createAccessToken($user, [], [], [], sessionId: 'une-session')];
        $this->withHeaders($session)->postJson('/api/v1/change-password', $params)->assertStatus(422);
        $this->assertTrue(Hash::check('a-very-long-password', $user->fresh()->password));

        $this->withHeaders($session)
            ->postJson('/api/v1/change-password', [...$params, 'code' => TOTP::createFromSecret($secret)->now()])
            ->assertOk();
        $this->assertTrue(Hash::check('un-nouveau-mot-de-passe', $user->fresh()->password));
    }

    public function testChangingThePasswordOfAWebauthnOnlyAccountRequiresASessionOfThatAccount(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-password-change'),
            'public_key' => base64_encode('pk-password-change'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé',
        ]);
        $someoneElse = User::factory()->create();
        $params = ['email' => $user->email, 'password' => 'a-very-long-password', 'new_password' => 'un-nouveau-mot-de-passe'];

        $this->withHeaders(['Authorization' => 'Bearer ' . TokenTools::createAccessToken($someoneElse, [], [], [], sessionId: 'autre')])
            ->postJson('/api/v1/change-password', $params)
            ->assertStatus(401);

        $this->withHeaders(['Authorization' => 'Bearer ' . TokenTools::createAccessToken($user, [], [], [], sessionId: 'une-session')])
            ->postJson('/api/v1/change-password', $params)
            ->assertOk();
    }
}
