<?php

namespace App\Http\Controllers;

use App\Auth\LoginResponder;
use App\Auth\TokenTools;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class ApiRefreshTokenController extends Controller
{
    // Absorbe la course bénigne entre deux onglets/requêtes qui tentent de
    // tourner un même refresh token quasi simultanément (chacun a pu le lire
    // depuis le localStorage avant que l'autre n'y écrive le nouveau) : dans
    // cette fenêtre, un token déjà consommé renvoie la même réponse plutôt
    // que d'être traité comme une réutilisation frauduleuse. Un vol réel se
    // rejoue typiquement bien plus tard (le temps d'intercepter puis
    // d'utiliser le token), donc au-delà de ce délai c'est traité comme tel.
    private const REUSE_GRACE_PERIOD_SECONDS = 10;

    /**
     * Handle a registration request for the application.
     */
    public function refresh(Request $request): JsonResponse
    {
        Log::debug("Call refresh token");

        $this->validator($request->all())->validate();

        // Hash the provided token before database lookup
        // This prevents timing attacks as the hash is computed in constant time
        $hashedToken = TokenTools::hashToken($request->input('token'));

        // Pas de filtre sur expire/used_at ici : il faut retrouver la ligne
        // même si elle a déjà été consommée, pour distinguer un token qui n'a
        // jamais existé (401 direct) d'un token déjà tourné (détection de
        // réutilisation ci-dessous).
        $refreshToken = RefreshToken::where('token', '=', $hashedToken)->with('user')->first();

        // Défense en profondeur : disableAccount() supprime les refresh tokens, mais un
        // compte désactivé ne doit en aucun cas pouvoir se ré-émettre un access token.
        if (!$refreshToken || $refreshToken->user === null || $refreshToken->user->disabled_at !== null) {
            Log::warning('Invalid or expired refresh token attempt', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Refresh token expired'], 401);
        }

        if ($refreshToken->used_at !== null) {
            return $this->handleReuse($refreshToken, $hashedToken, $request);
        }

        if ($refreshToken->expire <= Carbon::now()) {
            Log::warning('Invalid or expired refresh token attempt', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Refresh token expired'], 401);
        }

        // Session ouverte avant l'échéance d'obligation 2FA (ou pendant une
        // exemption expirée depuis) : sans ce contrôle, la rotation la
        // prolongerait indéfiniment sans second facteur. Le client repasse
        // par /login, qui impose alors la configuration.
        if (LoginResponder::mustSetUpTwoFactor($refreshToken->user)) {
            $refreshToken->user->refreshTokens()->delete();
            Log::info('Session revoked at refresh: 2FA setup required', [
                'user_id' => $refreshToken->user_id,
            ]);
            return response()->json(['message' => 'Double authentification requise, veuillez vous reconnecter'], 401);
        }

        return $this->rotate($refreshToken, $hashedToken, $request);
    }

    private function rotate(RefreshToken $refreshToken, string $hashedToken, Request $request): JsonResponse
    {
        $permissions = User::getPermissions($refreshToken->user_id);
        $mobiles = User::getMobile($refreshToken->user_id);
        $sapeurs = User::getSapeurs($refreshToken->user_id);
        $accessToken = TokenTools::createAccessToken($refreshToken->user, $permissions, $mobiles, $sapeurs, sessionId: $refreshToken->family_id);

        // Create new refreshToken (même famille, même choix "se souvenir de moi")
        $token = TokenTools::createRefreshToken($refreshToken->family_id, (bool) $refreshToken->remember);

        $newRefreshToken = new RefreshToken();
        $newRefreshToken->token = TokenTools::hashToken($token->token); // Hash before storing
        $newRefreshToken->expire = $token->expire;
        $newRefreshToken->family_id = $token->familyId;
        $newRefreshToken->remember = $token->remember;
        $newRefreshToken->ip_address = $request->ip();
        $newRefreshToken->user_agent = $request->userAgent();
        $newRefreshToken->last_used_at = now();
        $newRefreshToken->user_id = $refreshToken->user_id;
        $newRefreshToken->save();

        // Single use token : on le marque consommé plutôt que de le
        // supprimer, pour pouvoir détecter une réutilisation ultérieure.
        $refreshToken->used_at = now();
        $refreshToken->save();

        $data = [
            "accessToken" => $accessToken,
            "refreshToken" => $token->token, // Send plain token to client
            "user" => $refreshToken->user,
        ];

        Cache::put(self::reuseGraceCacheKey($hashedToken), $data, now()->addSeconds(self::REUSE_GRACE_PERIOD_SECONDS));

        return $this->respond($data);
    }

    private function handleReuse(RefreshToken $refreshToken, string $hashedToken, Request $request): JsonResponse
    {
        $cached = Cache::get(self::reuseGraceCacheKey($hashedToken));
        if ($cached !== null) {
            return $this->respond($cached);
        }

        // Réutilisation hors fenêtre de grâce : impossible de distinguer
        // l'utilisateur légitime d'un attaquant qui aurait intercepté ce
        // token avant sa rotation — toute la famille est révoquée pour
        // forcer une reconnexion complète plutôt que de laisser l'un des
        // deux (potentiellement l'attaquant) continuer avec le successeur.
        Log::warning('Refresh token reuse detected, revoking token family', [
            'user_id' => $refreshToken->user_id,
            'ip' => $request->ip(),
        ]);
        RefreshToken::where('user_id', $refreshToken->user_id)
            ->where('family_id', $refreshToken->family_id)
            ->delete();

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

    private static function reuseGraceCacheKey(string $hashedToken): string
    {
        return "refresh_token_reuse:{$hashedToken}";
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'token' => ['required', 'string'],
        ]);
    }
}
