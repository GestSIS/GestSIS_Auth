<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class ApiRefreshTokenController extends Controller
{

    /**
     * Handle a registration request for the application.
     */
    public function refresh(Request $request): JsonResponse
    {
        Log::debug("Call refresh token");

        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        // Hash the provided token before database lookup
        // This prevents timing attacks as the hash is computed in constant time
        $hashedToken = TokenTools::hashToken($request->input('token'));
        $refreshToken = RefreshToken::where('expire', '>', Carbon::now())
            ->where('token', '=', $hashedToken)
            ->with('user')
            ->first();

        // Défense en profondeur : disableAccount() supprime les refresh tokens, mais un
        // compte désactivé ne doit en aucun cas pouvoir se ré-émettre un access token.
        if (!$refreshToken || $refreshToken->user === null || $refreshToken->user->disabled_at !== null) {
            Log::warning('Invalid or expired refresh token attempt', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Refresh token expired'], 401);
        }

        $permissions = User::getPermissions($refreshToken->user_id);
        $mobiles = User::getMobile($refreshToken->user_id);
        $sapeurs = User::getSapeurs($refreshToken->user_id);
        $accessToken = TokenTools::createAccessToken($refreshToken->user, $permissions, $mobiles, $sapeurs);

        // Create new refreshToken
        $token = TokenTools::createRefreshToken();

        // Store refresh token in database (hashed)
        $newRefreshToken = new RefreshToken();
        $newRefreshToken->token = TokenTools::hashToken($token->token); // Hash before storing
        $newRefreshToken->expire = $token->expire;
        $newRefreshToken->user_id = $refreshToken->user_id;
        $newRefreshToken->save();

        // Single use token, we destroy the old one
        $refreshToken->delete();

        return response()->json(
            [
                "message" => "Successful login",
                "accessToken" => $accessToken,
                "refreshToken" => $token->token, // Send plain token to client
                "user" => $refreshToken->user
            ]
        );
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'token' => ['required', 'string'],
        ]);
    }
}
