<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;
use Tests\TestCase;

class TwoFactorSetupTest extends TestCase
{
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . TokenTools::createAccessToken($user, [], [], [])];
    }

    /**
     * Le code de confirmation consomme la fenêtre TOTP courante : on simule
     * ensuite le passage à la fenêtre suivante pour que l'appelant puisse
     * présenter un nouveau code (sinon rejeté comme rejeu).
     */
    private function enableAndConfirm(User $user, string $password = 'password'): string
    {
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => $password]);
        $secret = $user->fresh()->two_factor_secret;
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => TOTP::createFromSecret($secret)->now(),
        ]);
        User::whereKey($user->id)->update(['two_factor_last_used_timestep' => null]);

        return $secret;
    }

    public function testEnableReturnsSecretAndProvisioningUri(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'password']);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['secret', 'provisioningUri']]);
        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function testConfirmWithValidCodeActivatesAndReturnsRecoveryCodes(): void
    {
        $user = User::factory()->create();
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'password']);
        $secret = $user->fresh()->two_factor_secret;

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => TOTP::createFromSecret($secret)->now(),
        ]);

        $response->assertOk();
        $this->assertCount(8, $response->json('data.recoveryCodes'));
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertSame(8, $user->twoFactorRecoveryCodes()->count());
    }

    public function testConfirmingTheFirstTwoFactorMethodRevokesExistingRefreshTokens(): void
    {
        $user = User::factory()->create();
        $preExisting = $user->refreshTokens()->create([
            'token' => \App\Auth\TokenTools::hashToken('a-refresh-token-issued-before-2fa'),
            'expire' => now()->addDays(30),
        ]);

        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'password']);
        $secret = $user->fresh()->two_factor_secret;
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => TOTP::createFromSecret($secret)->now(),
        ])->assertOk();

        $this->assertDatabaseMissing('refresh_tokens', ['id' => $preExisting->id]);
    }

    public function testConfirmWithInvalidCodeIsRejected(): void
    {
        $user = User::factory()->create();
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'password']);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', ['code' => '000000']);

        $response->assertStatus(422);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function testDisableRequiresPasswordAndCode(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $secret = $this->enableAndConfirm($user, 'a-very-long-password');

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/disable', [
            'password' => 'a-very-long-password',
            'code' => TOTP::createFromSecret($secret)->now(),
        ]);

        $response->assertOk();
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->assertSame(0, $user->twoFactorRecoveryCodes()->count());
    }

    public function testDisableRejectsWrongPassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $secret = $this->enableAndConfirm($user, 'a-very-long-password');

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/disable', [
            'password' => 'wrong-password',
            'code' => TOTP::createFromSecret($secret)->now(),
        ]);

        $response->assertStatus(401);
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function testARecoveryCodeCanReplaceTheTotpCodeAndIsSingleUse(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'a-very-long-password']);
        $secret = $user->fresh()->two_factor_secret;
        $confirmResponse = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => TOTP::createFromSecret($secret)->now(),
        ]);
        $recoveryCode = $confirmResponse->json('data.recoveryCodes.0');

        // Réactive un 2FA fraîchement configuré pour tester le code de secours sur `regenerateRecoveryCodes`
        // plutôt que de le consommer directement sur `disable` (qui rendrait le test suivant redondant).
        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/recovery-codes', [
            'password' => 'a-very-long-password',
            'code' => $recoveryCode,
        ]);
        $response->assertOk();
        $this->assertCount(8, $response->json('data.recoveryCodes'));

        // Le code de secours utilisé ne doit plus être réutilisable.
        $secondAttempt = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/recovery-codes', [
            'password' => 'a-very-long-password',
            'code' => $recoveryCode,
        ]);
        $secondAttempt->assertStatus(422);
    }

    public function testCannotEnableTwiceWithoutDisablingFirst(): void
    {
        $user = User::factory()->create();
        $this->enableAndConfirm($user);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'password']);

        $response->assertStatus(409);
    }

    /**
     * Activation volontaire du premier moyen 2FA : les autres sessions (émises
     * sans second facteur) sont révoquées, mais pas celle qui l'active.
     */
    public function testConfirmingTheFirstMethodVoluntarilyKeepsTheCurrentSession(): void
    {
        $user = User::factory()->create();
        $current = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->json('data');
        $other = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->json('data');
        $headers = ['Authorization' => 'Bearer ' . $current['accessToken']];

        $this->withHeaders($headers)->postJson('/api/v1/2fa/totp/enable', ['password' => 'password'])->assertOk();
        $secret = $user->fresh()->two_factor_secret;
        $this->withHeaders($headers)->postJson('/api/v1/2fa/totp/confirm', [
            'code' => TOTP::createFromSecret($secret)->now(),
        ])->assertOk();

        $this->postJson('/api/v1/refresh-token', ['token' => $current['refreshToken']])->assertOk();
        $this->postJson('/api/v1/refresh-token', ['token' => $other['refreshToken']])->assertStatus(401);
    }
}
