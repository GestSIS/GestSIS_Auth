<?php

namespace App\Http\Controllers;

use App\Auth\LoginResponder;
use App\Auth\TokenTools;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApiLoginController extends Controller
{
    /**
     * Handle a login request to the application.JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        Log::debug("login");
        $this->validator($request->all())->validate();

        if ($this->attemptLogin($request)) {
            $user = Auth::user();
            if ($user->disabled_at === null) {
                // Même règle que l'inscription : aucune session tant que
                // l'email n'est pas prouvé (le client renvoie vers la saisie
                // du code, avec possibilité d'en redemander un).
                if ($user->email_verified_at === null) {
                    return response()->json([
                        'message' => "Votre adresse email n'est pas encore confirmée.",
                        'requiresEmailConfirmation' => true,
                    ], 403);
                }

                return LoginResponder::respondAfterAuthentication($user, $request);
            }

            Log::warning('Login attempt on disabled account', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);
        } else {
            // Log failed login attempt
            Log::warning('Failed login attempt', [
                'email' => $request->input('email'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        // Même message pour identifiants invalides et compte désactivé :
        // ne pas confirmer à un tiers qu'une paire email/mot de passe est valide.
        return response()->json(['message' => 'Les identifiants fournis sont incorrects'], 401);
    }

    /**
     * Handle a token request to the application.
     */
    public function token(Request $request): JsonResponse
    {
        Log::debug("ADMIN Request for a user token");
        $userId = $request->input('user_id');
        if (!$userId) {
            return response()->json(['message' => "Missing `user_id` parameter"], 400);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => "Utilisateur inexistant"], 404);
        }

        $permissions = User::getPermissions($user->id);
        $mobiles = User::getMobile($user->id);
        $sapeurs = User::getSapeurs($user->id);
        $accessToken = TokenTools::createAccessToken($user, $permissions, $mobiles, $sapeurs, type: TokenTools::TOKEN_TYPE_IMPERSONATION);

        return response()->json([
            "accessToken" => $accessToken,
        ]);
    }

    /**
     * Get a validator for an incoming registration request.
     */
    protected function validator(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            $this->username() => 'required|string',
            'password' => 'required|string',
        ]);
    }

    /**
     * Attempt to log the user into the application.
     */
    protected function attemptLogin(Request $request): bool
    {
        return $this->guard()->attempt(
            $this->credentials($request)
        );
    }

    /**
     * Get the needed authorization credentials from the request.
     */
    protected function credentials(Request $request): array
    {
        return $request->only($this->username(), 'password');
    }

    /**
     * Get the login username to be used by the controller.
     */
    public function username(): string
    {
        return 'email';
    }

    /**
     * Get the guard to be used during authentication.
     */
    protected function guard(): StatefulGuard
    {
        return Auth::guard();
    }
}
