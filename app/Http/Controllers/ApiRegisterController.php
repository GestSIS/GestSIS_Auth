<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\RefreshToken;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ApiRegisterController extends Controller
{

    /**
     * Handle a registration request for the application.
     *
     * @param Request $request
     * @return Response
     */
    public function register(Request $request)
    {
        // TODO: Décider de quoi logger
        Log::debug("Call register");

        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        // Controle que l'email est existant au sein d'un SIS
        $email = $request->get('email');
        $token = TokenTools::createAccessToken(new User(), ['_' => ['admin']]);
        
        // TODO: Changer endpoint en valeur non hardcodé
        $response = Http::withHeaders([
            'Sis-Id' => '_',
            'Authorization' => 'Bearer ' . $token
        ])->acceptJson()->timeout(2)->get('http://api:8000/api/v2/email-validate', ['email' => $email]);
        
        // TODO: Check situation de token
        if (!$response->successful() || !$response['data']) {
            return response()->json(["error" => "Email invalide"], 401);
        }

        $user = $this->create($request->all());
        $permissions = DB::table('permissions')
            ->join('permission_roles', 'permissions.id', '=', 'permission_roles.permission_id')
            ->join('roles', 'roles.id', '=', 'permission_roles.role_id')
            ->join('user_roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('sis', 'sis.id', '=', 'roles.sis_id')
            ->where('user_roles.user_id', '=', $user->id)
            ->select('permissions.api_key as perm_key', 'sis.api_key as sis_key')
            ->get();

        $groupedPermissions = array();
        foreach ($permissions as $element) {
            $groupedPermissions[$element->sis_key][] = $element->perm_key;
        }
        
        $accessToken = TokenTools::createAccessToken($user, $groupedPermissions);

        $token = TokenTools::createRefreshToken();
        $refreshToken = new RefreshToken();
        $refreshToken->token = $token->token;
        $refreshToken->expire = $token->expire;
        $user->refreshTokens()->save($refreshToken);

        // TODO: Ajouter un rôle par défault
        
        return response()->json(
            array(
                "message" => "Successful login",
                "accessToken" => $accessToken,
                "refreshToken" => $refreshToken->token,
                "user" => Auth::user()
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param array $data
     * @return User
     */
    protected function create(array $data)
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }
}
