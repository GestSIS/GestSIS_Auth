<?php

namespace App\Http\Middleware;

use App\Auth\TokenTools;
use App\Models\User;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class JwtTokenValidatorTwoFactorEnrollment
{
    /**
     * Accepte soit un accessToken complet (opt-in volontaire par un compte
     * déjà connecté), soit un jeton "setup" restreint (parcours forcé par la
     * politique d'enforcement, émis par /login à la place de l'accessToken
     * pour un compte pas encore protégé). Les deux mènent à 2fa/enable et
     * 2fa/confirm, mais le contrôleur a besoin de savoir lequel a été utilisé
     * pour décider s'il doit émettre une session complète en retour.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $token = TokenTools::validateToken($request->bearerToken());
            $isSetupFlow = false;
            if (TokenTools::isApiToken($token)) {
                return response()->json(["message" => "Action impossible avec un jeton d'API"], 403);
            }        } catch (Exception|\TypeError $e) {
            try {
                $token = TokenTools::validateTwoFactorSetupToken($request->bearerToken());
                $isSetupFlow = true;
            } catch (Exception|\TypeError $e2) {
                return response()->json(["message" => "Accès refusé"], 401);
            }
        }

        $user = User::findActive($token->data->id ?? null);
        if ($user === null) {
            return response()->json(["message" => "Accès refusé"], 401);
        }

        // Le setup token ne sert qu'à configurer la première méthode : une fois
        // le compte protégé, le réutiliser (pendant sa durée de validité)
        // permettrait d'ajouter une méthode sans mot de passe et d'obtenir une
        // session sans second facteur.
        if ($isSetupFlow && $user->hasTwoFactorEnabled()) {
            return response()->json(["message" => "Accès refusé"], 401);
        }

        Auth::setUser($user);
        $request->attributes->set('two_factor_setup_flow', $isSetupFlow);
        $request->attributes->set('two_factor_remember', $token->data->remember ?? true);
        $request->attributes->set('session_family_id', $isSetupFlow ? null : ($token->data->sid ?? null));

        return $next($request);
    }
}
