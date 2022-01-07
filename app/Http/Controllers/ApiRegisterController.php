<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Mail\ConfirmationEmail;
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
use Illuminate\Support\Facades\Mail;

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

        // Check présence de token
        $registerToken = $request->get('token');
        $rolesId = [];
        if ($registerToken != null && $registerToken != '') {
            $registerToken = RegisterToken::where('token', '=', $registerToken)
                ->where('validite', '>=', Carbon::now())->first();

            // Validate register token validité
            if (is_null($registerToken)) {
                return response()->json(["error" => "Token invalide"], 401);
            }
            $rolesId = DB::table('register_token_roles')
                ->where('register_token_id', '=', $registerToken->id)
                ->pluck('role_id')->toArray();
        } else {
            // Controle que l'email est existant au sein d'un SIS
            $email = $request->get('email');

            $response = Http::withHeaders([
                'Sis-Id' => '_',
                'Authorization' => 'Bearer ' . TokenTools::createAccessToken(new User(), ['_' => ['admin']])
            ])->acceptJson()->timeout(3)->get(env('APP_GESTSIS_API_URL', '') . '/api/v2/email-validate', ['email' => $email]); //->throw()->json();

            if (!$response->successful() || !$response['data']) {
                return response()->json(["error" => "Email invalide"], 401);
            }
        }

        $user = $this->create($request->all());

        $token = TokenTools::createRefreshToken();
        $refreshToken = new RefreshToken();
        $refreshToken->token = $token->token;
        $refreshToken->expire = $token->expire;
        $user->refreshTokens()->save($refreshToken);

        // Envoie du lien de confirmation par email
        Mail::to($user)->send(new ConfirmationEmail($user));

        // Ajoute des rôles
        $user->roles()->attach($rolesId);
        $user->save();

        // Load permissions
        $permissions = User::getPermissions($user->id);
        $accessToken = TokenTools::createAccessToken($user, $permissions);

        // Suppression du token
        if (!is_null($registerToken)) {
            $registerToken->delete();
        }

        return response()->json(
            array(
                "message" => "Successful login",
                "accessToken" => $accessToken,
                "refreshToken" => $refreshToken->token,
                "user" => $user
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
        $token = TokenTools::createConfirmationToken();
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'validate_email_token' => $token->token,
            'password' => Hash::make($data['password']),
        ]);
    }
}
