<?php

namespace App\Http\Middleware;

use Closure;
use App\Auth\TokenTools;
use Exception;
use Illuminate\Http\Request;

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
        return $next($request);
    }
}
