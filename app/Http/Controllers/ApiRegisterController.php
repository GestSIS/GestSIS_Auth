<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Mail\ConfirmationEmail;
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

        $this->validator($request->all())->validate();

        // Check présence de token
        $registerToken = $request->input('token');
        $rolesId = [];
        if ($registerToken != null && $registerToken != '') {
            $registerToken = RegisterToken::where('token', '=', TokenTools::hashToken($registerToken))
                ->where('validite', '>=', Carbon::now())->first();

            // Validate register token validité
            if (is_null($registerToken)) {
                return response()->json(['message' => "Token invalide"], 401);
            }
            $rolesId = DB::table('register_token_roles')
                ->where('register_token_id', '=', $registerToken->id)
                ->pluck('role_id')->toArray();
        } else {
            // Controle que l'email est existant au sein d'un SIS
            $email = $request->input('email');

            $response = Http::withHeaders([
                'Sis-Key' => '_',
                'Authorization' => 'Bearer ' . TokenTools::createAccessToken(new User(), ['_' => ['admin']], [], [], type: TokenTools::TOKEN_TYPE_SERVICE)
            ])->acceptJson()->timeout(3)->get(config('gestsis.api_url', '') . '/api/v2/email-validate', ['email' => $email]); //->throw()->json();

            if (!$response->successful() || !$response['data']) {
                // Same response as the duplicate-email case below: a caller must not
                // be able to tell apart "unknown email" from "already registered"
                return response()->json(['message' => 'Email invalide ou déjà utilisé', 'errors' => ['email' => ['Email invalide ou déjà utilisé']]], 422);
            }
        }

        // Duplicate emails are caught here via the database unique index rather than
        // a `unique:users` validation rule: the rule's distinctive error message
        // would let an unauthenticated caller enumerate registered addresses, and
        // the check would not be atomic with the insert anyway.
        try {
            $userData = $this->create($request->all());
        } catch (UniqueConstraintViolationException $e) {
            return response()->json(['message' => 'Email invalide ou déjà utilisé', 'errors' => ['email' => ['Email invalide ou déjà utilisé']]], 422);
        }
        $user = $userData['user'];
        $plainEmailCode = $userData['plain_code'];

        // Envoie du code de confirmation par email
        try {
            Mail::to($user)->send(new ConfirmationEmail($user, $plainEmailCode));
        } catch (Exception $e) {
            $user->delete();
            return response()->json(['message' => "Une erreur à eu lieu lors de l'envoie de l'email de confirmation"], 500);
        }

        // Ajoute des rôles
        $user->roles()->attach($rolesId);
        $user->save();

        // Suppression du token
        if (!is_null($registerToken)) {
            $registerToken->delete();
        }

        // Aucune session n'est émise ici : le compte n'a pas encore prouvé la
        // possession de son email. La session (ou l'étape 2FA si l'enforcement
        // est actif) n'est émise qu'une fois le code confirmé, voir
        // ApiConfirmerEmailController::confirmerEmail().
        return response()->json([
            'data' => [
                'requiresEmailConfirmation' => true,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Get a validator for an incoming registration request.
     */
    protected function validator(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
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
     * @return array ['user' => User, 'plain_code' => string]
     */
    protected function create(array $data)
    {
        $code = TokenTools::createEmailConfirmationCode();
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            // bcrypt (pas TokenTools::hashToken) : un code court a une
            // entropie trop faible pour un hash rapide non salé, voir
            // ApiConfirmerEmailController::confirmerEmail().
            'validate_email_token' => Hash::make($code->token),
            'validate_email_expire' => $code->expire,
            'password' => Hash::make($data['password']),
        ]);

        return [
            'user' => $user,
            'plain_code' => $code->token // Return plain code for email
        ];
    }
}
