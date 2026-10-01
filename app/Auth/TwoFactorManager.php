<?php

namespace App\Auth;

use App\Auth\Factors\EnrollableFactor;
use App\Auth\Factors\RecoveryCodeFactor;
use App\Auth\Factors\SecondFactor;
use App\Auth\Factors\TotpFactor;
use App\Auth\Factors\WebauthnFactor;
use App\Models\TwoFactorMethod;
use App\Models\User;

/**
 * Point d'entrée unique du 2FA pour le reste de l'application : méthodes
 * actives, vérification d'un code, enrôlement et retrait d'une méthode, avec
 * les règles transverses (codes de secours, révocation des sessions émises
 * sans second facteur).
 */
class TwoFactorManager
{
    public function __construct(
        private readonly TotpFactor $totp,
        private readonly WebauthnFactor $webauthn,
        private readonly RecoveryCodeFactor $recoveryCodes,
    ) {
    }

    public function totp(): TotpFactor
    {
        return $this->totp;
    }

    public function webauthn(): WebauthnFactor
    {
        return $this->webauthn;
    }

    public function recoveryCodes(): RecoveryCodeFactor
    {
        return $this->recoveryCodes;
    }

    /**
     * Méthodes confirmées du compte, dans un ordre stable (pour le choix
     * proposé au login).
     *
     * @return list<string>
     */
    public function availableMethods(User $user): array
    {
        $types = $user->twoFactorMethods()->confirmed()->distinct()->pluck('type')->all();

        return array_values(array_filter(
            [TwoFactorMethod::TYPE_TOTP, TwoFactorMethod::TYPE_WEBAUTHN],
            fn (string $type) => in_array($type, $types, true),
        ));
    }

    /**
     * Code saisi par l'utilisateur : code TOTP (si une application est
     * confirmée) ou code de secours.
     */
    public function verifyCode(User $user, string $code): bool
    {
        return $this->totp->verify($user, $code) || $this->recoveryCodes->verify($user, $code);
    }

    /**
     * Confirme une méthode. Si c'est la première du compte : émet les codes de
     * secours et révoque les sessions ouvertes sans second facteur, sauf la
     * session courante (`$currentSessionId`) qui vient de l'activer.
     *
     * @param array<string, mixed> $input
     * @return array{method: TwoFactorMethod, recoveryCodes: list<string>|null}
     */
    public function enroll(User $user, EnrollableFactor $factor, array $input, ?string $currentSessionId): array
    {
        $wasFirstMethod = !$user->hasTwoFactorEnabled();
        $method = $factor->completeEnrollment($user, $input);

        if (!$wasFirstMethod) {
            return ['method' => $method, 'recoveryCodes' => null];
        }

        $recoveryCodes = $this->recoveryCodes->regenerate($user);
        $user->revokeAllSessions($currentSessionId);

        return ['method' => $method, 'recoveryCodes' => $recoveryCodes];
    }

    /**
     * Retire une méthode ; quand c'était la dernière, les codes de secours
     * n'ont plus lieu d'être (régénérés à la prochaine méthode confirmée).
     */
    public function revoke(User $user, SecondFactor $factor, ?int $methodId = null): void
    {
        $factor->revoke($user, $methodId);

        if (!$user->hasTwoFactorEnabled()) {
            $this->recoveryCodes->revoke($user);
        }
    }
}
