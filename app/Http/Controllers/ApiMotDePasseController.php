<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\RefreshToken;
use App\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ApiMotDePasseController extends Controller
{
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
     * 
     * if ($data[])
     * Send the response after the user was authenticated.
     *
     * @param Request $request
     * @return Response
     */
    protected function sendLoginResponse(Request $request, $user)
    {
        $permissions = User::getPermissions($user->id);
        $mobiles = User::getMobile($user->id);
        $sapeurs = User::getSapeurs($user->id);
        $accessToken = TokenTools::createAccessToken($user, $permissions, $mobiles, $sapeurs);

        // Get active refreshToken
        // TODO:
        $refreshToken = $user->getActiveRefreshToken();
        if ($refreshToken === null) {
            $token = TokenTools::createRefreshToken();
            // Store refresh token in database
            $refreshToken = new RefreshToken();
            $refreshToken->token = $token->token;
            $refreshToken->expire = $token->expire;
            $user->refreshTokens()->save($refreshToken);
        }

        return response()->json(
            array(
                "message" => "Successful login",
                "accessToken" => $accessToken,
                "refreshToken" => $refreshToken->token,
                "user" => User::first('id', $user->id)
            )
        );
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
