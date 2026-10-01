<?php

namespace App\Auth;

use App\Models\AuthSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ouverture et renouvellement des sessions, et émission de leurs refresh
 * tokens signés.
 */
class AuthSessionService
{
    public function __construct(private readonly RefreshTokenCodec $codec)
    {
    }

    /**
     * Ouvre une session. `$startedAt` ne sert qu'à la reprise d'une session
     * d'avant cette version (sa vraie date de début).
     *
     * @return array{session: AuthSession, refreshToken: string}
     */
    public function start(User $user, ?Request $request, bool $remember, ?CarbonInterface $startedAt = null): array
    {
        $session = new AuthSession([
            'user_id' => $user->id,
            'remember' => $remember,
            'started_at' => $startedAt ?? now(),
            'last_refreshed_at' => now(),
            'ip_address' => $request?->ip(),
            'user_agent' => self::userAgent($request),
            // Une session n'est ouverte à un compte protégé qu'après son second
            // facteur (challenge au login ou configuration imposée).
            'two_factor_verified_at' => $user->hasTwoFactorEnabled() ? now() : null,
        ]);
        $session->idle_expires_at = $session->nextIdleExpiry();
        $session->save();
        $session->setRelation('user', $user);

        return ['session' => $session, 'refreshToken' => $this->refreshTokenFor($session)];
    }

    /**
     * Passe à la génération suivante : le refresh token précédent devient un
     * rejeu. À appeler sous le verrou de la session (ApiRefreshTokenController).
     */
    public function rotate(AuthSession $session, Request $request): string
    {
        $session->refresh_generation++;
        $session->last_refreshed_at = now();
        $session->idle_expires_at = $session->nextIdleExpiry();
        $session->ip_address = $request->ip();
        $session->user_agent = self::userAgent($request);
        $session->save();

        return $this->refreshTokenFor($session);
    }

    public function refreshTokenFor(AuthSession $session): string
    {
        return $this->codec->encode($session, $session->refreshTokenExpiry());
    }

    private static function userAgent(?Request $request): ?string
    {
        $userAgent = $request?->userAgent();

        return $userAgent === null ? null : Str::limit($userAgent, 255, '');
    }
}
