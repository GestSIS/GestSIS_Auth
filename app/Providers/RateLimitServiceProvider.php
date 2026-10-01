<?php

namespace App\Providers;

use App\Auth\TokenTools;
use App\Models\User;
use Exception;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Toutes les limites d'essais d'Auth, déclarées au même endroit.
 *
 * - Les limites `*-by-ip` sont appliquées par le middleware natif
 *   `throttle:<nom>` : chaque requête compte.
 * - Les limites `*-failures` sont appliquées par `throttle-failures:<nom>`
 *   (ThrottleFailedAttempts) : l'essai est compté avant la vérification et
 *   rendu si la réponse est un succès — seuls les échecs bloquent, y compris
 *   face à des requêtes parallèles. Clé par compte : en plus de la limite par
 *   IP, pour qu'un attaquant ne puisse pas répartir ses essais sur plusieurs IP.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('auth-by-ip', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('two-factor-enrollment-by-ip', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('registration-by-ip', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('session-by-ip', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('step-up-by-ip', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('api-token-creation', fn (Request $request) => Limit::perHour(5)->by((string) Auth::id()));

        // Code 2FA au login : compte identifié par le pre-auth token.
        RateLimiter::for('two-factor-verify-failures', function (Request $request) {
            try {
                $userId = TokenTools::validateTwoFactorPreAuthToken((string) $request->bearerToken())->data->id ?? null;
            } catch (Exception|\TypeError) {
                $userId = null;
            }

            return Limit::perMinutes(15, 5)->by($userId !== null ? "user:{$userId}" : "ip:{$request->ip()}");
        });

        // Ré-authentification (mot de passe + code) avant une action sensible.
        // `change-password` est public : le compte y est identifié par l'email.
        RateLimiter::for('step-up-failures', function (Request $request) {
            $key = Auth::id() !== null
                ? 'user:' . Auth::id()
                : 'email:' . strtolower((string) $request->input('email'));

            return Limit::perMinutes(15, 5)->by($key);
        });

        // Code de confirmation d'email : 5 essais par code. La clé inclut une
        // empreinte du hash du code en cours (bcrypt, salé : unique par code
        // émis) : un renvoi repart donc d'un compteur neuf, sans remise à zéro
        // manuelle. La date d'expiration ne suffit pas : précise à la seconde,
        // elle serait identique pour deux codes émis dans la même seconde.
        RateLimiter::for('email-confirmation-failures', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            $codeHash = User::where('email', $email)->value('validate_email_token');
            $codeFingerprint = $codeHash !== null ? substr(hash('sha256', $codeHash), 0, 16) : 'none';

            return Limit::perMinutes(30, 5)
                ->by("email:{$email}|code:{$codeFingerprint}")
                ->response(fn () => response()->json([
                    'message' => 'Trop de tentatives. Demandez un nouveau code de confirmation.',
                ], 429));
        });
    }
}
