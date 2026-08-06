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
        } catch (Exception $e) {
            return response()->json(["error" => "Accès refusé"], 401);
        }

        // Set authenticated user from token
        if (isset($token->data->id)) {
            $user = User::find($token->data->id);
            if ($user) {
                Auth::setUser($user);
            }
        }

        if (count($roles) > 0) {
            $sisKey = $request->header('Sis-Key', Null);
            if (is_null($sisKey)) {
                return response()->json(["error" => "Sis non sélectionné"], 401);
            }

            if ($token->data->admin !== true) {
                // Check has role for provided sis
                $perms = (array) $token->data->permissions;
                if (!array_key_exists($sisKey, $perms)) {
                    return response()->json(["error" => "Aucun droit pour ce sis"], 401);
                }

                if (count(array_intersect($roles, $perms[$sisKey])) == 0) {
                    return response()->json(["error" => "Au moins 1 des rôles suivant est requis [" . join(", ", $roles) . "]."], 401);
                }
            }
        }
        return $next($request);
    }
}
