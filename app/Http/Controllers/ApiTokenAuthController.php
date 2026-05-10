<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Models\ApiToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApiTokenAuthController extends Controller
{
    /**
     * Exchange an API token for a JWT access token.
     * Similar to refresh token flow but for API tokens.
     */
    public function authenticate(Request $request): JsonResponse
    {
        Log::debug("API Token authentication attempt");

        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        // Hash the provided token before database lookup
        $hashedToken = TokenTools::hashToken($request->input('token'));

        $apiToken = ApiToken::where('token', '=', $hashedToken)
            ->where('expires_at', '>', Carbon::now())
            ->with(['user', 'permissions'])
            ->first();

        if (!$apiToken) {
            Log::warning('Invalid or expired API token attempt', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Jeton API invalide ou expiré'], 401);
        }

        // Get valid permissions (intersection of token permissions and user's current permissions)
        $validPermissions = $apiToken->getValidPermissions();

        // Get all token permissions to compare
        $tokenPermissions = $apiToken->permissions()->get();
        $tokenPermissionCount = $tokenPermissions->count();

        // Count valid permissions (flatten the grouped array)
        $validPermissionCount = collect($validPermissions)->flatten()->count();

        // Reject if user has lost ANY of the token's permissions
        if ($validPermissionCount < $tokenPermissionCount) {
            // Find which permissions were lost
            $lostPermissions = $tokenPermissions->filter(function ($permission) use ($validPermissions) {
                $flatValid = collect($validPermissions)->flatten()->toArray();
                return !in_array($permission->api_key, $flatValid);
            })->pluck('nom')->implode(', ');

            Log::warning('API token has invalid permissions - user lost access', [
                'token_id' => $apiToken->id,
                'user_id' => $apiToken->user_id,
                'token_name' => $apiToken->name,
                'lost_permissions' => $lostPermissions,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => "Le jeton n'est plus valide. L'utilisateur a perdu les permissions requises : {$lostPermissions}. Veuillez révoquer ce jeton et en créer un nouveau."
            ], 403);
        }

        // Get mobiles and sapeurs (from user's current state)
        $mobiles = User::getMobile($apiToken->user_id);
        $sapeurs = User::getSapeurs($apiToken->user_id);

        // Generate JWT with token's valid permission subset
        $accessToken = TokenTools::createAccessToken(
            $apiToken->user,
            $validPermissions,
            $mobiles,
            $sapeurs
        );

        // Update last_used_at timestamp
        $apiToken->last_used_at = Carbon::now();
        $apiToken->save();

        Log::info('API token successfully exchanged for JWT', [
            'token_id' => $apiToken->id,
            'user_id' => $apiToken->user_id,
            'token_name' => $apiToken->name,
        ]);

        return response()->json([
            'message' => 'Authentification réussie',
            'accessToken' => $accessToken,
            'user' => $apiToken->user,
        ]);
    }

    /**
     * Get a validator for the authentication request.
     */
    protected function validator(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'token' => ['required', 'string'],
        ]);
    }
}
