<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\ApiToken;
use App\Models\RefreshToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiLoginDisabledAccountTest extends TestCase
{
    public function testDisabledAccountCannotLoginAndGetsGenericError(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('a-very-long-password'),
            'disabled_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Les identifiants fournis sont incorrects');
        $this->assertArrayNotHasKey('accessToken', $response->json());
    }

    public function testDisabledAccountCannotRefreshAnAccessToken(): void
    {
        $user = User::factory()->create(['disabled_at' => now()]);

        // Refresh token encore présent (ex. désactivation sans purge) : il ne doit rien émettre.
        $plain = 'refresh-token-disabled';
        $refreshToken = new RefreshToken();
        $refreshToken->token = TokenTools::hashToken($plain);
        $refreshToken->expire = Carbon::now()->addDay();
        $refreshToken->user_id = $user->id;
        $refreshToken->save();

        $response = $this->postJson('/api/v1/refresh-token', ['token' => $plain]);

        $response->assertStatus(401);
        $this->assertArrayNotHasKey('accessToken', $response->json());
    }

    public function testDisabledAccountCannotExchangeAnApiToken(): void
    {
        $user = User::factory()->create(['disabled_at' => now()]);

        $tokenData = TokenTools::createApiToken(30);
        ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Test Token',
            'token' => TokenTools::hashToken($tokenData->token),
            'expires_at' => $tokenData->expire,
        ]);

        $response = $this->postJson('/api/v1/token-auth', ['token' => $tokenData->token]);

        $response->assertStatus(401);
        $this->assertArrayNotHasKey('accessToken', $response->json());
    }
}
