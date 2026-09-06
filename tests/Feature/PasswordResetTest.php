<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Mail\ResetPassword;
use App\Models\ApiToken;
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

        // All existing sessions of this user must be invalidated (scoped to the user:
        // the test DB is shared with the dev stack, which may hold live sessions).
        $this->assertDatabaseMissing('refresh_tokens', ['user_id' => $user->id]);
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

    private function createApiTokenFor(User $user, string $name): string
    {
        $tokenData = TokenTools::createApiToken(30);
        ApiToken::create([
            'user_id' => $user->id,
            'name' => $name,
            'token' => TokenTools::hashToken($tokenData->token),
            'expires_at' => $tokenData->expire,
        ]);

        return $tokenData->token;
    }

    public function testResetRevokesApiTokensAndTellsTheUserWhichOnes(): void
    {
        $user = User::factory()->create();
        $plainApiToken = $this->createApiTokenFor($user, 'Integration comptable');
        $this->createApiTokenFor($user, 'Export calendrier');

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
        $this->assertEqualsCanonicalizing(
            ['Integration comptable', 'Export calendrier'],
            $response->json('revoked_api_tokens')
        );
        $this->assertStringContainsString('jetons d\'API ont été révoqués', $response->json('message'));
        $this->assertStringContainsString('Integration comptable', $response->json('message'));

        // Les jetons restent listés (révocation douce) avec la raison...
        $this->assertSame(2, ApiToken::where('user_id', $user->id)->whereNotNull('revoked_at')
            ->where('revoked_reason', ApiToken::REASON_PASSWORD_RESET)->count());

        // ...mais ne sont plus échangeables contre un JWT.
        $exchange = $this->postJson('/api/v1/token-auth', ['token' => $plainApiToken]);
        $exchange->assertStatus(401);
        $this->assertStringContainsString('réinitialisation du mot de passe', $exchange->json('error'));
    }

    public function testResetWithoutApiTokensReturnsPlainMessage(): void
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
            'password' => 'un-nouveau-mot-de-passe',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Mot de passe réinitialisé avec succès');
        $response->assertJsonPath('revoked_api_tokens', []);
    }

    public function testChangingPasswordWithOldPasswordKeepsApiTokens(): void
    {
        // Admin : l'échange token-auth ne recontrôle pas le sous-ensemble de permissions.
        $user = User::factory()->create(['admin' => true, 'password' => Hash::make('ancien-mot-de-passe-ok')]);
        $plainApiToken = $this->createApiTokenFor($user, 'Integration comptable');

        $response = $this->postJson('/api/v1/change-password', [
            'email' => $user->email,
            'password' => 'ancien-mot-de-passe-ok',
            'new_password' => 'nouveau-mot-de-passe-ok',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('api_tokens', ['user_id' => $user->id, 'revoked_at' => null]);

        // L'utilisateur a prouvé qu'il contrôle le compte : ses intégrations continuent de fonctionner.
        $this->postJson('/api/v1/token-auth', ['token' => $plainApiToken])->assertStatus(200);
    }
}
