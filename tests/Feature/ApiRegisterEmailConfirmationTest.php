<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\RegisterToken;
use App\Models\TwoFactorPolicy;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * L'inscription n'émet plus de session immédiatement : l'email doit d'abord
 * être confirmé via un code à 6 chiffres, saisi dans le même flow (remplace
 * l'ancien lien à jeton). C'est seulement une fois l'email confirmé que la
 * même décision qu'au login (LoginResponder::respondAfterAuthentication)
 * s'applique — session complète, ou étape de configuration 2FA si
 * l'enforcement est actif.
 */
class ApiRegisterEmailConfirmationTest extends TestCase
{
    private function registerViaInviteToken(string $email = 'nouveau@example.com'): void
    {
        Mail::fake();
        RegisterToken::create([
            'token' => TokenTools::hashToken('un-jeton-invitation'),
            'description' => 'Test',
            'validite' => now()->addDay(),
        ]);

        $this->postJson('/api/v1/register', [
            'name' => 'Nouveau Sapeur',
            'email' => $email,
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
            'token' => 'un-jeton-invitation',
        ]);
    }

    public function testRegisteringDoesNotIssueASessionAndAsksForEmailConfirmation(): void
    {
        $this->registerViaInviteToken('nouveau@example.com');

        $user = User::where('email', 'nouveau@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->validate_email_token);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Doublon',
            'email' => 'autre@example.com',
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
            'token' => 'un-jeton-invitation-inexistant',
        ]);
        // Jeton d'invitation invalide : juste pour vérifier qu'aucune session
        // n'apparaît même dans une réponse d'erreur.
        $this->assertArrayNotHasKey('accessToken', $response->json() ?? []);
    }

    public function testConfirmingWithTheCorrectCodeVerifiesTheEmailAndIssuesASession(): void
    {
        $this->registerViaInviteToken('confirme-moi@example.com');
        $user = User::where('email', 'confirme-moi@example.com')->first();
        $plainCode = $this->extractSentCode();

        $response = $this->postJson('/api/v1/confirmer-email', [
            'email' => 'confirme-moi@example.com',
            'code' => $plainCode,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.accessToken'));
        $this->assertNotEmpty($response->json('data.refreshToken'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull($user->fresh()->validate_email_token);
    }

    public function testConfirmingWithAnIncorrectCodeFails(): void
    {
        $this->registerViaInviteToken('mauvais-code@example.com');

        $response = $this->postJson('/api/v1/confirmer-email', [
            'email' => 'mauvais-code@example.com',
            'code' => '000000',
        ]);

        $response->assertStatus(401);
        $this->assertNull(User::where('email', 'mauvais-code@example.com')->first()->email_verified_at);
    }

    public function testConfirmingWithTheCodeOfADifferentEmailFails(): void
    {
        $this->registerViaInviteToken('victime@example.com');
        $plainCode = $this->extractSentCode();

        $response = $this->postJson('/api/v1/confirmer-email', [
            'email' => 'attaquant@example.com',
            'code' => $plainCode,
        ]);

        $response->assertStatus(401);
    }

    public function testConfirmingWhileEnforcementIsActiveReturnsTheForcedSetupStepInstead(): void
    {
        config(['gestsis.two_factor_enforcement_enabled' => true]);
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->subDay();
        $policy->save();

        $this->registerViaInviteToken('doit-configurer-2fa@example.com');
        $plainCode = $this->extractSentCode();

        $response = $this->postJson('/api/v1/confirmer-email', [
            'email' => 'doit-configurer-2fa@example.com',
            'code' => $plainCode,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.requiresTwoFactorSetup', true);
        $this->assertNotEmpty($response->json('data.setupToken'));
        $this->assertArrayNotHasKey('accessToken', $response->json('data'));
        $this->assertNotNull(User::where('email', 'doit-configurer-2fa@example.com')->first()->email_verified_at);
    }

    public function testResendConfirmationWorksWithoutAnyToken(): void
    {
        $this->registerViaInviteToken('renvoi@example.com');
        $user = User::where('email', 'renvoi@example.com')->first();
        $originalHash = $user->validate_email_token;

        $response = $this->postJson('/api/v1/resend-confirmation', ['email' => 'renvoi@example.com']);

        $response->assertOk();
        $this->assertNotSame($originalHash, $user->fresh()->validate_email_token);
    }

    public function testResendConfirmationDoesNotResendForAnAlreadyVerifiedEmail(): void
    {
        $this->registerViaInviteToken('deja-verifie@example.com');
        $user = User::where('email', 'deja-verifie@example.com')->first();
        $user->email_verified_at = now();
        $user->save();
        $originalHash = $user->validate_email_token;

        $response = $this->postJson('/api/v1/resend-confirmation', ['email' => 'deja-verifie@example.com']);

        $response->assertOk();
        $this->assertSame($originalHash, $user->fresh()->validate_email_token);
    }

    public function testResendConfirmationGivesTheSameGenericResponseForAnUnknownEmail(): void
    {
        $response = $this->postJson('/api/v1/resend-confirmation', ['email' => 'inconnu@example.com']);

        $response->assertOk();
        $response->assertJsonPath(
            'message',
            'Un nouveau code a été envoyé si cette adresse email existe et n\'est pas encore confirmée.',
        );
    }

    /**
     * Limite par compte (en plus de la limite par IP de la route) : le code
     * est invalidé après 5 échecs, un nouveau code doit être demandé.
     */
    public function testTheCodeIsInvalidatedAfterTooManyFailedAttempts(): void
    {
        $this->registerViaInviteToken('brute-force@example.com');
        $plainCode = $this->extractSentCode();
        $wrongCode = $plainCode === '000000' ? '111111' : '000000';

        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/confirmer-email', ['email' => 'brute-force@example.com', 'code' => $wrongCode])
                ->assertStatus(401);
        }
        $this->postJson('/api/v1/confirmer-email', ['email' => 'brute-force@example.com', 'code' => $wrongCode])
            ->assertStatus(429);

        $this->postJson('/api/v1/confirmer-email', ['email' => 'brute-force@example.com', 'code' => $plainCode])
            ->assertStatus(401);
        $this->assertNull(User::where('email', 'brute-force@example.com')->first()->email_verified_at);
    }

    public function testResendingACodeResetsTheFailedAttemptsCounter(): void
    {
        $this->registerViaInviteToken('renvoi@example.com');
        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/confirmer-email', ['email' => 'renvoi@example.com', 'code' => 'faux-code']);
        }

        Mail::fake();
        $this->postJson('/api/v1/resend-confirmation', ['email' => 'renvoi@example.com'])->assertOk();
        $newCode = $this->extractSentCode();

        $this->postJson('/api/v1/confirmer-email', ['email' => 'renvoi@example.com', 'code' => 'faux-code'])
            ->assertStatus(401);
        $this->postJson('/api/v1/confirmer-email', ['email' => 'renvoi@example.com', 'code' => $newCode])
            ->assertOk();
    }

    /**
     * Pas de plafond par compte : un tiers qui connaît l'email et épuise les
     * essais en boucle ne doit pas pouvoir bloquer l'activation — le
     * propriétaire redemande un code et confirme normalement.
     */
    public function testATiersCannotLockTheOwnerOutByBurningAttempts(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->registerViaInviteToken('boucle@example.com');

        for ($round = 1; $round <= 3; $round++) {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $this->postJson('/api/v1/confirmer-email', ['email' => 'boucle@example.com', 'code' => 'FAUXCODE']);
            }
            $this->postJson('/api/v1/resend-confirmation', ['email' => 'boucle@example.com'])->assertOk();
        }

        Mail::fake();
        $this->postJson('/api/v1/resend-confirmation', ['email' => 'boucle@example.com'])->assertOk();
        $this->postJson('/api/v1/confirmer-email', ['email' => 'boucle@example.com', 'code' => $this->extractSentCode()])
            ->assertOk();
    }

    public function testTheCodeHasEightUnambiguousCharacters(): void
    {
        $this->registerViaInviteToken('format@example.com');

        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{8}$/', $this->extractSentCode());
    }

    public function testTheCodeIsAcceptedRegardlessOfCaseAndSeparators(): void
    {
        $this->registerViaInviteToken('saisie@example.com');
        $code = $this->extractSentCode();

        $this->postJson('/api/v1/confirmer-email', [
            'email' => 'saisie@example.com',
            'code' => ' ' . strtolower(substr($code, 0, 4)) . '-' . strtolower(substr($code, 4)) . ' ',
        ])->assertOk();
    }

    /**
     * Compte inscrit avant le passage au code à 6 chiffres : son jeton est un
     * hash SHA-256, que Hash::check (bcrypt) refuse par une exception.
     */
    public function testALegacyNonBcryptTokenIsRejectedCleanlyInsteadOfCrashing(): void
    {
        $user = User::factory()->unverified()->create();
        $user->forceFill([
            'validate_email_token' => hash('sha256', 'ancien-jeton'),
            'validate_email_expire' => now()->addMinutes(30),
        ])->save();

        $this->postJson('/api/v1/confirmer-email', ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(401);
    }

    public function testLoginIsRefusedUntilTheEmailIsConfirmed(): void
    {
        $this->registerViaInviteToken('pas-confirme@example.com');

        $response = $this->postJson('/api/v1/login', [
            'email' => 'pas-confirme@example.com',
            'password' => 'a-very-long-password',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('requiresEmailConfirmation', true);
        $this->assertArrayNotHasKey('accessToken', $response->json('data') ?? []);
    }

    public function testLoginOfAnUnconfirmedAccountWithAWrongPasswordStaysAGenericFailure(): void
    {
        $this->registerViaInviteToken('pas-confirme-2@example.com');

        $response = $this->postJson('/api/v1/login', [
            'email' => 'pas-confirme-2@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $this->assertNull($response->json('requiresEmailConfirmation'));
    }

    private function extractSentCode(): string
    {
        $captured = null;
        Mail::assertSent(\App\Mail\ConfirmationEmail::class, function ($mail) use (&$captured) {
            $captured = $mail->code;
            return true;
        });

        $this->assertNotNull($captured);

        return $captured;
    }
}
