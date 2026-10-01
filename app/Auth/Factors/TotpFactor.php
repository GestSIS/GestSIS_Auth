<?php

namespace App\Auth\Factors;

use App\Models\TwoFactorMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use OTPHP\TOTP;

/**
 * Application d'authentification (TOTP, RFC 6238). Un seul TOTP par compte.
 * L'heure vient de `now()` (et non de l'horloge système) : les tests peuvent
 * avancer le temps (`travel()`) pour passer à la fenêtre suivante.
 */
class TotpFactor implements EnrollableFactor
{
    private const ISSUER = 'GestSIS';
    private const METHOD_NAME = "Application d'authentification";

    public function type(): string
    {
        return TwoFactorMethod::TYPE_TOTP;
    }

    public function isActiveFor(User $user): bool
    {
        return $this->methods($user)->confirmed()->exists();
    }

    public function createChallenge(User $user): ?array
    {
        return null;
    }

    public function verify(User $user, mixed $proof): bool
    {
        $method = $this->methods($user)->confirmed()->first();

        return $method !== null && is_string($proof) && $this->consume($method, $proof);
    }

    /**
     * Génère un nouveau secret (non confirmé). Peut être rappelé tant que le
     * TOTP n'est pas confirmé : le secret précédent est remplacé.
     */
    public function beginEnrollment(User $user): array
    {
        if ($this->isActiveFor($user)) {
            throw new TwoFactorException('Le TOTP est déjà activé sur ce compte', 409);
        }

        $this->methods($user)->whereNull('confirmed_at')->delete();

        $totp = TOTP::generate();
        $totp->setLabel($user->email);
        $totp->setIssuer(self::ISSUER);

        $user->twoFactorMethods()->create([
            'type' => TwoFactorMethod::TYPE_TOTP,
            'name' => self::METHOD_NAME,
            'secret' => $totp->getSecret(),
        ]);

        return [
            'secret' => $totp->getSecret(),
            'provisioningUri' => $totp->getProvisioningUri(),
        ];
    }

    /**
     * Confirme le secret en attente avec un premier code valide.
     */
    public function completeEnrollment(User $user, array $input): TwoFactorMethod
    {
        if ($this->isActiveFor($user)) {
            throw new TwoFactorException('Le TOTP est déjà activé sur ce compte', 409);
        }

        $pending = $this->methods($user)->whereNull('confirmed_at')->latest('id')->first();
        if ($pending === null) {
            throw new TwoFactorException("Aucune configuration TOTP en attente, appelez d'abord 2fa/totp/enable", 422);
        }

        $code = $input['code'] ?? null;
        if (!is_string($code) || !$this->consume($pending, $code)) {
            throw new TwoFactorException('Code invalide', 422);
        }

        $pending->confirmed_at = now();
        $pending->save();

        return $pending;
    }

    public function revoke(User $user, ?int $methodId = null): void
    {
        $this->methods($user)->delete();
    }

    /**
     * Vérifie le code et consomme sa fenêtre de 30 s : un même code ne sert
     * qu'une fois, y compris entre deux requêtes concurrentes (mise à jour
     * conditionnelle atomique).
     */
    private function consume(TwoFactorMethod $method, string $code): bool
    {
        $totp = TOTP::createFromSecret($method->secret);
        $timestamp = now()->getTimestamp();
        if (!$totp->verify($code, $timestamp)) {
            return false;
        }

        $timestep = intdiv($timestamp, $totp->getPeriod());

        return TwoFactorMethod::whereKey($method->getKey())
            ->where(function (Builder $query) use ($timestep) {
                $query->whereNull('last_used_timestep')->orWhere('last_used_timestep', '<', $timestep);
            })
            ->update(['last_used_timestep' => $timestep, 'last_used_at' => now()]) === 1;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<TwoFactorMethod, User>
     */
    private function methods(User $user)
    {
        return $user->twoFactorMethods()->ofType(TwoFactorMethod::TYPE_TOTP);
    }
}
