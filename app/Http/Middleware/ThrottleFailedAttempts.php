<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite les ÉCHECS (mot de passe, code 2FA, code email) d'une limite nommée
 * déclarée dans RateLimitServiceProvider.
 *
 * Contrairement au middleware natif `throttle`, qui compte toutes les
 * requêtes, l'essai est compté AVANT d'exécuter la requête puis effacé si la
 * réponse est un succès : compter après coup (comme `Limit::after()`) laisserait
 * une rafale de requêtes parallèles voir un compteur à 0 pendant les
 * vérifications bcrypt.
 */
class ThrottleFailedAttempts
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $limiterName): Response
    {
        $limiter = RateLimiter::limiter($limiterName);
        if ($limiter === null) {
            throw new RuntimeException("Limite d'essais inconnue : {$limiterName}");
        }

        $limit = $limiter($request);
        if (!$limit instanceof Limit) {
            throw new RuntimeException("La limite {$limiterName} doit renvoyer une seule Limit");
        }

        $key = "{$limiterName}:{$limit->key}";

        if (RateLimiter::hit($key, $limit->decaySeconds) > $limit->maxAttempts) {
            if ($limit->responseCallback !== null) {
                return call_user_func($limit->responseCallback, $request, []);
            }

            return response()->json([
                'message' => 'Trop de tentatives invalides. Réessayez dans ' . ceil(RateLimiter::availableIn($key) / 60) . ' minute(s).',
            ], 429);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            RateLimiter::clear($key);
        }

        return $response;
    }
}
