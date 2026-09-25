<?php

namespace App\Http\Middleware;

use App\Auth\TokenTools;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestion de l'authentification du compte en usurpation d'identité (jeton
 * émis par admin/token) : lecture seule, pour le support — aucune
 * modification (sessions, jetons d'API, 2FA, jetons de permissions).
 *
 * Décode lui-même le jeton plutôt que de dépendre d'un attribut posé par le
 * middleware d'authentification : indépendant de l'ordre de déclaration. Un
 * jeton invalide est laissé au middleware d'authentification (401).
 */
class ImpersonationReadOnly
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        try {
            $token = TokenTools::validateToken((string) $request->bearerToken());
        } catch (Exception|\TypeError) {
            return $next($request);
        }

        if (TokenTools::isImpersonationToken($token)) {
            return response()->json(["message" => "Action impossible en usurpation d'identité"], 403);
        }

        return $next($request);
    }
}
