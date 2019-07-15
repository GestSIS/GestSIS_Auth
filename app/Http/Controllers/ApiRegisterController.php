<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\RefreshToken;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApiRegisterController extends Controller
{

    /**
     * Handle a registration request for the application.
     *
     * @param Request $request
     * @return Response
     */
    public function register(Request $request)
    {
        Log::debug("Call register");

        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()]);
        }

        $user = $this->create($request->all());
        $accessToken = TokenTools::createAccessToken($user);

        $token = TokenTools::createRefreshToken();
        $refreshToken = new RefreshToken();
        $refreshToken->token = $token->token;
        $refreshToken->expire = $token->expire;
        $user->refreshTokens()->save($refreshToken);

        return response()->json(
            array(
                "message" => "Successful login",
                "accessToken" => $accessToken,
                "refreshToken" => $refreshToken->token
            )
        );
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param array $data
     * @return User
     */
    protected function create(array $data)
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }
}
