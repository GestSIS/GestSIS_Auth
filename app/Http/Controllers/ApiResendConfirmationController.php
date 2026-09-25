<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Mail\ConfirmationEmail;
use App\Models\User;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ApiResendConfirmationController extends Controller
{
    public const RESEND_CONFIRMATION_RESPONSE = 'Un nouveau code a été envoyé si cette adresse email existe et n\'est pas encore confirmée.';

    /**
     * Renvoie un nouveau code de confirmation par email — identifié par
     * l'email lui-même, pas un jeton d'accès : depuis que l'inscription
     * n'émet plus de session tant que l'email n'est pas prouvé, un compte
     * fraîchement créé n'a justement aucun jeton à présenter ici. Réponse
     * identique que l'email existe, soit déjà confirmé ou non : ne pas
     * permettre l'énumération d'adresses.
     */
    public function resend(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'email' => ['required', 'string', 'email'],
        ])->validate();

        $user = User::where('email', $request->input('email'))->first();

        if ($user === null || $user->email_verified_at !== null) {
            return response()->json(['message' => self::RESEND_CONFIRMATION_RESPONSE]);
        }

        $code = TokenTools::createEmailConfirmationCode();
        $user->validate_email_token = Hash::make($code->token);
        $user->validate_email_expire = $code->expire;
        $user->save();
        ApiConfirmerEmailController::clearFailedAttempts($user);

        try {
            Mail::to($user)->send(new ConfirmationEmail($user, $code->token));
        } catch (Exception $e) {
            Log::error($e);
        }

        return response()->json(['message' => self::RESEND_CONFIRMATION_RESPONSE]);
    }
}
