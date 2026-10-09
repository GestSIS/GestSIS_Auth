<?php

namespace Tests;

use App\Auth\AuthSessionService;
use App\Auth\RefreshTokenCodec;
use App\Models\AuthSession;
use App\Models\TwoFactorMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use OTPHP\TOTP;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Pas d'appel réseau à Have I Been Pwned (règle `uncompromised()` de
        // PasswordPolicy) : tout mot de passe est considéré comme sain, sauf
        // test qui appelle markPasswordsAsCompromised().
        $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier {
            public function verify($data)
            {
                return true;
            }
        });
    }

    protected function markPasswordsAsCompromised(): void
    {
        $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier {
            public function verify($data)
            {
                return false;
            }
        });
    }

    /**
     * Code TOTP à l'heure de l'application (`now()`, que `travel()` déplace),
     * comme le vérifie TotpFactor.
     */
    protected function totpCode(string $secret): string
    {
        return TOTP::createFromSecret($secret)->at(now()->getTimestamp());
    }

    /**
     * Un code TOTP ne sert qu'une fois par fenêtre de 30 s : avance le temps
     * jusqu'à la fenêtre suivante pour pouvoir en présenter un nouveau.
     */
    protected function nextTotpWindow(): void
    {
        $this->travel(30)->seconds();
    }

    /**
     * Secret de l'application TOTP du compte (confirmée ou en cours d'enrôlement).
     */
    protected function totpSecretOf(User $user): ?string
    {
        return $user->twoFactorMethods()->ofType(TwoFactorMethod::TYPE_TOTP)->latest('id')->first()?->secret;
    }

    protected function hasConfirmedTotp(User $user): bool
    {
        return $user->twoFactorMethods()->ofType(TwoFactorMethod::TYPE_TOTP)->confirmed()->exists();
    }

    /**
     * Ouvre une session pour le compte et renvoie son refresh token signé.
     *
     * @param array<string, mixed> $attributes surcharge de la session (ex. `started_at`, `remember`)
     * @return array{0: string, 1: AuthSession}
     */
    protected function issueSession(User $user, array $attributes = []): array
    {
        $session = AuthSession::factory()->create(['user_id' => $user->id, ...$attributes]);
        $session->setRelation('user', $user);

        return [app(AuthSessionService::class)->refreshTokenFor($session), $session];
    }

    /**
     * Session désignée par un refresh token signé (null si le jeton est invalide).
     */
    protected function sessionOf(string $refreshToken): ?AuthSession
    {
        $claims = app(RefreshTokenCodec::class)->decode($refreshToken);

        return $claims === null ? null : AuthSession::find($claims['sid']);
    }
}
