<?php

namespace App\Http\Middleware;

use Closure;
use App\Auth\TokenTools;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JwtTokenValidatorAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        try {
            $token = TokenTools::validateToken($request->bearerToken());
        } catch (Exception $e) {
            return response()->json(["error" => "Accès refusé"], 401);
        }

        if ($token->data->admin !== true) {
            return response()->json(["error" => "Accès refusé"], 401);
        }

        // Recharge depuis la DB (contrairement au seul claim JWT) pour ne pas
        // laisser un compte désactivé garder l'accès admin jusqu'à l'expiration
        // naturelle du token (8h).
        $user = User::findActive($token->data->id);
        if ($user === null) {
            return response()->json(["error" => "Accès refusé"], 401);
        }

        Auth::setUser($user);

        return $next($request);
    }
}
