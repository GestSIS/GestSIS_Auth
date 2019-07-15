<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Response;

class Authenticate extends Middleware
{

    public function handle($request, Closure $next, ...$guards)
    {
        $res = $this->authenticate($request, $guards);
        if(!$request->user()){
            dd($res);
            Log::debug("HANDLE");

            Log::debug($request);
            if ($request->ajax() || $request->wantsJson()) {
                return response('Unauthorized.', 401);
            } else {
                $response = [
                    'error' => 'Token expired'
                ];
                return Response::json($response);
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
        }
    }
}
