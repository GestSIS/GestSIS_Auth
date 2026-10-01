<?php

namespace App\Auth\Factors;

use App\Models\User;

/**
 * Un second facteur d'authentification (application TOTP, clé WebAuthn, codes
 * de secours). Le login, la ré-authentification et la désactivation passent
 * tous par cette interface (via TwoFactorManager) : une seule définition de ce
 * qui est actif et de ce qui est accepté.
 */
interface SecondFactor
{
    public function type(): string;

    /**
     * Au moins une méthode de ce type est confirmée sur le compte.
     */
    public function isActiveFor(User $user): bool;

    /**
     * Prépare une vérification (challenge WebAuthn, à renvoyer au client) ;
     * null si le facteur n'en a pas besoin.
     *
     * @return array<string, mixed>|null
     *
     * @throws TwoFactorException
     */
    public function createChallenge(User $user): ?array;

    /**
     * Vérifie la preuve et la consomme (anti-rejeu TOTP, usage unique d'un
     * code de secours, compteur WebAuthn). Seules les méthodes confirmées sont
     * acceptées.
     *
     * @throws TwoFactorException pour une erreur à remonter telle quelle au client
     */
    public function verify(User $user, mixed $proof): bool;

    /**
     * Retire la méthode `$methodId`, ou toutes les méthodes de ce type.
     */
    public function revoke(User $user, ?int $methodId = null): void;
}
