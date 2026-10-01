<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Les endpoints "message générique" de l'API Auth (login, confirmer-email,
 * refresh-token, token-auth, use-token, change-password) doivent renvoyer
 * leurs erreurs sous une forme homogène selon le type d'échec :
 * - 422 (requête mal formée) : {"message": "...", "errors": {champ: [messages]}},
 *   le rendu natif de Laravel pour ValidationException (aucun handler custom).
 * - 401/403 (identifiants ou jeton invalides) : {"message": "<texte>"}.
 */
class AuthErrorResponseFormatTest extends TestCase
{
    public function testLoginWithMissingFieldsReturns422WithFieldErrors(): void
    {
        $response = $this->postJson('/api/v1/login', []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.email.0', 'The email field is required.');
        $response->assertJsonPath('errors.password.0', 'The password field is required.');
    }

    public function testLoginWithWrongPasswordReturns401WithMessage(): void
    {
        $user = User::factory()->create(['password' => Hash::make('un-bon-mot-de-passe')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'un-mauvais-mot-de-passe',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Les identifiants fournis sont incorrects');
    }

    public function testConfirmerEmailWithMissingFieldsReturns422WithFieldErrors(): void
    {
        $response = $this->postJson('/api/v1/confirmer-email', []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.email.0', 'The email field is required.');
        $response->assertJsonPath('errors.code.0', 'The code field is required.');
    }

    public function testConfirmerEmailWithInvalidCodeReturns401WithMessage(): void
    {
        $response = $this->postJson('/api/v1/confirmer-email', [
            'email' => 'inconnu@example.com',
            'code' => '000000',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath(
            'message',
            'Code de confirmation invalide, expiré ou déjà utilisé.'
        );
    }

    public function testRefreshTokenWithMissingTokenReturns422WithFieldErrors(): void
    {
        $response = $this->postJson('/api/v1/refresh-token', []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.token.0', 'The token field is required.');
    }

    public function testRefreshTokenWithUnknownTokenReturns401WithMessage(): void
    {
        $response = $this->postJson('/api/v1/refresh-token', ['token' => 'un-jeton-inconnu']);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Refresh token expired');
    }

    public function testTokenAuthWithMissingTokenReturns422WithFieldErrors(): void
    {
        $response = $this->postJson('/api/v1/token-auth', []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.token.0', 'The token field is required.');
    }

    public function testTokenAuthWithUnknownTokenReturns401WithMessage(): void
    {
        $response = $this->postJson('/api/v1/token-auth', ['token' => 'un-jeton-inconnu']);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Jeton API invalide ou expiré');
    }

    public function testUseTokenWithMissingTokenReturns422WithFieldErrors(): void
    {
        $user = User::factory()->create();
        $bearerToken = TokenTools::createAccessToken($user, [], [], []);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $bearerToken])
            ->postJson('/api/v1/use-token', []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.token.0', 'The token field is required.');
    }

    public function testUseTokenWithInvalidTokenReturns401WithMessage(): void
    {
        $user = User::factory()->create();
        $bearerToken = TokenTools::createAccessToken($user, [], [], []);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $bearerToken])
            ->postJson('/api/v1/use-token', ['token' => 'un-jeton-invalide']);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Token invalide');
    }

    public function testChangePasswordWithMissingFieldsReturns422WithFieldErrors(): void
    {
        $response = $this->postJson('/api/v1/change-password', []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.email.0', 'The email field is required.');
    }

    public function testChangePasswordWithWrongCredentialsReturns401WithMessage(): void
    {
        $user = User::factory()->create(['password' => Hash::make('un-bon-mot-de-passe')]);

        $response = $this->postJson('/api/v1/change-password', [
            'email' => $user->email,
            'password' => 'un-mauvais-mot-de-passe',
            'new_password' => 'un-nouveau-mot-de-passe',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Identifiants invalides');
    }
}
