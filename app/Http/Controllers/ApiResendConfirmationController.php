<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Mail\ConfirmationEmail;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Exception;
use Illuminate\Support\Facades\Mail;

class ApiResendConfirmationController extends Controller
{

    /**
     * Handle a resend confirmation email request for the application.
     *
     * @param Request $request
     * @return Response
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function resend(Request $request)
    {
        // Décode du JWT token
        $authToken = $request->bearerToken();
        try {
            $jwt = TokenTools::validateToken($authToken);
        } catch (Exception $e) {
            return response()->json(["error" => "Invalid bearer token" . $e], 401);
        }

        $id = $jwt->data->id;

        $user = User::find($id);
        if ($user === null) {
            return response()->json(["error" => "Utilisateur invalid !"], 401);
        }

        if ($user->email_verified_at !== null) {
            return response()->json(["error" => "Votre email est déjà vérifié !"], 401);
        }

        // Envoie du lien de confirmation par email
        try {
            Mail::to($user)->send(new ConfirmationEmail($user));
        } catch (Exception $e) {
            return response()->json(["error" => "Une erreur à eu lieu lors de l'envoie de l'email de confirmation"], 401);
        }
        return response()->json(["message" => "Email réenvoyé avec succès"]);
    }
}
