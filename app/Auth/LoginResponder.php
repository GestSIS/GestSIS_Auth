<?php

namespace App\Auth;

use App\Models\RefreshToken;
use App\Models\TwoFactorPolicy;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginResponder
{
    /**
     * Décide de la réponse à renvoyer une fois le mot de passe validé — après
     * un login classique, ou juste après la création du compte (un compte
     * fraîchement enregistré est en tout point équivalent à "mot de passe
     * validé, sans 2FA ni exemption encore possibles") : connexion complète
     * (2FA désactivé ou exempté), challenge 2FA (2FA déjà configuré — ne
     * peut pas arriver pour un compte tout juste créé, mais partagé pour ne
     * pas dupliquer la décision), ou configuration 2FA obligatoire (politique
     * d'enforcement dépassée et compte non encore protégé). `rememberMe`
     * (par défaut vrai) transite jusqu'à l'émission de la session complète, y
     * compris à travers un éventuel challenge 2FA (voir TokenTools::encodeScopedToken).
     */
    public static function respondAfterAuthentication(User $user, Request $request): JsonResponse
    {
        $remember = $request->boolean('rememberMe', true);

        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'data' => [
                    'requiresTwoFactor' => true,
                    'preAuthToken' => TokenTools::createTwoFactorPreAuthToken($user, $remember),
                    'availableMethods' => $user->twoFactorAvailableMethods(),
                ],
            ]);
        }

        if ($user->isTwoFactorExempt()) {
            return self::respond($user, null, $request, $remember);
        }

        if (self::mustSetUpTwoFactor($user)) {
            return response()->json([
                'data' => [
                    'requiresTwoFactorSetup' => true,
                    'setupToken' => TokenTools::createTwoFactorSetupToken($user, $remember),
                ],
            ]);
        }

        $policy = TwoFactorPolicy::current();

        return self::respond($user, $policy->enforced_at !== null ? Carbon::parse($policy->enforced_at) : null, $request, $remember);
    }

    /**
     * Compte soumis à l'obligation 2FA (échéance dépassée) mais pas encore
     * protégé ni exempté : aucune session ne doit lui être émise, ni au login
     * ni au refresh d'une session ouverte avant l'échéance.
     */
    public static function mustSetUpTwoFactor(User $user): bool
    {
        if ($user->hasTwoFactorEnabled() || $user->isTwoFactorExempt()) {
            return false;
        }

        return config('gestsis.two_factor_enforcement_enabled') && TwoFactorPolicy::current()->isEnforced();
    }

    /**
     * Émet la réponse de connexion complète (accessToken + refreshToken +
     * user). Appelé aussi bien après un login mot de passe direct (compte
     * sans 2FA) qu'après une vérification 2FA réussie (`2fa/verify`) : les
     * deux chemins doivent produire une réponse strictement identique.
     *
     * @param CarbonInterface|null $twoFactorNudgeAt Si fourni (compte sans 2FA,
     *        pendant la période de grâce), ajoute un indicateur pour que le
     *        front affiche un bandeau incitant à activer le 2FA avant l'échéance.
     * @param Request|null $request Fournit l'IP/user-agent affichés dans la
     *        liste des sessions actives de l'utilisateur.
     * @param bool $remember "Se souvenir de moi" — détermine la durée de vie
     *        du refresh token émis (voir TokenTools::createRefreshToken()).
     */
    public static function respond(User $user, ?CarbonInterface $twoFactorNudgeAt = null, ?Request $request = null, bool $remember = true): JsonResponse
    {
        $data = self::buildData($user, $twoFactorNudgeAt, $request, $remember);

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

    /**
     * Construit les données de connexion (accessToken/refreshToken/user) sans
     * les envelopper dans une réponse — pour les appelants qui doivent les
     * fusionner avec d'autres champs (ex. TotpController::confirm, qui y
     * ajoute les codes de secours lors d'une configuration 2FA forcée).
     *
     * @return array{accessToken: string, refreshToken: string, user: User, twoFactorNudge?: array}
     */
    public static function buildData(User $user, ?CarbonInterface $twoFactorNudgeAt = null, ?Request $request = null, bool $remember = true): array
    {
        $permissions = User::getPermissions($user->id);
        $mobiles = User::getMobile($user->id);
        $sapeurs = User::getSapeurs($user->id);
        // We create a single use refreshToken
        // Usable only once, to prevent any one to steal an old token and authenticate using it.
        // When used, will be deactivated and a new one should be generated
        $token = TokenTools::createRefreshToken(remember: $remember);
        $accessToken = TokenTools::createAccessToken($user, $permissions, $mobiles, $sapeurs, sessionId: $token->familyId);
        $refreshToken = new RefreshToken();
        $refreshToken->token = TokenTools::hashToken($token->token); // Hash before storing
        $refreshToken->expire = $token->expire;
        $refreshToken->family_id = $token->familyId;
        $refreshToken->remember = $token->remember;
        $refreshToken->ip_address = $request?->ip();
        $refreshToken->user_agent = $request?->userAgent();
        $refreshToken->last_used_at = now();
        $refreshToken->user_id = $user->id;
        $user->refreshTokens()->save($refreshToken);

        $data = [
            "accessToken" => $accessToken,
            "refreshToken" => $token->token, // Send plain token to client
            "user" => User::where('id', $user->id)->first(),
        ];

        if ($twoFactorNudgeAt !== null) {
            $data["twoFactorNudge"] = [
                "enforcedAt" => $twoFactorNudgeAt->toIso8601String(),
                "daysRemaining" => max(0, (int) now()->diffInDays($twoFactorNudgeAt, false)),
            ];
        }

        return $data;
    }
}
