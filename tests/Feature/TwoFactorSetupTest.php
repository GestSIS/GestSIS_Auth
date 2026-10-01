<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\TwoFactorMethod;
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
        $secret = $this->totpSecretOf($user);
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => $this->totpCode($secret),
        ]);
        $this->nextTotpWindow();

        return $secret;
    }

    public function testEnableReturnsSecretAndProvisioningUri(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'password']);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['secret', 'provisioningUri']]);
        $this->assertNotNull($this->totpSecretOf($user));
        $this->assertFalse($this->hasConfirmedTotp($user));
    }

    public function testConfirmWithValidCodeActivatesAndReturnsRecoveryCodes(): void
    {
        $user = User::factory()->create();
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'password']);
        $secret = $this->totpSecretOf($user);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => $this->totpCode($secret),
        ]);

        $response->assertOk();
        $this->assertCount(8, $response->json('data.recoveryCodes'));
        $this->assertTrue($this->hasConfirmedTotp($user));
        $this->assertSame(8, $user->twoFactorRecoveryCodes()->count());
    }

    public function testConfirmingTheFirstTwoFactorMethodRevokesExistingSessions(): void
    {
        $user = User::factory()->create();
        [, $preExisting] = $this->issueSession($user);

        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'password']);
        $secret = $this->totpSecretOf($user);
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => $this->totpCode($secret),
        ])->assertOk();

        $this->assertDatabaseMissing('auth_sessions', ['id' => $preExisting->id]);
    }

    public function testConfirmWithInvalidCodeIsRejected(): void
    {
        $user = User::factory()->create();
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'password']);

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', ['code' => '000000']);

        $response->assertStatus(422);
        $this->assertFalse($this->hasConfirmedTotp($user));
    }

    public function testDisableRequiresPasswordAndCode(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $secret = $this->enableAndConfirm($user, 'a-very-long-password');

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/disable', [
            'password' => 'a-very-long-password',
            'code' => $this->totpCode($secret),
        ]);

        $response->assertOk();
        $this->assertNull($this->totpSecretOf($user));
        $this->assertFalse($this->hasConfirmedTotp($user));
        $this->assertSame(0, $user->twoFactorRecoveryCodes()->count());
    }

    public function testDisableRejectsWrongPassword(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $secret = $this->enableAndConfirm($user, 'a-very-long-password');

        $response = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/disable', [
            'password' => 'wrong-password',
            'code' => $this->totpCode($secret),
        ]);

        $response->assertStatus(401);
        $this->assertTrue($this->hasConfirmedTotp($user));
    }

    public function testARecoveryCodeCanReplaceTheTotpCodeAndIsSingleUse(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'a-very-long-password']);
        $secret = $this->totpSecretOf($user);
        $confirmResponse = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => $this->totpCode($secret),
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
        $secret = $this->totpSecretOf($user);
        $this->withHeaders($headers)->postJson('/api/v1/2fa/totp/confirm', [
            'code' => $this->totpCode($secret),
        ])->assertOk();

        $this->postJson('/api/v1/refresh-token', ['token' => $current['refreshToken']])->assertOk();
        $this->postJson('/api/v1/refresh-token', ['token' => $other['refreshToken']])->assertStatus(401);
    }

    /**
     * Codes générés en minuscules : la saisie ne doit pas échouer sur une
     * majuscule (clavier mobile) ou des espaces autour.
     */
    public function testARecoveryCodeIsAcceptedRegardlessOfCaseAndSurroundingSpaces(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/enable', ['password' => 'a-very-long-password']);
        $secret = $this->totpSecretOf($user);
        $recoveryCode = $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/confirm', [
            'code' => $this->totpCode($secret),
        ])->json('data.recoveryCodes.0');

        $this->withHeaders($this->authHeaders($user))->postJson('/api/v1/2fa/totp/recovery-codes', [
            'password' => 'a-very-long-password',
            'code' => ' ' . ucfirst(strtoupper($recoveryCode)) . ' ',
        ])->assertOk();
    }
}
