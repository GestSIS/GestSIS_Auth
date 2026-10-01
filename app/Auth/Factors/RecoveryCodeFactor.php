<?php

namespace App\Auth\Factors;

use App\Models\TwoFactorRecoveryCode;
use App\Models\User;

/**
 * Codes de secours : filet de secours du compte, pas une méthode à part
 * entière — ils ne comptent jamais comme « le compte a du 2FA ». Émis à la
 * première méthode confirmée, purgés quand la dernière méthode est retirée
 * (voir TwoFactorManager).
 */
class RecoveryCodeFactor implements SecondFactor
{
    public const TYPE = 'recovery_code';

    public function type(): string
    {
        return self::TYPE;
    }

    public function isActiveFor(User $user): bool
    {
        return $user->twoFactorRecoveryCodes()->whereNull('used_at')->exists();
    }

    public function createChallenge(User $user): ?array
    {
        return null;
    }

    public function verify(User $user, mixed $proof): bool
    {
        return is_string($proof) && TwoFactorRecoveryCode::redeem($user, $proof);
    }

    public function revoke(User $user, ?int $methodId = null): void
    {
        $user->twoFactorRecoveryCodes()->delete();
    }

    /**
     * Remplace tous les codes du compte ; renvoie les nouveaux en clair (à
     * afficher une seule fois).
     *
     * @return list<string>
     */
    public function regenerate(User $user): array
    {
        return TwoFactorRecoveryCode::regenerateFor($user);
    }
}
