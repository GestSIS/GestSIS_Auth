<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\RefreshToken;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Le claim `sapeurs` du JWT ne doit jamais être émis pour un compte dont
 * l'email n'est pas vérifié : l'inscription accepte tout email connu de
 * l'API, donc un lien sapeur sur un compte non vérifié n'est pas une preuve
 * d'identité.
 */
class UserSapeursClaimTest extends TestCase
{
    private function linkSapeur(User $user, Sis $sis, int $sapeurId): void
    {
        Sapeur::insert([
            'sapeur_id' => $sapeurId,
            'sis_id' => $sis->id,
            'user_id' => $user->id,
        ]);
    }

    public function testGetSapeursIsEmptyForUnverifiedAccount(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->unverified()->create();
        $this->linkSapeur($user, $sis, 42);

        $this->assertSame([], User::getSapeurs($user->id));
    }

    public function testGetSapeursReturnsLinksOnceEmailIsVerified(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->unverified()->create();
        $this->linkSapeur($user, $sis, 42);

        $user->email_verified_at = Carbon::now();
        $user->save();

        $this->assertSame(['test' => 42], User::getSapeurs($user->id));
    }

    public function testRefreshTokenDoesNotGrantSapeurIdentityToUnverifiedAccount(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->unverified()->create();
        $this->linkSapeur($user, $sis, 42);

        $plain = 'refresh-token-unverified';
        $refreshToken = new RefreshToken();
        $refreshToken->token = TokenTools::hashToken($plain);
        $refreshToken->expire = Carbon::now()->addDay();
        $refreshToken->user_id = $user->id;
        $refreshToken->save();

        $response = $this->postJson('/api/v1/refresh-token', ['token' => $plain]);
        $response->assertOk();

        $claims = TokenTools::validateToken($response->json('accessToken'));
        $this->assertFalse($claims->data->validated);
        $this->assertSame([], (array) $claims->data->sapeurs);
    }
}
