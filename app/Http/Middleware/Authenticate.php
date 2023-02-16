<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Authenticate extends Middleware
{

    public function handle($request, Closure $next, ...$guards)
    {
        if ($request->bearerToken() === null) {
            Log::error("Missing token");

            $response = [
                'error' => 'Missing token'
            ];
            return Response::json($response, 401);
        }

        foreach ($guards as $guard) {
            if (!Auth::guard($guard)->check()) {
                Log::error("Invalid login");

                $response = [
                    'error' => 'Invalid token'
                ];
                return Response::json($response, 401);
            }
        }

        return $next($request);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param Request $request
     * @return string
     */
    protected function redirectTo($request)
    {
        if (!$request->expectsJson()) {
            return route('login');
        } else {
            return response()->json(["error" => "Error while authenticate"]);
        }
    }
}
