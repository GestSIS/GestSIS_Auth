<?php

namespace Tests\Feature;

use App\Models\RefreshToken;
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
        $row = $user->refreshTokens()->latest('id')->first();
        $this->assertFalse((bool) $row->remember);
        $this->assertTrue(\Carbon\Carbon::parse($row->expire)->lessThan(now()->addDays(2)));
    }

    public function testLoginDefaultsToRememberedForBackwardCompatibility(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertOk();
        $row = $user->refreshTokens()->latest('id')->first();
        $this->assertTrue((bool) $row->remember);
        $this->assertTrue(\Carbon\Carbon::parse($row->expire)->greaterThan(now()->addDays(20)));
    }

    public function testRememberMeChoiceSurvivesTheTotpChallenge(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $this->withHeaders(['Authorization' => 'Bearer ' . \App\Auth\TokenTools::createAccessToken($user, [], [], [])])
            ->postJson('/api/v1/2fa/totp/enable', ['password' => 'a-very-long-password']);
        $secret = $user->fresh()->two_factor_secret;
        $this->withHeaders(['Authorization' => 'Bearer ' . \App\Auth\TokenTools::createAccessToken($user, [], [], [])])
            ->postJson('/api/v1/2fa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()]);
        // Simule la fenêtre TOTP suivante (le code de confirmation a consommé la courante).
        User::whereKey($user->id)->update(['two_factor_last_used_timestep' => null]);

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
            'rememberMe' => false,
        ]);
        $preAuthToken = $loginResponse->json('data.preAuthToken');

        $verifyResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $preAuthToken])
            ->postJson('/api/v1/2fa/verify', ['code' => TOTP::createFromSecret($secret)->now()]);

        $verifyResponse->assertOk();
        $row = RefreshToken::where('token', \App\Auth\TokenTools::hashToken($verifyResponse->json('data.refreshToken')))->first();
        $this->assertFalse((bool) $row->remember);
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
        $secret = $user->fresh()->two_factor_secret;
        $confirmResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $setupToken])
            ->postJson('/api/v1/2fa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()]);

        $confirmResponse->assertOk();
        $row = RefreshToken::where('token', \App\Auth\TokenTools::hashToken($confirmResponse->json('data.refreshToken')))->first();
        $this->assertFalse((bool) $row->remember);
    }
}
