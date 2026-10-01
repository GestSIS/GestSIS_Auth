<?php

namespace Tests\Feature;

use App\Models\TwoFactorPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;
use Tests\TestCase;

/**
 * "Se souvenir de moi" (`rememberMe` dans le body de /login) détermine la
 * durée de vie du refresh token — par défaut vrai, pour ne pas raccourcir
 * silencieusement la session de tous les comptes existants. Doit survivre à
 * un éventuel passage par le challenge 2FA (voir TokenTools::encodeScopedToken).
 */
class RememberMeTest extends TestCase
{
    public function testLoginWithoutRememberMeIssuesAShortLivedRefreshToken(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
            'rememberMe' => false,
        ]);

        $response->assertOk();
        $session = $this->sessionOf($response->json('data.refreshToken'));
        $this->assertFalse($session->remember);
        $this->assertTrue($session->idle_expires_at->lessThan(now()->addDays(2)));
    }

    public function testLoginDefaultsToRememberedForBackwardCompatibility(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertOk();
        $session = $this->sessionOf($response->json('data.refreshToken'));
        $this->assertTrue($session->remember);
        $this->assertTrue($session->idle_expires_at->greaterThan(now()->addDays(20)));
    }

    public function testRememberMeChoiceSurvivesTheTotpChallenge(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $this->withHeaders(['Authorization' => 'Bearer ' . \App\Auth\TokenTools::createAccessToken($user, [], [], [])])
            ->postJson('/api/v1/2fa/totp/enable', ['password' => 'a-very-long-password']);
        $secret = $this->totpSecretOf($user);
        $this->withHeaders(['Authorization' => 'Bearer ' . \App\Auth\TokenTools::createAccessToken($user, [], [], [])])
            ->postJson('/api/v1/2fa/totp/confirm', ['code' => $this->totpCode($secret)]);
        // Simule la fenêtre TOTP suivante (le code de confirmation a consommé la courante).
        $this->nextTotpWindow();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
            'rememberMe' => false,
        ]);
        $preAuthToken = $loginResponse->json('data.preAuthToken');

        $verifyResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $preAuthToken])
            ->postJson('/api/v1/2fa/verify', ['code' => $this->totpCode($secret)]);

        $verifyResponse->assertOk();
        $this->assertFalse($this->sessionOf($verifyResponse->json('data.refreshToken'))->remember);
    }

    public function testRememberMeChoiceSurvivesForcedSetup(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
            'rememberMe' => false,
        ]);
        $setupToken = $loginResponse->json('data.setupToken');

        $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])->postJson('/api/v1/2fa/totp/enable');
        $secret = $this->totpSecretOf($user);
        $confirmResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])
            ->postJson('/api/v1/2fa/totp/confirm', ['code' => $this->totpCode($secret)]);

        $confirmResponse->assertOk();
        $this->assertFalse($this->sessionOf($confirmResponse->json('data.refreshToken'))->remember);
    }
}
