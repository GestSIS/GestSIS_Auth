<?php

namespace App\Http\Controllers;

use App\Auth\AuthSessionService;
use App\Auth\LoginResponder;
use App\Auth\RefreshTokenCodec;
use App\Auth\TokenTools;
use App\Models\AuthSession;
use App\Models\LegacyRefreshToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Renouvelle l'accès d'une session à partir de son refresh token signé
 * (RefreshTokenCodec) : chaque refresh passe la session à la génération
 * suivante, un jeton d'une génération dépassée est un rejeu et révoque la
 * session.
 */
class ApiRefreshTokenController extends Controller
{
    // Absorbe la course bénigne entre deux onglets/requêtes qui renouvellent
    // le même refresh token quasi simultanément : dans cette fenêtre, le jeton
    // déjà consommé reçoit la même réponse plutôt que d'être pris pour un vol.
    private const REUSE_GRACE_PERIOD_SECONDS = 10;

    public function __construct(
        private readonly RefreshTokenCodec $codec,
        private readonly AuthSessionService $sessions,
    ) {
    }

    public function refresh(Request $request): JsonResponse
    {
        Validator::make($request->all(), ['token' => ['required', 'string']])->validate();
        $presentedToken = $request->input('token');

        if (RefreshTokenCodec::isLegacyToken($presentedToken)) {
            return $this->refreshLegacy($presentedToken, $request);
        }

        // Signature invalide (jeton fabriqué ou modifié) : simple refus, sans
        // rien révoquer — personne ne peut forcer la déconnexion d'autrui.
        $claims = $this->codec->decode($presentedToken);
        if ($claims === null) {
            return $this->unauthorized($request);
        }

        // Un seul renouvellement à la fois par session : le second de deux
        // refresh simultanés trouve ainsi la réponse du premier.
        return Cache::lock("auth-session-refresh:{$claims['sid']}", 10)
            ->block(5, fn () => $this->refreshSession($claims, $presentedToken, $request));
    }

    /**
     * @param array{sid: string, gen: int, exp: int} $claims
     */
    private function refreshSession(array $claims, string $presentedToken, Request $request): JsonResponse
    {
        $cached = Cache::get(self::graceCacheKey($presentedToken));
        if ($cached !== null) {
            return $this->respond($cached);
        }

        $session = AuthSession::with('user')->find($claims['sid']);
        if ($session === null) {
            return $this->unauthorized($request);
        }

        if ($claims['gen'] !== $session->refresh_generation) {
            // Jeton authentique mais déjà renouvelé, hors fenêtre de grâce :
            // impossible de distinguer l'utilisateur légitime d'un attaquant
            // qui l'aurait intercepté — la session est révoquée pour forcer
            // une reconnexion complète.
            Log::warning('Refresh token reuse detected, revoking session', [
                'user_id' => $session->user_id,
                'ip' => $request->ip(),
            ]);
            $session->delete();

            return $this->unauthorized($request);
        }

        // Défense en profondeur : la désactivation supprime les sessions, mais
        // un compte désactivé ne doit en aucun cas obtenir un access token.
        if ($session->user === null || $session->user->disabled_at !== null) {
            $session->delete();

            return $this->unauthorized($request);
        }

        // Durée maximale depuis le login (30 jours par défaut, 90 pour
        // certains comptes) : sans elle, une session utilisée au moins une fois
        // par mois ne se terminait jamais.
        if ($session->deadline()->isPast()) {
            $session->delete();
            Log::info('Session revoked at refresh: maximum lifetime reached', [
                'user_id' => $session->user_id,
                'session_max_days' => $session->user->sessionMaxDays(),
            ]);

            return response()->json(['message' => 'Session expirée, veuillez vous reconnecter'], 401);
        }

        if ($session->idle_expires_at->isPast() || $claims['exp'] < now()->getTimestamp()) {
            return $this->unauthorized($request);
        }

        // Session ouverte avant l'échéance d'obligation 2FA (ou pendant une
        // exemption expirée depuis) : sans ce contrôle, le refresh la
        // prolongerait sans second facteur. Le client repasse par /login, qui
        // impose alors la configuration.
        if (LoginResponder::mustSetUpTwoFactor($session->user)) {
            $session->user->revokeAllSessions();
            Log::info('Session revoked at refresh: 2FA setup required', ['user_id' => $session->user_id]);

            return response()->json(['message' => 'Double authentification requise, veuillez vous reconnecter'], 401);
        }

        return $this->issue($session, $this->sessions->rotate($session, $request), $presentedToken);
    }

    /**
     * Jeton d'avant les sessions signées : échangé une fois contre une session,
     * sans déconnexion. À supprimer avec LegacyRefreshToken.
     */
    private function refreshLegacy(string $presentedToken, Request $request): JsonResponse
    {
        $hashedToken = TokenTools::hashToken($presentedToken);

        return Cache::lock("legacy-refresh:{$hashedToken}", 10)->block(5, function () use ($hashedToken, $presentedToken, $request) {
            $cached = Cache::get(self::graceCacheKey($presentedToken));
            if ($cached !== null) {
                return $this->respond($cached);
            }

            $legacy = LegacyRefreshToken::with('user')->where('token', $hashedToken)->first();
            if ($legacy === null || $legacy->user === null || $legacy->user->disabled_at !== null || $legacy->expire->isPast()) {
                $legacy?->delete();

                return $this->unauthorized($request);
            }

            $user = $legacy->user;
            if (LoginResponder::mustSetUpTwoFactor($user)) {
                $user->revokeAllSessions();

                return response()->json(['message' => 'Double authentification requise, veuillez vous reconnecter'], 401);
            }

            // L'ancien format ne gardait pas la date de login : la création du
            // jeton (son dernier renouvellement) est la meilleure approximation.
            $legacy->delete();
            ['session' => $session, 'refreshToken' => $refreshToken] = $this->sessions->start($user, $request, true, $legacy->created_at ?? now());

            return $this->issue($session, $refreshToken, $presentedToken);
        });
    }

    private function issue(AuthSession $session, string $refreshToken, string $presentedToken): JsonResponse
    {
        /** @var User $user */
        $user = $session->user;
        $accessToken = TokenTools::createAccessToken(
            $user,
            User::getPermissions($user->id),
            User::getMobile($user->id),
            User::getSapeurs($user->id),
            sessionId: $session->id,
        );

        $data = [
            "accessToken" => $accessToken,
            "refreshToken" => $refreshToken,
            "user" => $user,
            // Sans ça, le bandeau d'incitation 2FA disparaissait au premier
            // refresh (seuls login et confirmation d'email le renvoyaient).
            "twoFactorNudge" => LoginResponder::twoFactorNudgeFor($user),
        ];

        Cache::put(self::graceCacheKey($presentedToken), $data, now()->addSeconds(self::REUSE_GRACE_PERIOD_SECONDS));

        return $this->respond($data);
    }

    private function unauthorized(Request $request): JsonResponse
    {
        Log::warning('Invalid or expired refresh token attempt', ['ip' => $request->ip()]);

        return response()->json(['message' => 'Refresh token expired'], 401);
    }

    /**
     * @param array{accessToken: string, refreshToken: string, user: User} $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json(
            [
                "data" => $data,
                // TODO(rétro-compat temporaire) : le "message" et les champs plats
                // (accessToken/refreshToken/user) dupliquent `data` pour les anciens builds de
                // GestSIS_Mobile qui lisent la réponse à plat (ancien format, avant le passage au
                // wrapper `data`) plutôt que sous `data`. À retirer une fois confirmé qu'aucun build
                // Mobile antérieur au passage au format enveloppé (2026-09) n'est plus en usage sur
                // le terrain.
                "message" => "Successful login",
                ...$data,
            ]
        );
    }

    private static function graceCacheKey(string $presentedToken): string
    {
        return 'refresh_token_reuse:' . hash('sha256', $presentedToken);
    }
}
