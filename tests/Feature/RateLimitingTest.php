<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Limites d'essais nommées (RateLimitServiceProvider) : par IP via
 * `throttle:<nom>`, et limites d'échecs par compte via `throttle-failures:<nom>`.
 */
class RateLimitingTest extends TestCase
{
    /**
     * Chaque groupe de routes a son propre compteur par IP : épuiser la limite
     * du login ne bloque pas le refresh des sessions (avant les limites
     * nommées, tous les `throttle:10,1` partageaient le même compteur).
     */
    public function testEachNamedIpLimitHasItsOwnCounter(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/v1/login', ['email' => 'inconnu@example.com', 'password' => 'faux'])->assertStatus(401);
        }
        $this->postJson('/api/v1/login', ['email' => 'inconnu@example.com', 'password' => 'faux'])->assertStatus(429);

        $this->postJson('/api/v1/refresh-token', ['token' => 'inexistant'])->assertStatus(401);
    }

    /**
     * Le `throttle:` natif répond « Too Many Attempts. » : le front affiche le
     * message tel quel, il doit donc être en français et donner le délai.
     */
    public function testIpLimitReturnsFrenchMessageWithRetryAfter(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/v1/login', ['email' => 'inconnu@example.com', 'password' => 'faux']);
        }

        $this->postJson('/api/v1/login', ['email' => 'inconnu@example.com', 'password' => 'faux'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Trop de tentatives. Réessayez dans 1 minute(s).');
    }

    /**
     * Seuls les échecs comptent : une réussite remet le compteur à zéro, si
     * bien qu'un utilisateur légitime n'est jamais bloqué par ses succès.
     */
    public function testASuccessResetsTheFailureCounter(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $params = ['email' => $user->email, 'password' => 'mauvais', 'new_password' => 'un-nouveau-mot-de-passe'];

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/v1/change-password', $params)->assertStatus(401);
        }
        $this->postJson('/api/v1/change-password', [...$params, 'password' => 'a-very-long-password'])->assertOk();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/change-password', $params)->assertStatus(401);
        }
        $this->postJson('/api/v1/change-password', $params)->assertStatus(429);
    }

    /**
     * Les échecs sont comptés par compte, pas par IP : changer d'IP ne
     * redonne pas d'essais.
     */
    public function testFailuresAreCountedPerAccountAcrossIps(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = User::factory()->create(['password' => Hash::make('a-very-long-password')]);
        $headers = ['Authorization' => 'Bearer ' . TokenTools::createAccessToken($user, [], [], [], sessionId: 'session')];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$attempt}"])
                ->withHeaders($headers)
                ->postJson('/api/v1/2fa/totp/enable', ['password' => 'mauvais'])
                ->assertStatus(401);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->withHeaders($headers)
            ->postJson('/api/v1/2fa/totp/enable', ['password' => 'a-very-long-password'])
            ->assertStatus(429);
    }
}
