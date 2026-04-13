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

        $refreshToken = RefreshToken::where('expire', '>', Carbon::now())->where('token', '=', $request->get('token'))->with('user')->first();
        if (!$refreshToken) {
            return response()->json(['error' => 'Refresh token expired'], 401);
        }

        $permissions = User::getPermissions($refreshToken->user_id);
        $mobiles = User::getMobile($refreshToken->user_id);
        $sapeurs = User::getSapeurs($refreshToken->user_id);
        $accessToken = TokenTools::createAccessToken($refreshToken->user, $permissions, $mobiles, $sapeurs);

        // Create new refreshToken
        $token = TokenTools::createRefreshToken();

        // Store refresh token in database
        $newRefreshToken = new RefreshToken();
        $newRefreshToken->token = $token->token;
        $newRefreshToken->expire = $token->expire;
        $newRefreshToken->user_id = $refreshToken->user_id;
        $newRefreshToken->save();

        // Single use token, we destroy the old one
        $refreshToken->delete();

        return response()->json(
            array(
                "message" => "Successful login",
                "accessToken" => $accessToken,
                "refreshToken" => $newRefreshToken->token,
                "user" => $refreshToken->user
            )
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
