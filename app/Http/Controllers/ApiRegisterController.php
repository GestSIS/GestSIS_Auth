<?php

namespace App\Http\Controllers;

use App\Auth\PasswordPolicy;
use App\Auth\TokenTools;
use App\Mail\ConfirmationEmail;
use App\Models\Domaine;
use App\Models\User;
use App\Services\EmailIdentityMatcher;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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

        $domaine = Domaine::where('code', $request->input('domaine_code'))->first();

        // Un domaine qui désactive l'auto-inscription refuse toute inscription,
        // avant même de contacter GestSIS_API : même réponse générique que le
        // cas email invalide/déjà utilisé, pour ne pas révéler pourquoi (voir
        // system.md).
        if (! $domaine->self_registration_enabled) {
            return response()->json(['message' => 'Email invalide ou déjà utilisé', 'errors' => ['email' => ['Email invalide ou déjà utilisé']]], 422);
        }

        // Controle que l'email est existant au sein d'un SIS
        $result = (new EmailIdentityMatcher())->matchInDomaine($domaine, $request->input('email'));

        if ($result['failed'] || !$result['matched']) {
            // Same response as the duplicate-email case below: a caller must not
            // be able to tell apart "unknown email" from "already registered"
            return response()->json(['message' => 'Email invalide ou déjà utilisé', 'errors' => ['email' => ['Email invalide ou déjà utilisé']]], 422);
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
            Mail::to($user)->send(new ConfirmationEmail($user, $plainEmailCode, domaineCode: $domaine->code));
        } catch (Exception $e) {
            $user->delete();
            return response()->json(['message' => "Une erreur à eu lieu lors de l'envoie de l'email de confirmation"], 500);
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
            'password' => ['required', 'string', PasswordPolicy::rule(), 'confirmed'],
            'domaine_code' => ['required', 'string', 'exists:domaines,code'],
        ], PasswordPolicy::messages('password'));
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
