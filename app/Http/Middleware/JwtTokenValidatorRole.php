<?php

namespace App\Http\Middleware;

use App\Auth\TokenTools;
use App\Models\User;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JwtTokenValidatorRole
{
    /**
     * Refus par défaut des jetons issus d'un jeton d'API : ils servent aux
     * intégrations (GestSIS_API), jamais à gérer l'authentification du compte
     * (sessions, 2FA, jetons, mot de passe, jetons de permissions). Seules les
     * routes déclarées avec `jwtTokenRoleOrApiToken` les acceptent.
     */
    protected bool $acceptsApiTokens = false;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        try {
            $token = TokenTools::validateToken($request->bearerToken());
        } catch (Exception|\TypeError $e) {
            return response()->json(["message" => "Accès refusé"], 401);
        }

        if (!$this->acceptsApiTokens && TokenTools::isApiToken($token)) {
            return response()->json(["message" => "Action impossible avec un jeton d'API"], 403);
        }
        // Set authenticated user from token
        if (isset($token->data->id)) {
            $user = User::findActive($token->data->id);
            if ($user === null) {
                return response()->json(["message" => "Accès refusé"], 401);
            }
            Auth::setUser($user);
        }
        $request->attributes->set('session_family_id', $token->data->sid ?? null);

        if (count($roles) > 0) {
            $sisKey = $request->header('Sis-Key', Null);
            if (is_null($sisKey)) {
                return response()->json(["message" => "Sis non sélectionné"], 401);
            }

            if ($token->data->admin !== true) {
                // Check has role for provided sis
                $perms = (array) $token->data->permissions;
                if (!array_key_exists($sisKey, $perms)) {
                    return response()->json(["message" => "Aucun droit pour ce sis"], 401);
                }

                if (count(array_intersect($roles, $perms[$sisKey])) == 0) {
                    return response()->json(["message" => "Au moins 1 des rôles suivant est requis [" . join(", ", $roles) . "]."], 401);
                }
            }
        }
        return $next($request);
    }
}
