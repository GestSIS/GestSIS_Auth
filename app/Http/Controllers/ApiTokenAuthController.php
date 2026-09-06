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
            ->with(['user', 'permissions', 'allowedSis'])
            ->first();

        // Défense en profondeur : les jetons API sont supprimés à la désactivation du
        // compte, mais un compte désactivé ne doit jamais pouvoir s'en servir.
        if (!$apiToken || $apiToken->user === null || $apiToken->user->disabled_at !== null) {
            Log::warning('Invalid or expired API token attempt', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Jeton API invalide ou expiré'], 401);
        }

        if ($apiToken->isRevoked()) {
            Log::warning('Revoked API token attempt', [
                'token_id' => $apiToken->id,
                'user_id' => $apiToken->user_id,
                'reason' => $apiToken->revoked_reason,
                'ip' => $request->ip(),
            ]);
            return response()->json([
                'error' => $apiToken->revoked_reason === ApiToken::REASON_PASSWORD_RESET
                    ? 'Jeton API révoqué suite à une réinitialisation du mot de passe du compte'
                    : 'Jeton API révoqué',
            ], 401);
        }

        // For admin users: allow token exchange without permission validation
        // For non-admin users: strict validation that they still have all permissions
        if ($apiToken->user->admin) {
            // Admin: generate JWT with token's permissions for allowed SIS only
            $tokenPermissions = $apiToken->permissions()->get();
            $tokenPermissionKeys = $tokenPermissions->pluck('api_key')->toArray();
            
            $allowedSis = $apiToken->allowedSis;
            
            // Build permissions grouped by SIS
            if ($allowedSis->isEmpty()) {
                // No SIS restriction: get all user's SIS
                $allUserPermissions = User::getPermissions($apiToken->user_id);
                $validPermissions = [];
                foreach (array_keys($allUserPermissions) as $sisKey) {
                    $validPermissions[$sisKey] = $tokenPermissionKeys;
                }
            } else {
                // SIS restriction: use only allowed SIS
                $validPermissions = [];
                foreach ($allowedSis as $sis) {
                    $validPermissions[$sis->api_key] = $tokenPermissionKeys;
                }
            }

            Log::info('Admin API token exchanged for JWT without validation', [
                'token_id' => $apiToken->id,
                'user_id' => $apiToken->user_id,
                'token_name' => $apiToken->name,
                'sis_count' => count($validPermissions),
                'ip' => $request->ip(),
            ]);
        } else {
            // Non-admin: strict permission validation
            $validPermissions = $apiToken->getValidPermissions();

            // Get all token permissions
            $tokenPermissions = $apiToken->permissions()->get();
            $tokenPermissionKeys = $tokenPermissions->pluck('api_key')->toArray();

            // Get allowed SIS for this token (or all user's SIS if no restriction)
            $allowedSis = $apiToken->allowedSis;
            $userPermissions = User::getPermissions($apiToken->user_id);

            // If token has SIS restrictions, use only those SIS
            // Otherwise, use all user's SIS
            $sisToCheck = $allowedSis->isEmpty()
                ? array_keys($userPermissions)
                : $allowedSis->pluck('api_key')->toArray();

            // If no SIS to check, user has lost all access
            if (empty($sisToCheck)) {
                Log::warning('API token rejected - user has no SIS access', [
                    'token_id' => $apiToken->id,
                    'user_id' => $apiToken->user_id,
                    'token_name' => $apiToken->name,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'error' => "Le jeton n'est plus valide. L'utilisateur a perdu les permissions requises (accès à tous les SIS révoqué). Veuillez révoquer ce jeton et en créer un nouveau."
                ], 403);
            }

            // Verify that user has ALL token permissions in EACH allowed SIS
            $missingPermissions = [];
            foreach ($sisToCheck as $sisKey) {
                if (!isset($userPermissions[$sisKey])) {
                    // User lost access to this SIS entirely
                    $missingPermissions[$sisKey] = $tokenPermissionKeys;
                    continue;
                }

                $userSisPermissions = $userPermissions[$sisKey];
                $missing = array_diff($tokenPermissionKeys, $userSisPermissions);

                if (!empty($missing)) {
                    $missingPermissions[$sisKey] = $missing;
                }
            }

            if (!empty($missingPermissions)) {
                $errorDetails = collect($missingPermissions)
                    ->map(function ($perms, $sisKey) use ($tokenPermissions) {
                        $permNames = $tokenPermissions
                            ->whereIn('api_key', $perms)
                            ->pluck('nom')
                            ->implode(', ');
                        return "{$sisKey}: {$permNames}";
                    })
                    ->implode(' | ');

                Log::warning('API token has invalid permissions - user lost access in some SIS', [
                    'token_id' => $apiToken->id,
                    'user_id' => $apiToken->user_id,
                    'token_name' => $apiToken->name,
                    'invalid_for_sis' => array_keys($missingPermissions),
                    'missing_permissions_by_sis' => $missingPermissions,
                    'allowed_sis' => $sisToCheck,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'error' => "Le jeton n'est plus valide. L'utilisateur a perdu des permissions requises dans certains SIS : {$errorDetails}. Veuillez révoquer ce jeton et en créer un nouveau."
                ], 403);
            }
        }

        // Get mobiles and sapeurs (from user's current state)
        $mobiles = User::getMobile($apiToken->user_id);
        $sapeurs = User::getSapeurs($apiToken->user_id);

        // Generate JWT with token's valid permission subset.
        // Admin flag is forced to false: API tokens are always scoped credentials,
        // so the JWT must not bypass middleware permission checks regardless of the user's admin status.
        $accessToken = TokenTools::createAccessToken(
            $apiToken->user,
            $validPermissions,
            $mobiles,
            $sapeurs,
            false
        );

        // Update last_used_at timestamp
        $apiToken->last_used_at = Carbon::now();
        $apiToken->save();

        Log::info('API token successfully exchanged for JWT', [
            'token_id' => $apiToken->id,
            'user_id' => $apiToken->user_id,
            'token_name' => $apiToken->name,
            'sis_restricted' => $apiToken->allowedSis()->count() > 0,
            'allowed_sis_count' => $apiToken->allowedSis()->count(),
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
