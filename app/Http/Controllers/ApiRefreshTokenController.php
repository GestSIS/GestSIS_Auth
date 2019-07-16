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
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class ApiRefreshTokenController extends Controller
{

    /**
     * Handle a registration request for the application.
     *
     * @param Request $request
     * @return Response
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function refresh(Request $request)
    {
        Log::debug("Call refresh token");

        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        $refreshToken = RefreshToken::where('expire', '>', Carbon::now())->where('token', '=', $request->get('token'))->with('user')->first();
        if(!$refreshToken){
            return response()->json(['error' => 'Refresh token expired'], 401);
        }

        $accessToken = TokenTools::createAccessToken($refreshToken->user);

        return response()->json(
            array(
                "message" => "Successful login",
                "accessToken" => $accessToken,
                "refreshToken" => $refreshToken->token,
                "user" => $refreshToken->user
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
            'token' => ['required', 'string'],
        ]);
    }
}
