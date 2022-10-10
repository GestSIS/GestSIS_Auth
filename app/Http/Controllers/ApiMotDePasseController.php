<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Mail\ConfirmationEmail;
use App\Mail\ResetPassword;
use App\RefreshToken;
use App\ResetPasswordToken;
use App\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ApiMotDePasseController extends Controller
{

    public const RESET_MDP_CONFIRMATION_RESPONSE = 'Un email a été envoyé si cette adresse email existe.';
    public const RESET_TOKEN_VALIDITE_HEURE = 1;

    /**
     * Handle a registration request for the application.
     *
     * @param Request $request
     * @return Response
     */
    public function request(Request $request)
    {
        // Décider de quoi logger
        Log::debug("Call request password reset");

        $validation = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validation->fails()) {
            return response()->json(['message' => self::RESET_MDP_CONFIRMATION_RESPONSE]);
        }

        // Chargement de l'utilisateur
        $email = $validation->validated()['email'];
        $user = User::where('email', '=', $email)->first();

        if (is_null($user)) {
            return response()->json(['message' => self::RESET_MDP_CONFIRMATION_RESPONSE]);
        }

        // Creation du reset token
        $resetToken = TokenTools::createResetToken();

        // Envoyer le mail contenant le lien de réinitialisation du mot de passe
        try {
            Mail::to($user)->send(new ResetPassword($resetToken->token));
        } catch (Exception $e) {
            Log::debug("Une erreur à eu lieu lors de l'envoie de l'email de reset");
            Log::error($e);
            return response()->json(['message' => self::RESET_MDP_CONFIRMATION_RESPONSE]);
        }

        // Sauvegarder le jeton dans la DB
        $token = new ResetPasswordToken();
        $token->fill(['token' => $resetToken->token, 'user_id' => $user->id, 'validite' => $resetToken->expire]);
        $token->save();

        // Retourner le message standard
        return response()->json(['message' => self::RESET_MDP_CONFIRMATION_RESPONSE]);
    }

    /**
     * Handle a registration request for the application.
     *
     * @param Request $request
     * @return Response
     */
    public function reset(Request $request)
    {
        Log::debug("Utilisation d'un reset password token");

        $validation = Validator::make($request->all(), [
            'token' => ['required', 'string'],
            'password' => ['required', 'min:8']
        ]);

        if ($validation->fails()) {
            return response()->json(['message' => 'Jeton de réinitialisation manquant ou mot de passe invalide']);
        }

        $validated = $validation->validated();
        $jeton = $validated['token'];
        $password = $validated['password'];

        // Chargement du jeton depuis la DB
        $resetPasswordToken = ResetPasswordToken::where('jeton', '=', $jeton)->first();

        // Controller la validité
        if (is_null($resetPasswordToken)) {
            return response()->json(['error' => ['message' => 'Jeton invalide']], 401);
        }
        if (Carbon::parse($resetPasswordToken->validite)->lt(Carbon::now())) {
            return response()->json(['error' => ['message' => 'Jeton expiré']], 401);
        }

        // Suppression du jeton dans la DB
        $userId = $resetPasswordToken->user_id;
        $resetPasswordToken->delete();

        // Stockage du nouveau mot de passe
        $user = User::find($userId);
        $user->password = Hash::make($password);
        $user->save();

        // return success
        return response()->json(['message' => 'Mot de passe réinitialisé avec succès']);
    }

    /**
     * Handle a login request to the application.
     *
     * @param Request $request
     * @return RedirectResponse|Response|JsonResponse
     *
     * @throws ValidationException
     */
    public function changer(Request $request)
    {
        Log::debug("Changer mdp");
        $data = $request->validate([
            $this->username() => 'required|string',
            'password' => 'required|string',
            'new_password' => 'required|string|min:8',
        ]);

        $endString = "@gestsis.ch";
        if (substr(strtolower($data[$this->username()]), -strlen($endString)) === $endString) {
            return response()->json(['error' => 'Modification de mot de passe refusée'], 401);
        }

        if ($this->attemptLogin($request)) {
            $user = Auth::user();
            User::find($user->id)->update(['password' => Hash::make($data['new_password'])]);
            return response()->json(['message' => 'Modification effectuée avec succès']);
        }

        return response()->json(['error' => 'Identifiants invalides'], 401);
    }

    /**
     * Attempt to log the user into the application.
     *
     * @param Request $request
     * @return bool
     */
    protected function attemptLogin(Request $request)
    {
        return $this->guard()->attempt(
            $this->credentials($request)
        );
    }

    /**
     * Get the needed authorization credentials from the request.
     *
     * @param Request $request
     * @return array
     */
    protected function credentials(Request $request)
    {
        return $request->only($this->username(), 'password');
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    public function username()
    {
        return 'email';
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard();
    }
}
