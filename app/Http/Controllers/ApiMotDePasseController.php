<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Mail\ResetPassword;
use App\Models\ApiToken;
use App\Models\PasswordResetToken;
use App\Models\User;
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
     */
    public function request(Request $request): JsonResponse
    {
        // Décider de quoi logger
        Log::debug("Call request password reset");

        $validation = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validation->fails()) {
            return response()->json(['error' => ['message' => 'email manquant']], 401);
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

        // Sauvegarder le jeton dans la DB (hashed)
        $token = new PasswordResetToken();
        $token->fill([
            'token' => TokenTools::hashToken($resetToken->token), // Hash before storing
            'user_id' => $user->id,
            'validite' => $resetToken->expire
        ]);
        $token->save();

        // Retourner le message standard
        return response()->json(['message' => self::RESET_MDP_CONFIRMATION_RESPONSE]);
    }

    /**
     * Handle a registration request for the application.
     */
    public function reset(Request $request): JsonResponse
    {
        Log::debug("Utilisation d'un reset password token");

        $validation = Validator::make($request->all(), [
            'token' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:12',
            ],
        ]);

        if ($validation->fails()) {
            return response()->json(['error' => ['message' => 'Jeton de réinitialisation manquant ou mot de passe invalide']], 401);
        }

        $validated = $validation->validated();
        $jeton = $validated['token'];
        $password = $validated['password'];

        // Hash the provided token before database lookup
        $hashedToken = TokenTools::hashToken($jeton);
        
        // Chargement du jeton depuis la DB
        $passwordResetToken = PasswordResetToken::where('token', '=', $hashedToken)
            ->where('validite', '>=', Carbon::now())
            ->first();

        if (is_null($passwordResetToken)) {
            Log::warning('Invalid or expired password reset token attempt', [
                'ip' => request()->ip(),
            ]);
            
            return response()->json(['error' => ['message' => 'Jeton invalide ou déjà utilisé']], 401);
        }

        // Suppression du jeton dans la DB
        $userId = $passwordResetToken->user_id;
        $passwordResetToken->delete();

        // Stockage du nouveau mot de passe
        $user = User::find($userId);
        $user->password = Hash::make($password);
        $user->save();

        // Revoke all refresh tokens (invalidate all sessions)
        $user->refreshTokens()->delete();

        // "Mot de passe oublié" est le chemin de récupération après une compromission
        // possible et ne prouve que le contrôle de la boîte mail : les jetons API
        // (potentiellement créés par un attaquant) sont révoqués. Ils restent listés
        // avec la raison pour que l'utilisateur sache quelles intégrations recréer.
        // Un changement de mot de passe avec l'ancien mot de passe (changer()) ne les
        // touche pas : l'utilisateur prouve alors qu'il contrôle le compte.
        $revokedApiTokens = ApiToken::revokeAllForUser($user->id, ApiToken::REASON_PASSWORD_RESET);

        Log::info('Password reset successful', [
            'user_id' => $user->id,
            'ip' => request()->ip(),
            'revoked_api_tokens' => count($revokedApiTokens),
        ]);

        $message = 'Mot de passe réinitialisé avec succès';
        if (count($revokedApiTokens) > 0) {
            $message .= '. Par sécurité, vos jetons d\'API ont été révoqués suite à cette réinitialisation : '
                . implode(', ', $revokedApiTokens)
                . '. Les applications qui les utilisaient ont perdu leur accès et devront être reconfigurées avec un nouveau jeton.';
        }

        return response()->json([
            'message' => $message,
            'revoked_api_tokens' => $revokedApiTokens,
        ]);
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
            'new_password' => [
                'required',
                'string',
                'min:12',
            ],
        ]);

        $endString = "@gestsis.ch";
        if (substr(strtolower($data[$this->username()]), -strlen($endString)) === $endString) {
            return response()->json(['error' => 'Modification de mot de passe refusée'], 401);
        }

        if ($this->attemptLogin($request)) {
            $user = Auth::user();
            User::find($user->id)->update(['password' => Hash::make($data['new_password'])]);
            
            // Revoke all refresh tokens (invalidate all sessions)
            $user->refreshTokens()->delete();
            
            Log::info('Password changed successfully', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);
            
            return response()->json(['message' => 'Modification effectuée avec succès']);
        }

        Log::warning('Failed password change attempt', [
            'email' => $data['email'] ?? 'unknown',
            'ip' => $request->ip(),
        ]);

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
