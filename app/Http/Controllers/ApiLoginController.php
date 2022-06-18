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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ApiLoginController extends Controller
{
    /**
     * Handle a login request to the application.
     *
     * @param Request $request
     * @return RedirectResponse|Response|JsonResponse
     *
     * @throws ValidationException
     */
    public function login(Request $request)
    {
        Log::debug("login");
        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        if ($this->attemptLogin($request)) {
            return $this->sendLoginResponse($request, Auth::user());
        }

        return response()->json(['error' => 'invalid credentials'], 401);
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
            $this->username() => 'required|string',
            'password' => 'required|string',
        ]);
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
     * Send the response after the user was authenticated.
     *
     * @param Request $request
     * @return Response
     */
    protected function sendLoginResponse(Request $request, $user)
    {
        $permissions = User::getPermissions($user->id);
        $mobiles = User::getMobile($user->id);
        $accessToken = TokenTools::createAccessToken($user, $permissions, $mobiles);

        // Get active refreshToken
        // TODO: We should create a single use refreshToken
        //       Usable only once, to prevent any one to steal an old token and authenticate using it.
        //       When used, should be deactivated and a new one should be generated
        $refreshToken = $user->getActiveRefreshToken();
        if ($refreshToken === null) {
            $token = TokenTools::createRefreshToken();
            // Store refresh token in database
            $refreshToken = new RefreshToken();
            $refreshToken->token = $token->token;
            $refreshToken->expire = $token->expire;
            $user->refreshTokens()->save($refreshToken);
        } else {
            // Expand refreshToken duration
            $token = TokenTools::createRefreshToken();
            $refreshToken->expire = $token->expire;
            $refreshToken->save();
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
