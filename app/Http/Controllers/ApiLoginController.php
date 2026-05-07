<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApiLoginController extends Controller
{
    /**
     * Handle a login request to the application.JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        Log::debug("login");
        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        if ($this->attemptLogin($request)) {
            return $this->sendLoginResponse(Auth::user());
        }

        // Log failed login attempt
        Log::warning('Failed login attempt', [
            'email' => $request->input('email'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['error' => 'Les identifiants fournis sont incorrects'], 401);
    }

    /**
     * Handle a token request to the application.
     */
    public function token(Request $request): JsonResponse
    {
        Log::debug("ADMIN Request for a user token");
        $userId = $request->input('user_id');
        if (!$userId) {
            return response()->json(["Missing `user_id` parameter", 400]);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(["Missing `user_id` parameter", 400]);
        }

        $permissions = User::getPermissions($user->id);
        $mobiles = User::getMobile($user->id);
        $sapeurs = User::getSapeurs($user->id);
        $accessToken = TokenTools::createAccessToken($user, $permissions, $mobiles, $sapeurs);

        return response()->json([
            "accessToken" => $accessToken,
        ]);
    }

    /**
     * Get a validator for an incoming registration request.
     */
    protected function validator(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            $this->username() => 'required|string',
            'password' => 'required|string',
        ]);
    }

    /**
     * Attempt to log the user into the application.
     */
    protected function attemptLogin(Request $request): bool
    {
        return $this->guard()->attempt(
            $this->credentials($request)
        );
    }

    /**
     * Get the needed authorization credentials from the request.
     */
    protected function credentials(Request $request): array
    {
        return $request->only($this->username(), 'password');
    }

    /**
     * Send the response after the user was authenticated.
     */
    protected function sendLoginResponse(User $user): JsonResponse
    {
        $permissions = User::getPermissions($user->id);
        $mobiles = User::getMobile($user->id);
        $sapeurs = User::getSapeurs($user->id);
        $accessToken = TokenTools::createAccessToken($user, $permissions, $mobiles, $sapeurs);

        // We create a single use refreshToken
        // Usable only once, to prevent any one to steal an old token and authenticate using it.
        // When used, will be deactivated and a new one should be generated
        $token = TokenTools::createRefreshToken();
        $refreshToken = new RefreshToken();
        $refreshToken->token = TokenTools::hashToken($token->token); // Hash before storing
        $refreshToken->expire = $token->expire;
        $refreshToken->user_id = $user->id;
        $user->refreshTokens()->save($refreshToken);

        return response()->json(
            array(
                "message" => "Successful login",
                "accessToken" => $accessToken,
                "refreshToken" => $token->token, // Send plain token to client
                "user" => User::where('id', $user->id)->first()
            )
        );
    }

    /**
     * Get the login username to be used by the controller.
     */
    public function username(): string
    {
        return 'email';
    }

    /**
     * Get the guard to be used during authentication.
     */
    protected function guard(): StatefulGuard
    {
        return Auth::guard();
    }
}
