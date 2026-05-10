<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Models\ApiToken;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApiTokenController extends Controller
{
    /**
     * List all API tokens for the authenticated user.
     * Excludes the actual token value for security.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $tokens = ApiToken::where('user_id', $user->id)
            ->with('permissions:id,nom,api_key')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'description' => $token->description,
                    'created_at' => $token->created_at,
                    'expires_at' => $token->expires_at,
                    'last_used_at' => $token->last_used_at,
                    'permissions' => $token->permissions->map(fn($p) => [
                        'id' => $p->id,
                        'nom' => $p->nom,
                        'api_key' => $p->api_key,
                    ]),
                ];
            });

        return response()->json(['tokens' => $tokens]);
    }

    /**
     * Create a new API token with specified permissions.
     * Returns the plain token only once.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Validate request
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:365'],
            'permission_ids' => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['required', 'integer', 'exists:permissions,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Check for duplicate token name for this user
        $existingToken = ApiToken::where('user_id', $user->id)
            ->where('name', $validated['name'])
            ->first();

        if ($existingToken) {
            return response()->json([
                'error' => ['name' => ['Un jeton avec ce nom existe déjà.']]
            ], 422);
        }

        // Get user's current permissions
        $userPermissions = User::getPermissions($user->id);
        $userPermissionKeys = collect($userPermissions)
            ->flatMap(fn($perms, $sisKey) => $perms)
            ->unique()
            ->toArray();

        // Validate that user has all requested permissions
        $requestedPermissions = Permission::whereIn('id', $validated['permission_ids'])->get();
        $invalidPermission = $requestedPermissions->first(
            fn($permission) => !in_array($permission->api_key, $userPermissionKeys)
        );

        if ($invalidPermission) {
            return response()->json([
                'error' => "Vous ne disposez pas de la permission : {$invalidPermission->nom} ({$invalidPermission->api_key})"
            ], 403);
        }

        // Generate API token
        $tokenData = TokenTools::createApiToken($validated['expires_in_days']);

        // Create token record in database
        $apiToken = new ApiToken();
        $apiToken->user_id = $user->id;
        $apiToken->name = $validated['name'];
        $apiToken->description = $validated['description'] ?? null;
        $apiToken->token = TokenTools::hashToken($tokenData->token); // Hash before storing
        $apiToken->expires_at = $tokenData->expire;
        $apiToken->save();

        // Attach permissions to token
        $apiToken->permissions()->attach($validated['permission_ids']);

        Log::info('API token created', [
            'token_id' => $apiToken->id,
            'user_id' => $user->id,
            'name' => $apiToken->name,
            'permissions_count' => count($validated['permission_ids']),
        ]);

        return response()->json([
            'message' => 'Jeton API créé avec succès. Sauvegardez ce jeton en sécurité - il ne sera plus affiché.',
            'token' => $tokenData->token, // Send plain token to client ONCE
            'token_info' => [
                'id' => $apiToken->id,
                'name' => $apiToken->name,
                'description' => $apiToken->description,
                'expires_at' => $apiToken->expires_at,
                'permissions' => $requestedPermissions->map(fn($p) => [
                    'id' => $p->id,
                    'nom' => $p->nom,
                    'api_key' => $p->api_key,
                ]),
            ],
        ], 201);
    }

    /**
     * Revoke (delete) an API token.
     */
    public function destroy(Request $request, ApiToken $token): JsonResponse
    {
        $user = Auth::user();

        // Verify the token belongs to the authenticated user
        if ($token->user_id !== $user->id) {
            return response()->json(['error' => 'Jeton introuvable'], 404);
        }

        $tokenName = $token->name;
        $tokenId = $token->id;
        $token->delete();

        Log::info('API token revoked', [
            'token_id' => $tokenId,
            'user_id' => $user->id,
            'name' => $tokenName,
        ]);

        return response()->json(['message' => 'Jeton révoqué avec succès']);
    }
}
