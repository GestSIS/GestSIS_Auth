<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Sentry\Laravel\Integration;

use App\Http\Middleware\ImpersonationReadOnly;
use App\Http\Middleware\JwtTokenValidatorAdmin;
use App\Http\Middleware\JwtTokenValidatorRole;
use App\Http\Middleware\JwtTokenValidatorRoleOrApiToken;
use App\Http\Middleware\JwtTokenValidatorTwoFactorEnrollment;
use App\Http\Middleware\ThrottleFailedAttempts;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'jwtTokenRole' => JwtTokenValidatorRole::class,
            'jwtTokenRoleOrApiToken' => JwtTokenValidatorRoleOrApiToken::class,
            'jwtTokenAdmin' => JwtTokenValidatorAdmin::class,
            'jwtTokenTwoFactorEnrollment' => JwtTokenValidatorTwoFactorEnrollment::class,
            'impersonationReadOnly' => ImpersonationReadOnly::class,
            'throttle-failures' => ThrottleFailedAttempts::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
        // ValidationException est rendue nativement par Laravel en
        // {"message": "...", "errors": {champ: [...]}} @ 422 — même forme
        // que le reste de l'API, pas besoin de handler custom.

        // Le middleware `throttle:` natif répond « Too Many Attempts. » : message
        // en français avec le délai d'attente, comme ThrottleFailedAttempts.
        $exceptions->render(function (ThrottleRequestsException $e) {
            $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return response()->json([
                'message' => 'Trop de tentatives. Réessayez dans ' . max(1, (int) ceil($retryAfter / 60)) . ' minute(s).',
            ], 429, $e->getHeaders());
        });
    })->create();
