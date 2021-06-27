<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\RefreshToken;
use App\RegisterToken;
use App\RegisterTokenRole;
use App\User;
use Carbon\Carbon;
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

        // TODO: Check situation de token
        $registerToken = $request->get('token');
        $rolesId = [];
        if ($registerToken != null && $registerToken != '') {
            $registerToken = RegisterToken::where('token', '=', $registerToken)
                ->where('validite', '>=', Carbon::now())->first();

            // Validate register token validité
            if (is_null($registerToken)) {
                return response()->json(["error" => "Token invalide"], 401);
            }
            $roles = array_values((array)DB::table('register_token_roles')
            ->where('register_token_id', '=', $registerToken->id)
            ->get('role_id'));
            $rolesId = array_map(function($r) { return $r->role_id; }, $roles[0]);

        } else {
            // Controle que l'email est existant au sein d'un SIS
            $email = $request->get('email');
            
            // TODO: Changer endpoint en valeur non hardcodé
            $response = Http::withHeaders([
                'Sis-Id' => '_',
                'Authorization' => 'Bearer ' . TokenTools::createAccessToken(new User(), ['_' => ['admin']])
            ])->acceptJson()->timeout(2)->get('http://api:8000/api/v2/email-validate', ['email' => $email]);
            
            if (!$response->successful() || !$response['data']) {
                return response()->json(["error" => "Email invalide"], 401);
            }
        }
        
        $user = $this->create($request->all());
        $permissions = DB::table('permissions')
            ->join('permission_roles', 'permissions.id', '=', 'permission_roles.permission_id')
            ->join('roles', 'roles.id', '=', 'permission_roles.role_id')
            ->join('user_roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('sis', 'sis.id', '=', 'roles.sis_id')
            ->where('user_roles.user_id', '=', $user->id)
            ->select('permissions.api_key as perm_key', 'sis.api_key as sis_key')
            ->distinct()
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

        // Ajouter des rôles
        $user->roles()->attach($rolesId);
        $user->save();

        // Suppression du token
        if (!is_null($registerToken)) {
            $registerToken->delete();
        }
        
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
            'token' => ['string', 'min:8', 'nullable'],
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
