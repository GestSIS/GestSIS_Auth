<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\User;
use Tests\TestCase;

class SessionControllerTest extends TestCase
{
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . TokenTools::createAccessToken($user, [], [], [])];
    }

    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->getJson('/api/v1/sessions');

        $response->assertStatus(401);
    }

    public function testIndexListsOnlyTheOwnersActiveSessions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $active = $user->refreshTokens()->create([
            'token' => TokenTools::hashToken('active-token'),
            'expire' => now()->addDays(30),
            'ip_address' => '10.0.0.1',
            'user_agent' => 'PHPUnit',
            'last_used_at' => now(),
        ]);
        $user->refreshTokens()->create([
            'token' => TokenTools::hashToken('used-token'),
            'expire' => now()->addDays(30),
            'used_at' => now(),
        ]);
        $user->refreshTokens()->create([
            'token' => TokenTools::hashToken('expired-token'),
            'expire' => now()->subDay(),
        ]);
        $otherUser->refreshTokens()->create([
            'token' => TokenTools::hashToken('other-users-token'),
            'expire' => now()->addDays(30),
        ]);

        $response = $this->withHeaders($this->authHeaders($user))->getJson('/api/v1/sessions');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $active->id);
        $response->assertJsonPath('data.0.ip_address', '10.0.0.1');
    }

    public function testDestroyRevokesOnlyTheOwnersSession(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreignSession = $otherUser->refreshTokens()->create([
            'token' => TokenTools::hashToken('foreign-token'),
            'expire' => now()->addDays(30),
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/v1/sessions/{$foreignSession->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('refresh_tokens', ['id' => $foreignSession->id]);
    }

    public function testDestroyRevokesTheOwnSession(): void
    {
        $user = User::factory()->create();
        $session = $user->refreshTokens()->create([
            'token' => TokenTools::hashToken('a-token'),
            'expire' => now()->addDays(30),
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/v1/sessions/{$session->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('refresh_tokens', ['id' => $session->id]);
    }

    /**
     * @return array{accessToken: string, refreshToken: string}
     */
    private function loginSession(User $user): array
    {
        $data = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->json('data');

        return ['accessToken' => $data['accessToken'], 'refreshToken' => $data['refreshToken']];
    }

    public function testIndexFlagsTheCurrentSessionAndShowsTheLoginTimeNotTheLastRefresh(): void
    {
        $user = User::factory()->create();
        $this->travelTo(now()->subHour());
        $current = $this->loginSession($user);
        $this->travelBack();
        $refreshed = $this->postJson('/api/v1/refresh-token', ['token' => $current['refreshToken']])->json('data');
        $this->loginSession($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $refreshed['accessToken']])->getJson('/api/v1/sessions');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $sessions = collect($response->json('data'));
        $currentSession = $sessions->firstWhere('current', true);
        $this->assertNotNull($currentSession);
        $this->assertSame(1, $sessions->where('current', true)->count());
        $this->assertTrue(now()->subMinutes(50)->gt($currentSession['created_at']));
    }

    /**
     * La liste a pu être affichée avant un refresh de l'appareil : révoquer
     * l'ancienne ligne doit couper la session entière, successeur compris.
     */
    public function testDestroyWithAnIdThatHasSinceBeenRotatedRevokesTheSuccessorToo(): void
    {
        $user = User::factory()->create();
        $device = $this->loginSession($user);
        $listedId = $this->withHeaders(['Authorization' => 'Bearer ' . $device['accessToken']])
            ->getJson('/api/v1/sessions')->json('data.0.id');
        $successor = $this->postJson('/api/v1/refresh-token', ['token' => $device['refreshToken']])->json('data.refreshToken');

        $this->withHeaders($this->authHeaders($user))->deleteJson("/api/v1/sessions/{$listedId}")->assertNoContent();

        $this->postJson('/api/v1/refresh-token', ['token' => $successor])->assertStatus(401);
    }
}
