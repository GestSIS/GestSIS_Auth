<?php

namespace App\Http\Controllers;

use App\Role;
use App\Auth\TokenTools;
use App\RegisterToken;
use Illuminate\Http\Request;
use Exception;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class RegisterTokenController extends Controller
{
    public function newToken(Request $request) {
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
        $rolesId = $request->get('roles');
        $roles = Role::whereIn('id', $rolesId)->with('sis')->get();
        
        // Controle que les rôles à ajouter peuvent l'être par l'utilisateur
        foreach ($roles as $element) {
            if(!array_key_exists($element->sis->api_key, $permissions) || !in_array('utilisateur.tout' ,$permissions[$element->sis->api_key])) {
                return response()->json(["error" => "Permissions insuffisantes for ".$element->sis->api_key], 401);
            }
        }

        $registerToken = new RegisterToken();
        $registerToken->token = Base64UrlSafe::encode(random_bytes(20));
        $registerToken->validite = Carbon::now()->add(1, 'month');
        $registerToken->save();
        $registerToken->roles()->attach($rolesId);
        $registerToken->save();

        return response()->json(["data" => $registerToken->token], 200);
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
            'roles.*' => ['required', 'integer', 'min:1', 'distinct'],
            'description' => ['string', 'nullable']
        ]);
    }
}
