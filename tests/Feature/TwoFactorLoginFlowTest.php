<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\TwoFactorPolicy;
use App\Models\User;
use App\Models\TwoFactorMethod;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;
use Tests\TestCase;

class TwoFactorLoginFlowTest extends TestCase
{
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . TokenTools::createAccessToken($user, [], [], [])];
    }

    private function createUserWithTwoFactorEnabled(string $password = 'a-very-long-password'): array
    {
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => $password]);
        $secret = $this->totpSecretOf($user);
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => $this->totpCode($secret),
        ]);
        // Simule le passage à la fenêtre TOTP suivante : le code de
        // confirmation vient de consommer la fenêtre courante.
        $this->nextTotpWindow();

        return [$user->fresh(), $secret];
    }

    private function preAuthTokenFor(User $user): string
    {
        return $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ])->json('data.preAuthToken');
    }

    public function testATotpCodeCannotBeReusedWithinItsTimeWindow(): void
    {
        [$user, $secret] = $this->createUserWithTwoFactorEnabled();
        $code = $this->totpCode($secret);

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->preAuthTokenFor($user)])
            ->postJson('/api/v1/2fa/verify', ['code' => $code])
            ->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->preAuthTokenFor($user)])
            ->postJson('/api/v1/2fa/verify', ['code' => $code])
            ->assertStatus(422);
    }

    public function testAnUnconfirmedTotpSecretIsNotAcceptedAsASecondFactor(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        TwoFactorMethod::factory()->webauthn()->create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-unconfirmed-totp'),
            'public_key' => base64_encode('pk-unconfirmed-totp'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé principale',
        ]);
        // Enrôlement TOTP commencé puis abandonné : secret stocké, jamais confirmé.
        $secret = TOTP::generate()->getSecret();
        TwoFactorMethod::factory()->totp($secret)->unconfirmed()->create(['user_id' => $user->id]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->preAuthTokenFor($user)])
            ->postJson('/api/v1/2fa/verify', ['code' => $this->totpCode($secret)]);

        $response->assertStatus(422);
    }

    public function testLoginReturnsAPreAuthTokenInsteadOfAnAccessTokenWhenTwoFactorIsEnabled(): void
    {
        [$user] = $this->createUserWithTwoFactorEnabled();

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.requiresTwoFactor', true);
        $this->assertNotEmpty($response->json('data.preAuthToken'));
        $this->assertArrayNotHasKey('accessToken', $response->json());
    }

    public function testVerifyExchangesAValidCodeForAFullLoginResponse(): void
    {
        [$user, $secret] = $this->createUserWithTwoFactorEnabled();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);
        $preAuthToken = $loginResponse->json('data.preAuthToken');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $preAuthToken])
            ->postJson('/api/v1/2fa/verify', ['code' => $this->totpCode($secret)]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.accessToken'));
        $this->assertNotEmpty($response->json('data.refreshToken'));
    }

    public function testVerifyRejectsAnInvalidCode(): void
    {
        [$user] = $this->createUserWithTwoFactorEnabled();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);
        $preAuthToken = $loginResponse->json('data.preAuthToken');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $preAuthToken])
            ->postJson('/api/v1/2fa/verify', ['code' => '000000']);

        $response->assertStatus(422);
    }

    public function testAPreAuthTokenCannotBeUsedAsARegularAccessToken(): void
    {
        [$user] = $this->createUserWithTwoFactorEnabled();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);
        $preAuthToken = $loginResponse->json('data.preAuthToken');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $preAuthToken])
            ->getJson('/api/v1/me');

        $response->assertStatus(401);
    }

    public function testEnforcedPolicyBlocksLoginAndReturnsASetupTokenWhenEnforcementEnabled(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.requiresTwoFactorSetup', true);
        $this->assertNotEmpty($response->json('data.setupToken'));
        $this->assertArrayNotHasKey('accessToken', $response->json());
    }

    public function testEnforcementIsIgnoredWhenTheEnvironmentToggleIsDisabled(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => false]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.accessToken'));
    }

    public function testAnExemptAccountIsNeverBlockedEvenWhenEnforced(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create([
            'password' => Hash::make('a-very-long-password'),
            'two_factor_exempt' => true,
            'two_factor_exempt_reason' => 'Compte tablette',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.accessToken'));
    }

    public function testAnExemptAccountWithTwoFactorAlreadyEnabledStillGetsTheChallenge(): void
    {
        // hasTwoFactorEnabled() doit primer sur isTwoFactorExempt() : l'exemption
        // sert à contourner l'enforcement pour un compte qui n'a pas encore de
        // 2FA, pas à désactiver le 2FA d'un compte qui l'a déjà configuré.
        $user = User::factory()->has(TwoFactorMethod::factory(), 'twoFactorMethods')->create([
            'password' => Hash::make('a-very-long-password'),
            'two_factor_exempt' => true,
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertJsonPath('data.requiresTwoFactor', true);
        $this->assertArrayNotHasKey('accessToken', $response->json('data'));
    }

    public function testAnExpiredExemptionNoLongerAppliesAndTheAccountGetsBlocked(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create([
            'password' => Hash::make('a-very-long-password'),
            'two_factor_exempt' => true,
            'two_factor_exempt_until' => now()->subHour(),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertJsonPath('data.requiresTwoFactorSetup', true);
    }

    public function testGracePeriodReturnsANudgeInsteadOfBlocking(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->addDays(10);
        $policy->save();

        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.accessToken'));
        $this->assertNotNull($response->json('data.twoFactorNudge.enforcedAt'));
    }

    public function testTheForcedSetupFlowCompletesLoginOnConfirm(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);
        $setupToken = $loginResponse->json('data.setupToken');

        $enableResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])
            ->postJson('/api/v1/2fa/totp/enable');
        $enableResponse->assertOk();
        $secret = $this->totpSecretOf($user);

        $confirmResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])
            ->postJson('/api/v1/2fa/totp/confirm', ['code' => $this->totpCode($secret)]);

        $confirmResponse->assertOk();
        $this->assertNotEmpty($confirmResponse->json('data.accessToken'));
        $this->assertNotEmpty($confirmResponse->json('data.recoveryCodes'));
    }

    public function testCompletingForcedSetupRevokesSessionsOpenedBeforeTwoFactorButNotTheNewOne(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        [, $staleSession] = $this->issueSession($user);

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);
        $setupToken = $loginResponse->json('data.setupToken');

        $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])->postJson('/api/v1/2fa/totp/enable');
        $secret = $this->totpSecretOf($user);

        $confirmResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])
            ->postJson('/api/v1/2fa/totp/confirm', ['code' => $this->totpCode($secret)]);

        $confirmResponse->assertOk();
        $this->assertDatabaseMissing('auth_sessions', ['id' => $staleSession->id]);
        $this->assertSame(1, $user->authSessions()->count());
        $this->assertNotNull($this->sessionOf($confirmResponse->json('data.refreshToken'))->two_factor_verified_at);
    }

    public function testASetupTokenCannotBeUsedAsARegularAccessToken(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);
        $setupToken = $loginResponse->json('data.setupToken');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])->getJson('/api/v1/me');

        $response->assertStatus(401);
    }

    /**
     * Le setup token ne sert qu'à la première méthode : une fois le compte
     * protégé, il ne doit plus permettre d'enrôler (sans mot de passe) ni
     * d'obtenir une session sans second facteur.
     */
    public function testASetupTokenIsRejectedOnceTheAccountHasTwoFactor(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $setupToken = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ])->json('data.setupToken');

        $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])->postJson('/api/v1/2fa/totp/enable');
        $secret = $this->totpSecretOf($user);
        $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])
            ->postJson('/api/v1/2fa/totp/confirm', ['code' => $this->totpCode($secret)])
            ->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])
            ->postJson('/api/v1/2fa/webauthn/register/challenge')
            ->assertStatus(401);
    }

    public function testVerifyIsLockedPerAccountAfterTooManyInvalidCodes(): void
    {
        // Simule un attaquant multi-IP : la limite par IP de la route ne s'applique pas.
        $this->withoutMiddleware(ThrottleRequests::class);
        [$user, $secret] = $this->createUserWithTwoFactorEnabled();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeaders(['Authorization' => 'Bearer ' . $this->preAuthTokenFor($user)])
                ->postJson('/api/v1/2fa/verify', ['code' => '000000'])
                ->assertStatus(422);
        }

        // Même avec le bon code et un nouveau pre-auth token : verrouillé.
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->preAuthTokenFor($user)])
            ->postJson('/api/v1/2fa/verify', ['code' => $this->totpCode($secret)])
            ->assertStatus(429);
    }
}
