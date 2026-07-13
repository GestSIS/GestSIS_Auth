<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Mail\ConfirmationEmail;
use App\Models\RefreshToken;
use App\Models\RegisterToken;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class ApiRegisterController extends Controller
{

    /**
     * Handle a registration request for the application.
     */
    public function register(Request $request): JsonResponse
    {
        // TODO: Décider de quoi logger
        Log::debug("Call register");

        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        // Check présence de token
        $registerToken = $request->input('token');
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
            $email = $request->input('email');

            $response = Http::withHeaders([
                'Sis-Key' => '_',
                'Authorization' => 'Bearer ' . TokenTools::createAccessToken(new User(), ['_' => ['admin']], [], [])
            ])->acceptJson()->timeout(3)->get(config('gestsis.api_url', '') . '/api/v2/email-validate', ['email' => $email]); //->throw()->json();

            if (!$response->successful() || !$response['data']) {
                return response()->json(["error" => "Email invalide"], 401);
            }
        }

        // The `unique:users` validation above and this insert are not atomic, so two
        // concurrent registrations with the same email can both pass validation and
        // reach here. The database unique index is the source of truth; catch its
        // violation and return the same shape the validator produces for a duplicate.
        try {
            $userData = $this->create($request->all());
        } catch (UniqueConstraintViolationException $e) {
            return response()->json(['error' => ['email' => [__('validation.unique', ['attribute' => 'email'])]]], 401);
        }
        $user = $userData['user'];
        $plainEmailToken = $userData['plain_token'];

        $token = TokenTools::createRefreshToken();
        $refreshToken = new RefreshToken();
        $refreshToken->token = TokenTools::hashToken($token->token); // Hash before storing
        $refreshToken->expire = $token->expire;

        // Envoie du lien de confirmation par email
        try {
            Mail::to($user)->send(new ConfirmationEmail($user, $plainEmailToken));
        } catch (Exception $e) {
            $user->delete();
            return response()->json(["error" => "Une erreur à eu lieu lors de l'envoie de l'email de confirmation"], 401);
        }

        $user->refreshTokens()->save($refreshToken);

        // Ajoute des rôles
        $user->roles()->attach($rolesId);
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
            "message" => "Successful login",
            "accessToken" => $accessToken,
            "refreshToken" => $token->token, // Send plain token to client
            "user" => $user
        ]);
    }

    /**
     * Get a validator for an incoming registration request.
     */
    protected function validator(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => [
                'required',
                'string',
                'min:12',
                'confirmed',
            ],
            'token' => ['string', 'min:8', 'nullable'],
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param array $data
     * @return array ['user' => User, 'plain_token' => string]
     */
    protected function create(array $data)
    {
        $token = TokenTools::createConfirmationToken();
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'validate_email_token' => TokenTools::hashToken($token->token), // Hash before storing
            'password' => Hash::make($data['password']),
        ]);

        return [
            'user' => $user,
            'plain_token' => $token->token // Return plain token for email
        ];
    }
}
