<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Models\ApiToken;
use App\Models\Permission;
use App\Models\Sis;
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
            ->with(['permissions:id,nom,api_key', 'allowedSis:id,nom,api_key'])
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
                    'allowed_sis' => $token->allowedSis->map(fn($s) => [
                        'id' => $s->id,
                        'nom' => $s->nom,
                        'api_key' => $s->api_key,
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
            'sis_ids' => ['nullable', 'array'],
            'sis_ids.*' => ['required', 'integer', 'exists:sis,id'],
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

        // Admins can create tokens for any SIS with any permissions
        // Non-admins must have the permissions and SIS access they're assigning
        if (!$user->admin) {
            // Non-admins must specify at least one SIS
            if (empty($validated['sis_ids'])) {
                return response()->json([
                    'error' => "Vous devez spécifier au moins un SIS pour ce jeton"
                ], 422);
            }

            // Get user's current permissions grouped by SIS
            $userPermissions = User::getPermissions($user->id);

            // Get requested permissions
            $requestedPermissions = Permission::whereIn('id', $validated['permission_ids'])->get();
            $requestedPermissionKeys = $requestedPermissions->pluck('api_key')->toArray();

            // Get requested SIS
            $requestedSis = Sis::whereIn('id', $validated['sis_ids'])->get();

            // Verify that user has ALL requested permissions in EACH requested SIS
            foreach ($requestedSis as $sis) {
                // Check if user has access to this SIS
                if (!isset($userPermissions[$sis->api_key])) {
                    return response()->json([
                        'error' => "Vous n'avez aucune permission pour le SIS : {$sis->nom}"
                    ], 403);
                }

                $userSisPermissions = $userPermissions[$sis->api_key];

                // Check if user has all requested permissions in this SIS
                $missingPermissions = array_diff($requestedPermissionKeys, $userSisPermissions);

                if (!empty($missingPermissions)) {
                    $missingPermissionNames = $requestedPermissions
                        ->whereIn('api_key', $missingPermissions)
                        ->pluck('nom')
                        ->implode(', ');

                    return response()->json([
                        'error' => "Vous ne disposez pas des permissions suivantes pour le SIS {$sis->nom} : {$missingPermissionNames}"
                    ], 403);
                }
            }
        }

        // Get requested permissions for response
        $requestedPermissions = Permission::whereIn('id', $validated['permission_ids'])->get();

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

        // Attach SIS restrictions if provided
        if (!empty($validated['sis_ids'])) {
            $apiToken->allowedSis()->attach($validated['sis_ids']);
        }

        Log::info('API token created', [
            'token_id' => $apiToken->id,
            'user_id' => $user->id,
            'created_by_admin' => $user->admin,
            'name' => $apiToken->name,
            'permissions_count' => count($validated['permission_ids']),
            'sis_restriction' => !empty($validated['sis_ids']),
            'sis_count' => count($validated['sis_ids'] ?? []),
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
                'allowed_sis' => !empty($validated['sis_ids'])
                    ? Sis::whereIn('id', $validated['sis_ids'])->get()->map(fn($s) => [
                        'id' => $s->id,
                        'nom' => $s->nom,
                        'api_key' => $s->api_key,
                    ])
                    : [],
            ],
        ], 201);
    }

    /**
     * Revoke (delete) an API token.
     */
    public function destroy(Request $request, ApiToken $apiToken): JsonResponse
    {
        $user = Auth::user();

        // Verify the token belongs to the authenticated user
        if ($apiToken->user_id !== $user->id) {
            return response()->json(['error' => 'Jeton introuvable'], 404);
        }

        $tokenName = $apiToken->name;
        $tokenId = $apiToken->id;
        $apiToken->delete();

        Log::info('API token revoked', [
            'token_id' => $tokenId,
            'user_id' => $user->id,
            'name' => $tokenName,
        ]);

        return response()->json(['message' => 'Jeton révoqué avec succès']);
    }
}
