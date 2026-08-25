<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Mail\ResetPassword;
use App\Models\PasswordResetToken;
use App\Models\RefreshToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    public function testRequestSendsEmailAndStoresHashedTokenForExistingUser(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/forgotten-password', [
            'email' => $user->email,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Un email a été envoyé si cette adresse email existe.');

        Mail::assertSent(ResetPassword::class);

        $this->assertDatabaseCount('password_reset_tokens', 1);
        $resetToken = PasswordResetToken::first();
        $this->assertSame($user->id, $resetToken->user_id);
        // The stored token must be the SHA-256 hash, never the plaintext token.
        $this->assertSame(64, strlen($resetToken->token));
        $this->assertTrue(Carbon::parse($resetToken->validite)->isFuture());
    }

    public function testRequestReturnsGenericMessageAndSendsNoMailForUnknownEmail(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/forgotten-password', [
            'email' => 'no-such-user@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Un email a été envoyé si cette adresse email existe.');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function testRequestRejectsMissingEmail(): void
    {
        $response = $this->postJson('/api/v1/forgotten-password', []);

        $response->assertStatus(401);
    }

    public function testResetWithValidTokenUpdatesPasswordAndRevokesSessions(): void
    {
        $user = User::factory()->create();
        $refreshToken = new RefreshToken();
        $refreshToken->token = TokenTools::hashToken('some-refresh-token');
        $refreshToken->expire = Carbon::now()->addDays(30);
        $user->refreshTokens()->save($refreshToken);

        $plainToken = TokenTools::createResetToken();
        PasswordResetToken::create([
            'token' => TokenTools::hashToken($plainToken->token),
            'user_id' => $user->id,
            'validite' => $plainToken->expire,
        ]);

        $response = $this->postJson('/api/v1/reset-password', [
            'token' => $plainToken->token,
            'password' => 'un-nouveau-mot-de-passe',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Mot de passe réinitialisé avec succès');

        $user->refresh();
        $this->assertTrue(Hash::check('un-nouveau-mot-de-passe', $user->password));

        // Token must be single-use.
        $this->assertDatabaseMissing('password_reset_tokens', ['user_id' => $user->id]);

        // All existing sessions must be invalidated.
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function testResetWithAlreadyUsedTokenIsRejectedOnSecondAttempt(): void
    {
        $user = User::factory()->create();
        $plainToken = TokenTools::createResetToken();
        PasswordResetToken::create([
            'token' => TokenTools::hashToken($plainToken->token),
            'user_id' => $user->id,
            'validite' => $plainToken->expire,
        ]);

        $payload = ['token' => $plainToken->token, 'password' => 'premiere-tentative-ok'];

        $this->postJson('/api/v1/reset-password', $payload)->assertStatus(200);

        $secondResponse = $this->postJson('/api/v1/reset-password', [
            'token' => $plainToken->token,
            'password' => 'deuxieme-tentative-ok',
        ]);

        $secondResponse->assertStatus(401);
        $secondResponse->assertJsonPath('error.message', 'Jeton invalide ou déjà utilisé');
    }

    public function testResetWithExpiredTokenIsRejected(): void
    {
        $user = User::factory()->create();
        $plainToken = TokenTools::createResetToken();
        PasswordResetToken::create([
            'token' => TokenTools::hashToken($plainToken->token),
            'user_id' => $user->id,
            'validite' => Carbon::now()->subMinute(),
        ]);

        $response = $this->postJson('/api/v1/reset-password', [
            'token' => $plainToken->token,
            'password' => 'un-nouveau-mot-de-passe',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.message', 'Jeton invalide ou déjà utilisé');

        $user->refresh();
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function testResetWithUnknownTokenIsRejected(): void
    {
        $response = $this->postJson('/api/v1/reset-password', [
            'token' => bin2hex(random_bytes(32)),
            'password' => 'un-nouveau-mot-de-passe',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.message', 'Jeton invalide ou déjà utilisé');
    }

    public function testResetRejectsPasswordShorterThanTwelveCharacters(): void
    {
        $user = User::factory()->create();
        $plainToken = TokenTools::createResetToken();
        PasswordResetToken::create([
            'token' => TokenTools::hashToken($plainToken->token),
            'user_id' => $user->id,
            'validite' => $plainToken->expire,
        ]);

        $response = $this->postJson('/api/v1/reset-password', [
            'token' => $plainToken->token,
            'password' => 'court',
        ]);

        $response->assertStatus(401);

        // Token must still be usable since it was never consumed.
        $this->assertDatabaseHas('password_reset_tokens', ['user_id' => $user->id]);
    }
}
