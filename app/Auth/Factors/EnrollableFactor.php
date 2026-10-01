<?php

namespace App\Auth\Factors;

use App\Models\TwoFactorMethod;
use App\Models\User;

/**
 * Facteur qui s'enrôle en deux temps : préparation (secret TOTP, options de
 * création WebAuthn) puis confirmation par une première preuve valide. La
 * méthode n'est active qu'après confirmation.
 */
interface EnrollableFactor extends SecondFactor
{
    /**
     * @return array<string, mixed> données à renvoyer au client
     *
     * @throws TwoFactorException
     */
    public function beginEnrollment(User $user): array;

    /**
     * @param array<string, mixed> $input
     *
     * @throws TwoFactorException
     */
    public function completeEnrollment(User $user, array $input): TwoFactorMethod;
}
