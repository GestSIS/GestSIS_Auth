<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Models\Role;
use App\Models\RegisterToken;
use App\Models\Sis;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Exception;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RegisterTokenController extends Controller
{
    public function newToken(Request $request): JsonResponse
    {
        // Validate inputs
        $validation = $this->validator($request->all());
        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        // Décode du JWT token
        $authToken = $request->bearerToken();
        try {
            $jwt = TokenTools::validateToken($authToken);
        } catch (Exception $e) {
            return response()->json(["error" => "Invalid bearer token" . $e], 401);
        }

        $permissions = (array) $jwt->data->permissions;
        $rolesId = $request->input('roles');
        $roles = Role::whereIn('id', $rolesId)->with('sis')->get();

        // Controle que les rôles à ajouter peuvent l'être par l'utilisateur
        if ($jwt->data->admin !== true) {
            /** @var Role $role */
            foreach ($roles as $role) {
                /** @var Sis $sis */
                $sis = $role->sis;
                if (!array_key_exists($sis->api_key, $permissions) || !in_array('utilisateur.tout', $permissions[$sis->api_key])) {
                    return response()->json(["error" => "Permissions insuffisantes for " . $sis->api_key], 401);
                }
            }
        }

        $plainToken = Base64UrlSafe::encode(random_bytes(20));

        $registerToken = new RegisterToken();
        $registerToken->token = TokenTools::hashToken($plainToken); // Hash before storing
        $registerToken->validite = Carbon::now()->addMonths(1);
        $registerToken->save();
        $registerToken->roles()->attach($rolesId);
        $registerToken->save();

        return response()->json(["data" => $plainToken], 200);
    }

    public function consume(Request $request): JsonResponse
    {
        $token = $request->validate([
            'token' => 'string|required|min:1'
        ]);

        $registerToken = RegisterToken::where('token', '=', TokenTools::hashToken($token['token']))
            ->where('validite', '>=', Carbon::now())->first();

        // Validate register token validité
        if (is_null($registerToken)) {
            return response()->json(["error" => "Token invalide"], 401);
        }

        // Load user from database by using user_id from jwt token
        $authToken = $request->bearerToken();
        try {
            $jwt = TokenTools::validateToken($authToken);
        } catch (Exception $e) {
            return response()->json(["error" => "Invalid bearer token" . $e], 401);
        }
        $id = (array) $jwt->data->id;
        $user = User::where('id', $id)->first();
        if (is_null($user)) {
            return response()->json(["error" => "Le compte utilisateur actuel n'existe plus"], 401);
        }

        $roleIds = DB::table('register_token_roles')
            ->where('register_token_id', '=', $registerToken->id)
            ->pluck('role_id')->toArray();

        $attachedIds = UserRole::where('user_id', $user->id)->pluck('role_id')->toArray();

        // Remove the attached IDs from the request array
        $newRolesIds = array_diff($roleIds, $attachedIds);

        // Attach the new IDs
        $user->roles()->attach($newRolesIds);
        $user->save();

        // Load permissions
        $permissions = User::getPermissions($user->id);
        $mobiles = User::getMobile($user->id);
        $sapeurs = User::getSapeurs($user->id);
        $accessToken = TokenTools::createAccessToken($user, $permissions, $mobiles, $sapeurs);

        // Suppression du token
        if (!is_null($registerToken)) {
            $registerToken->delete();
        }

        return response()->json([
            "message" => "Permissions ajoutées",
            "accessToken" => $accessToken,
        ]);
    }

    /**
     * Get a validator for an incoming registration request.
     */
    protected function validator(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'integer', 'min:1', 'distinct'],
            'description' => ['string', 'nullable']
        ]);
    }
}
